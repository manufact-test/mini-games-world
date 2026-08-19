<?php
declare(strict_types=1);

require_once __DIR__ . '/MgwIdentityPolicy.php';
require_once dirname(__DIR__) . '/catalog/ProductInventoryService.php';

final class MgwProfileService
{
    private const CLAIM_ATTEMPTS = 10;

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function publicProfile(string $mgwId): array
    {
        $mgwId = trim($mgwId);
        if (!MgwIdGenerator::isValid($mgwId)) throw new RuntimeException('Authenticated MGW profile id is invalid.');
        $this->ensureCanonicalIdentity($mgwId);
        $users = $this->database->fetchAll(
            'SELECT mgw_id, status, nickname, equipped_avatar_item_id, preferred_locale,
                    created_at_utc, updated_at_utc, last_seen_at_utc
             FROM mgw_users WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        );
        if (count($users) !== 1) throw new RuntimeException('Authenticated MGW profile is unavailable.');
        $identities = $this->database->fetchAll(
            'SELECT provider, linked_at_utc FROM mgw_identities
             WHERE mgw_id = :mgw_id ORDER BY linked_at_utc ASC, provider ASC',
            ['mgw_id' => $mgwId]
        );
        $publicIdentities = [];
        foreach ($identities as $identity) {
            if (!is_array($identity)) continue;
            $provider = strtolower(trim((string)($identity['provider'] ?? '')));
            if (!MgwIdentityPolicy::isPublicIdentityProvider($provider)) continue;
            $publicIdentities[] = [
                'provider' => $provider,
                'linked_at' => $this->nullableString($identity['linked_at_utc'] ?? null),
            ];
        }
        $user = $users[0];
        $nickname = (string)$user['nickname'];
        $internalMgwId = (string)$user['mgw_id'];
        $avatarItemId = trim((string)($user['equipped_avatar_item_id'] ?? ''));
        if ($avatarItemId === '') $avatarItemId = MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID;
        return [
            'mgw_id' => $internalMgwId,
            'public_mgw_id' => MgwIdGenerator::toPublic($internalMgwId),
            'status' => (string)($user['status'] ?? 'active'),
            'nickname' => $nickname,
            'display_name' => $nickname,
            'username' => null,
            'avatar' => ['item_id' => $avatarItemId],
            'preferred_locale' => $this->nullableString($user['preferred_locale'] ?? null),
            'identities' => $publicIdentities,
            'created_at' => $this->nullableString($user['created_at_utc'] ?? null),
            'updated_at' => $this->nullableString($user['updated_at_utc'] ?? null),
            'last_seen_at' => $this->nullableString($user['last_seen_at_utc'] ?? null),
        ];
    }

    public function updateProfile(string $mgwId, array $changes): array
    {
        $mgwId = trim($mgwId);
        if (!MgwIdGenerator::isValid($mgwId)) throw new InvalidArgumentException('MGW profile update invalid: id');
        if ($changes === []) throw new InvalidArgumentException('MGW profile update invalid: empty');

        try {
            return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($mgwId, $changes): array {
                $sets = [];
                $parameters = ['mgw_id' => $mgwId, 'updated_at' => $this->timestamp()];
                if (array_key_exists('nickname', $changes)) {
                    $nickname = MgwIdentityPolicy::normalizeNickname($changes['nickname']);
                    $sets[] = 'nickname = :nickname';
                    $sets[] = 'display_name = :display_name';
                    $parameters['nickname'] = $nickname;
                    $parameters['display_name'] = $nickname;
                }
                if (array_key_exists('preferred_locale', $changes)) {
                    $sets[] = 'preferred_locale = :preferred_locale';
                    $parameters['preferred_locale'] = MgwIdentityPolicy::normalizeLocale($changes['preferred_locale']);
                }

                if ($sets !== []) {
                    $sets[] = 'updated_at_utc = :updated_at';
                    $affected = $database->execute(
                        'UPDATE mgw_users SET ' . implode(', ', $sets) . ' WHERE mgw_id = :mgw_id',
                        $parameters
                    );
                    if ($affected !== 1) throw new RuntimeException('Authenticated MGW profile is unavailable.');
                }

                if (array_key_exists('avatar_item_id', $changes)) {
                    $avatarItemId = strtolower(trim((string)$changes['avatar_item_id']));
                    if ($avatarItemId === '') throw new InvalidArgumentException('MGW avatar item id is invalid.');
                    // ProductInventoryService is the only post-MVP-19.1 avatar
                    // equip writer. It validates catalogue membership + ownership
                    // and projects the selected item into mgw_users compatibility.
                    (new ProductInventoryService($database))->equip($mgwId, $avatarItemId);
                }

                if ($sets === [] && !array_key_exists('avatar_item_id', $changes)) {
                    throw new InvalidArgumentException('MGW profile update invalid: empty');
                }

                return $this->publicProfile($mgwId);
            });
        } catch (PDOException $error) {
            if (MgwIdentityPolicy::isUniqueViolation($error)) {
                throw new RuntimeException(MgwIdentityPolicy::NICKNAME_TAKEN_ERROR, 0, $error);
            }
            throw $error;
        }
    }

    private function ensureCanonicalIdentity(string $mgwId): void
    {
        for ($attempt = 1; $attempt <= self::CLAIM_ATTEMPTS; $attempt++) {
            $rows = $this->database->fetchAll(
                'SELECT nickname, equipped_avatar_item_id FROM mgw_users WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if (count($rows) !== 1) throw new RuntimeException('Authenticated MGW profile is unavailable.');
            $nickname = trim((string)($rows[0]['nickname'] ?? ''));
            $avatarItemId = trim((string)($rows[0]['equipped_avatar_item_id'] ?? ''));
            if ($nickname !== '' && $avatarItemId !== '') return;
            $candidate = $nickname !== '' ? $nickname : MgwIdentityPolicy::generateNickname();
            try {
                $this->database->execute(
                    'UPDATE mgw_users
                     SET nickname = CASE WHEN nickname IS NULL OR nickname = \'\' THEN :nickname ELSE nickname END,
                         display_name = :display_name,
                         username = NULL,
                         avatar_provider = NULL,
                         avatar_external_ref = NULL,
                         updated_at_utc = :updated_at
                     WHERE mgw_id = :mgw_id',
                    [
                        'nickname' => $candidate,
                        'display_name' => $candidate,
                        'updated_at' => $this->timestamp(),
                        'mgw_id' => $mgwId,
                    ]
                );
                if ($avatarItemId === '') {
                    $inventory = new ProductInventoryService($this->database);
                    $inventory->grantStarterItems($mgwId);
                    $inventory->equip($mgwId, MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID);
                }
                return;
            } catch (PDOException $error) {
                if (!MgwIdentityPolicy::isUniqueViolation($error) || $attempt === self::CLAIM_ATTEMPTS) throw $error;
            }
        }
        throw new RuntimeException('Unable to claim canonical MGW identity.');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
