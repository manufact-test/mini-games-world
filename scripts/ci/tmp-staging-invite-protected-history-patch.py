from pathlib import Path


def replace_exact(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one replacement, found {count}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


replace_exact(
    'bot/invites/RuntimeInviteRepository.php',
    """        foreach ($database->fetchAll(
            'SELECT invite_id FROM mgw_invites ORDER BY invite_id'
        ) as $row) {
            $inviteId = trim((string)($row['invite_id'] ?? ''));
            if ($inviteId === '') {
                throw new RuntimeException('Invite DB contains an invalid invite ID.');
            }
            if (isset($source[$inviteId])) continue;

            $relatedMatches = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );
            if ($relatedMatches > 0) {
                $historicalInviteIds[] = $inviteId;
                continue;
            }
""",
    """        foreach ($database->fetchAll(
            'SELECT invite_id, match_id FROM mgw_invites ORDER BY invite_id'
        ) as $row) {
            $inviteId = trim((string)($row['invite_id'] ?? ''));
            if ($inviteId === '') {
                throw new RuntimeException('Invite DB contains an invalid invite ID.');
            }
            if (isset($source[$inviteId])) continue;

            $matchId = trim((string)($row['match_id'] ?? ''));
            $relatedMatches = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            if ($matchId !== '' || $relatedMatches > 0) {
                $historicalInviteIds[] = $inviteId;
                continue;
            }
""",
)

replace_exact(
    'bot/tests/StagingInviteLifecycleParityReconciliationTest.php',
    """    /** @var array<string, array{invite_id:string}> */
    private array $invites = [];

    /** @var array<string, int> */
    private array $relatedMatches = [];

    public function __construct(array $inviteIds, array $relatedMatches = [], private int $eventDeletes = 0)
    {
        foreach ($inviteIds as $inviteId) {
            $inviteId = (string)$inviteId;
            $this->invites[$inviteId] = ['invite_id' => $inviteId];
        }
""",
    """    /** @var array<string, array{invite_id:string,match_id?:?string}> */
    private array $invites = [];

    /** @var array<string, int> */
    private array $relatedMatches = [];

    public function __construct(array $inviteRows, array $relatedMatches = [], private int $eventDeletes = 0)
    {
        foreach ($inviteRows as $value) {
            $row = is_array($value) ? $value : ['invite_id' => (string)$value];
            $inviteId = trim((string)($row['invite_id'] ?? ''));
            if ($inviteId === '') throw new RuntimeException('Fake invite ID is required.');
            $this->invites[$inviteId] = [
                'invite_id' => $inviteId,
                'match_id' => isset($row['match_id']) ? (string)$row['match_id'] : null,
            ];
        }
""",
)

replace_exact(
    'bot/tests/StagingInviteLifecycleParityReconciliationTest.php',
    """        if (str_contains($sql, 'SELECT invite_id FROM mgw_invites')) {
            return array_values($this->invites);
        }
""",
    """        if (str_contains($sql, 'SELECT invite_id, match_id FROM mgw_invites')) {
            return array_values($this->invites);
        }
""",
)

replace_exact(
    'bot/tests/StagingInviteLifecycleParityReconciliationTest.php',
    """$assertSame(1, $historicalAudit['preserved_historical_invite_rows'], 'Audit must report preserved historical row');

$source = file_get_contents($root . '/invites/RuntimeInviteRepository.php') ?: '';
""",
    """$assertSame(1, $historicalAudit['preserved_historical_invite_rows'], 'Audit must report preserved historical row');

$matchFieldDatabase = new StagingInviteLifecycleFakeDatabase([
    ['invite_id' => 'match-field-history', 'match_id' => 'game-history-1'],
]);
$matchFieldRepository = new RuntimeInviteRepository($config, $router, $matchFieldDatabase, true);
$matchFieldReport = $matchFieldRepository->synchronize(['invites' => []]);
$matchFieldAudit = $matchFieldRepository->auditParity(['invites' => []]);
$assertSame(true, $matchFieldReport['parity'], 'A DB-only invite with an assigned match must be protected history');
$assertSame(1, $matchFieldReport['preserved_historical_invite_rows'], 'Assigned-match history must be reported as preserved');
$assertSame(0, $matchFieldReport['pruned_invite_rows'], 'Assigned-match history must never be pruned');
$assertSame(1, $matchFieldDatabase->inviteCount(), 'Assigned-match history must remain in DB');
$assertSame(true, $matchFieldAudit['ok'], 'Read-only parity must ignore assigned-match history');

$source = file_get_contents($root . '/invites/RuntimeInviteRepository.php') ?: '';
""",
)

replace_exact(
    'bot/tests/StagingInviteLifecycleParityReconciliationTest.php',
    "$assertContains('SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id', $source, 'Match references must protect historical rows');",
    "$assertContains('SELECT invite_id, match_id FROM mgw_invites ORDER BY invite_id', $source, 'Assigned matches must protect historical rows');\n$assertContains('WHERE invite_id = :invite_id OR source_match_id = :source_match_id', $source, 'Direct and source-match references must protect historical rows');",
)
