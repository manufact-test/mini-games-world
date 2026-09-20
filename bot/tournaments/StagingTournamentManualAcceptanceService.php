<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/UserService.php';

final class StagingTournamentManualAcceptanceService
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';

    private $runtimeUserWriter;

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        private TournamentRegistrationService $tournaments,
        ?callable $runtimeUserWriter = null
    ) {
        $this->runtimeUserWriter = $runtimeUserWriter;
    }

    public function availability(array $server): array
    {
        if (!$this->isAvailableEnvironment($server)) {
            return [
                'available'=>false,
                'reason'=>'staging_only',
                'target_registered_count'=>null,
            ];
        }

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            return [
                'available'=>false,
                'reason'=>'no_active_tournament',
                'target_registered_count'=>null,
            ];
        }

        $capacity = (int)($tournament['capacity'] ?? 0);
        $registered = (int)($tournament['registered_count'] ?? 0);
        $target = max(0, $capacity - 1);
        $state = (string)($tournament['state'] ?? '');

        return [
            'available'=>$state === TournamentRegistrationService::STATE_REGISTRATION_OPEN
                && $capacity >= 2
                && $registered < $target,
            'reason'=>$state !== TournamentRegistrationService::STATE_REGISTRATION_OPEN
                ? 'registration_not_open'
                : ($registered >= $target ? 'manual_last_seat_ready' : 'ready'),
            'target_registered_count'=>$target,
            'registered_count'=>$registered,
            'capacity'=>$capacity,
            'remaining_fixture_slots'=>max(0, $target - $registered),
        ];
    }

    public function fillToOneManualSeat(array $server): array
    {
        $this->assertAvailableEnvironment($server);

        $snapshot = $this->tournaments->snapshot();
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)) {
            throw new RuntimeException('Для ручной проверки сначала нужен активный официальный турнир.');
        }
        if ((string)($tournament['state'] ?? '') !== TournamentRegistrationService::STATE_REGISTRATION_OPEN) {
            throw new RuntimeException('Тестовое заполнение доступно только пока регистрация открыта.');
        }

        $capacity = (int)($tournament['capacity'] ?? 0);
        $registered = (int)($tournament['registered_count'] ?? 0);
        if ($capacity < 2) {
            throw new RuntimeException('Некорректная вместимость турнира для ручной проверки.');
        }
        $target = $capacity - 1;
        if ($registered >= $target) {
            return [
                'status'=>'already_ready',
                'created_count'=>0,
                'registered_count'=>$registered,
                'capacity'=>$capacity,
                'target_registered_count'=>$target,
                'manual_seats_left'=>max(0, $capacity - $registered),
                'snapshot'=>$snapshot,
            ];
        }

        $rules = $tournament['rules'] ?? null;
        if (!is_array($rules)) {
            throw new RuntimeException('Снимок правил турнира недоступен.');
        }
        $consent = [
            'accepted'=>true,
            'version'=>(string)($rules['version'] ?? ''),
            'language'=>(string)($rules['language'] ?? ''),
            'sha256'=>(string)($rules['sha256'] ?? ''),
        ];
        if ($consent['version'] === '' || $consent['language'] === '' || $consent['sha256'] === '') {
            throw new RuntimeException('Снимок правил турнира неполный.');
        }

        $tournamentId = (string)($tournament['tournament_id'] ?? '');
        if ($tournamentId === '') {
            throw new RuntimeException('Идентификатор турнира недоступен.');
        }

        $registeredIds = array_fill_keys(
            $this->tournaments->registeredParticipantMgwIds($tournamentId),
            true
        );
        $needed = $target - $registered;
        $created = [];

        for ($slot = 1; count($created) < $needed && $slot <= 256; $slot++) {
            $identity = $this->fixtureIdentity($tournamentId, $slot);
            if (isset($registeredIds[$identity['mgw_id']])) continue;

            $this->ensureCanonicalUser($identity);
            $this->ensureEntryBalance($identity, $tournamentId, $slot);
            $registration = $this->tournaments->register(
                $identity['mgw_id'],
                $identity['account_ref'],
                null,
                $consent
            );
            $this->ensureRuntimeUser($identity, $slot);

            $registeredIds[$identity['mgw_id']] = true;
            $created[] = [
                'mgw_id'=>$identity['mgw_id'],
                'legacy_user_id'=>$identity['legacy_user_id'],
                'registration_id'=>(string)($registration['registration']['registration_id'] ?? ''),
            ];
        }

        $final = $this->tournaments->snapshot();
        $finalTournament = $final['tournament'] ?? null;
        $finalRegistered = is_array($finalTournament)
            ? (int)($finalTournament['registered_count'] ?? -1)
            : -1;
        $finalState = is_array($finalTournament)
            ? (string)($finalTournament['state'] ?? '')
            : '';

        if ($finalRegistered !== $target
            || $finalState !== TournamentRegistrationService::STATE_REGISTRATION_OPEN) {
            throw new RuntimeException('Не удалось безопасно подготовить турнир с одним свободным местом.');
        }

        return [
            'status'=>'prepared',
            'created_count'=>count($created),
            'created_participants'=>$created,
            'registered_count'=>$finalRegistered,
            'capacity'=>$capacity,
            'target_registered_count'=>$target,
            'manual_seats_left'=>1,
            'snapshot'=>$final,
        ];
    }

    private function ensureCanonicalUser(array $identity): void
    {
        $rows = $this->database->fetchAll(
            'SELECT mgw_id,status FROM mgw_users WHERE mgw_id=:mgw_id LIMIT 1',
            ['mgw_id'=>$identity['mgw_id']]
        );
        if ($rows !== []) {
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Тестовый MGW-пользователь имеет неоднозначное состояние.');
            }
            if ((string)($rows[0]['status'] ?? '') !== 'active') {
                throw new RuntimeException('Тестовый MGW-пользователь неактивен.');
            }
            return;
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.u');
        $this->database->execute(
            'INSERT INTO mgw_users (
                mgw_id,status,display_name,username,
                avatar_provider,avatar_external_ref,avatar_storage_key,avatar_mime_type,
                avatar_width,avatar_height,
                created_at_utc,updated_at_utc,last_seen_at_utc
             ) VALUES (
                :mgw_id,:status,:display_name,NULL,
                NULL,NULL,NULL,NULL,
                NULL,NULL,
                :created_at_utc,:updated_at_utc,:last_seen_at_utc
             )',
            [
                'mgw_id'=>$identity['mgw_id'],
                'status'=>'active',
                'display_name'=>$identity['display_name'],
                'created_at_utc'=>$now,
                'updated_at_utc'=>$now,
                'last_seen_at_utc'=>$now,
            ]
        );
    }

    private function ensureEntryBalance(array $identity, string $tournamentId, int $slot): void
    {
        $balance = $this->ledger->getBalance(
            $identity['account_ref'],
            TournamentRegistrationService::ENTRY_ASSET
        );
        if ($balance === null) {
            $this->ledger->postAvailableDelta([
                'operation_key'=>'staging:tournament-manual:grant:'
                    . substr(hash('sha256', $tournamentId), 0, 16)
                    . ':' . $slot,
                'account_ref'=>$identity['account_ref'],
                'mgw_id'=>$identity['mgw_id'],
                'legacy_user_id'=>$identity['legacy_user_id'],
                'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
                'available_delta'=>TournamentRegistrationService::ENTRY_FEE,
                'category'=>'test_grant',
                'source_type'=>'test',
                'source_ref'=>$tournamentId,
                'metadata'=>[
                    'purpose'=>'staging_tournament_manual_acceptance_fixture',
                    'fixture_slot'=>$slot,
                ],
            ]);
            return;
        }

        if ((int)($balance['available_amount'] ?? -1) < TournamentRegistrationService::ENTRY_FEE) {
            throw new RuntimeException('Баланс тестового участника уже существует в несовместимом состоянии.');
        }
        if ((int)($balance['reserved_amount'] ?? -1) !== 0) {
            throw new RuntimeException('У тестового участника уже есть чужой активный резерв.');
        }
    }

    private function ensureRuntimeUser(array $identity, int $slot): void
    {
        if ($this->runtimeUserWriter !== null) {
            ($this->runtimeUserWriter)($identity, $slot);
            return;
        }

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
        $config = $this->config;
        $database = $this->database;
        $storage->transaction(static function (array &$data) use (
            $identity,
            $slot,
            $config,
            $database
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) {
                $data['users'] = [];
            }
            $users = new UserService($config, $database);
            $users->ensureUser($data, [
                'id'=>$identity['legacy_user_id'],
                'first_name'=>$identity['display_name'],
                'username'=>'',
                'language_code'=>'ru',
                'is_dev_user'=>true,
                'is_staging_test_user'=>true,
                'staging_test_slot'=>'TOURNAMENT-' . $slot,
                'mgw_id'=>$identity['mgw_id'],
                'mgw_account_ref'=>$identity['account_ref'],
                'mgw_identity_provider'=>'staging_fixture',
                'mgw_nickname'=>'Тест ' . $slot,
            ]);
            return $data;
        });
    }

    private function fixtureIdentity(string $tournamentId, int $slot): array
    {
        $token = substr(hash('sha256', $tournamentId . '|manual-acceptance|' . $slot), 0, 12);
        $legacyUserId = 'stg_tour_' . $token;
        return [
            'mgw_id'=>'MGW-STG-' . $token,
            'legacy_user_id'=>$legacyUserId,
            'account_ref'=>'legacy:' . $legacyUserId,
            'display_name'=>'Тестовый участник ' . $slot,
        ];
    }

    private function assertAvailableEnvironment(array $server): void
    {
        if (!$this->isAvailableEnvironment($server)) {
            throw new RuntimeException('Заполнение тестовыми участниками доступно только в staging.');
        }
    }

    private function isAvailableEnvironment(array $server): bool
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            return false;
        }

        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $baseScheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $baseHost = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        $requestHost = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
        if (str_contains($requestHost, ':')) {
            $requestHost = explode(':', $requestHost, 2)[0];
        }
        if ($baseScheme !== 'https'
            || $baseHost !== self::STAGING_HOST
            || $requestHost !== self::STAGING_HOST) {
            return false;
        }

        if (!empty($this->config['external_payments_enabled'])) return false;
        foreach (['payment_mode', 'telegram_stars_mode', 'google_play_billing_mode'] as $key) {
            if (strtolower(trim((string)($this->config[$key] ?? ''))) === 'live') {
                return false;
            }
        }
        return true;
    }
}
