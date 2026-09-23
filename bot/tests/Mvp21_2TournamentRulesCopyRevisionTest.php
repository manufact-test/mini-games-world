<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/tournaments/TournamentRegistrationService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_2TournamentRulesCopyRevisionTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(mb_strtolower($error->getMessage()), mb_strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error: ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL,
    nickname TEXT NULL
)');

(require $root . '/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require $root . '/database/migrations/20260920_0049_create_official_tournaments.php')->up($db);

$rewardJson = json_encode(
    TournamentRegistrationService::canonicalRewardSnapshot(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$db->execute(
    'INSERT INTO mgw_tournaments (
        tournament_id,active_slot,title,game_type,capacity,entry_fee_amount,entry_asset_code,
        reward_snapshot_json,tournament_state,created_by_ref,opened_by_ref,
        created_at_utc,registration_opened_at_utc,updated_at_utc
     ) VALUES (
        :id,:slot,:title,:game,:capacity,:entry,:asset,:reward,:state,:created_by,:opened_by,
        :created_at,:opened_at,:updated_at
     )',
    [
        'id'=>'tour_mvp21_2_copy_revision',
        'slot'=>TournamentRegistrationService::ACTIVE_SLOT,
        'title'=>'Проба один',
        'game'=>'tictactoe',
        'capacity'=>8,
        'entry'=>50000,
        'asset'=>'mgw_coin',
        'reward'=>$rewardJson,
        'state'=>TournamentRegistrationService::STATE_REGISTRATION_OPEN,
        'created_by'=>'test:admin',
        'opened_by'=>'test:admin',
        'created_at'=>'2026-09-20 18:00:00.000000',
        'opened_at'=>'2026-09-20 18:01:00.000000',
        'updated_at'=>'2026-09-20 18:01:00.000000',
    ]
);

(require $root . '/database/migrations/20260920_0050_add_tournament_rules_consent.php')->up($db);

$mgwId = 'MGW-0123456789ABCDEF';
$accountRef = 'legacy:777001';
$db->execute(
    'INSERT INTO mgw_users (mgw_id,status,nickname) VALUES (:id,:status,:nickname)',
    ['id'=>$mgwId,'status'=>'active','nickname'=>'Tester']
);

$clock = '2026-09-20 18:02:00.000000';
$ledger = new LedgerWriteService($db, static function () use (&$clock): string {
    return $clock;
});
$ledger->postAvailableDelta([
    'operation_key'=>'mvp21_2_copy:grant',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'legacy_user_id'=>'777001',
    'asset_code'=>'mgw_coin',
    'available_delta'=>154702,
    'category'=>'test_grant',
    'source_type'=>'test',
]);

$service = new TournamentRegistrationService($db, $ledger);
$legacy = $service->snapshot($mgwId, $accountRef);
$legacyRules = $legacy['tournament']['rules'];
$assertSame('official-tournament-rules-v1', $legacyRules['version'], 'Existing staging tournament must begin on the applied v1 rules snapshot.');

$legacyConsent = [
    'accepted'=>true,
    'version'=>(string)$legacyRules['version'],
    'language'=>(string)$legacyRules['language'],
    'sha256'=>(string)$legacyRules['sha256'],
];
$clock = '2026-09-20 18:03:00.000000';
$registered = $service->register(
    $mgwId,
    $accountRef,
    new DateTimeImmutable('2026-09-20T18:03:00Z'),
    $legacyConsent
);
$assertSame('registered', $registered['registration']['state'], 'Legacy accepted participant must register before the copy revision.');
$assertSame(104702, $registered['balance']['available_amount'], 'Initial reservation must still hold exactly 50,000.');
$assertSame(50000, $registered['balance']['reserved_amount'], 'Initial reservation must remain reserved, not spent.');

(require $root . '/database/migrations/20260920_0051_refresh_tournament_rules_copy.php')->up($db);

$revised = $service->snapshot($mgwId, $accountRef);
$newRules = $revised['tournament']['rules'];
$assertSame(TournamentRegistrationService::RULES_VERSION, $newRules['version'], 'Copy migration must advance the active tournament rules version.');
$assertTrue(
    $newRules['sha256'] !== $legacyRules['sha256'],
    'Human-readable copy revision must get a new immutable rules identity.'
);
$sections = $newRules['snapshot']['sections'] ?? [];
$rulesText = json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach ([
    'После набора состава назначается дата и время турнира.',
    'Если отключились оба игрока, им даётся до 3 минут, чтобы вернуться в игру.',
    'Между раундами предусмотрен перерыв 3 минуты.',
    '1 место: 200 000 коинов; Golden Ticket;',
    'Для остальных участников денежная награда не предусмотрена.',
    'эксклюзивный чемпионский набор оформления игр',
    'Изменение правил',
] as $needle) {
    $assertTrue(str_contains($rulesText, $needle), 'Revised tournament rules must contain human copy: ' . $needle);
}
foreach ([
    'отдельная ветка восстановления',
    'возврат 50 000 взноса + 150 000 приз',
    'Согласие сохраняется вместе с точной версией правил, языком и временем принятия.',
    'чемпионская косметика',
    'Между раундами предусмотрен перерыв 5 минут.',
] as $needle) {
    $assertTrue(!str_contains($rulesText, $needle), 'Revised tournament rules must remove technical copy: ' . $needle);
}

$assertSame(
    $legacyRules['sha256'],
    (string)$revised['registration']['rules_consent']['sha256'],
    'Existing consent must never be silently rewritten by a rules copy migration.'
);

$assertThrows(
    fn() => $service->register(
        $mgwId,
        $accountRef,
        new DateTimeImmutable('2026-09-20T18:04:00Z'),
        $legacyConsent
    ),
    'обновились',
    'Registered participant must explicitly accept the current rules identity.'
);

$balanceAfterRejectedConsent = $ledger->getBalance($accountRef, 'mgw_coin');
$assertSame(104702, $balanceAfterRejectedConsent['available_amount'], 'Rejected stale consent must not reserve a second entry fee.');
$assertSame(50000, $balanceAfterRejectedConsent['reserved_amount'], 'Rejected stale consent must keep the existing hold unchanged.');

$currentConsent = [
    'accepted'=>true,
    'version'=>(string)$newRules['version'],
    'language'=>(string)$newRules['language'],
    'sha256'=>(string)$newRules['sha256'],
];
$clock = '2026-09-20 18:05:00.000000';
$refreshed = $service->register(
    $mgwId,
    $accountRef,
    new DateTimeImmutable('2026-09-20T18:05:00Z'),
    $currentConsent
);
$assertSame('registered', $refreshed['registration']['state'], 'Current rules consent must refresh the existing registration in place.');
$assertSame($newRules['sha256'], $refreshed['registration']['rules_consent']['sha256'], 'Consent refresh must bind the exact current rules hash.');
$assertSame(104702, $refreshed['balance']['available_amount'], 'Consent refresh must not debit coins again.');
$assertSame(50000, $refreshed['balance']['reserved_amount'], 'Consent refresh must preserve the one existing reservation.');
$assertSame(1, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_reservations WHERE account_ref=:account_ref AND status='active'",
    ['account_ref'=>$accountRef]
), 'Consent refresh must preserve exactly one active reservation.');

if ($assertions < 20) {
    throw new RuntimeException('MVP-21.2 rules copy revision coverage is incomplete.');
}
fwrite(STDOUT, "Mvp21_2TournamentRulesCopyRevisionTest: {$assertions} assertions passed\n");
