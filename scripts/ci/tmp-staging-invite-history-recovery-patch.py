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
    """        $this->reconcileDatabaseOnlyRows = $reconcileDatabaseOnlyRows
            ?? (($config['environment'] ?? null) === 'staging' && $database === null);
""",
    """        $this->reconcileDatabaseOnlyRows = $reconcileDatabaseOnlyRows
            ?? (($config['environment'] ?? null) === 'staging');
""",
)

replace_exact(
    'bot/services/StagingTestOnlyInviteOrphanRecoveryService.php',
    """            if (trim((string)($invite['match_id'] ?? '')) !== '') {
                throw new RuntimeException('Staging test-only orphan recovery refuses matched A/B invite.');
            }
            $matchRefs = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            if ($matchRefs !== 0) {
                throw new RuntimeException('Staging test-only orphan recovery refuses match-referenced A/B invite.');
            }
""",
    """            $matchId = trim((string)($invite['match_id'] ?? ''));
            $matchRefs = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id OR source_match_id = :source_match_id',
                ['invite_id' => $inviteId, 'source_match_id' => $inviteId]
            );
            // A DB-only invite tied to a normalized match is retained history,
            // not an orphan candidate. Runtime invite parity intentionally
            // preserves the same row and excludes it from active JSON parity.
            if ($matchId !== '' || $matchRefs !== 0) {
                continue;
            }
""",
)

replace_exact(
    'bot/tests/StagingInviteLifecycleParityReconciliationTest.php',
    """$injectedDatabase = new StagingInviteLifecycleFakeDatabase([]);
$injectedRepository = new RuntimeInviteRepository($config, $router, $injectedDatabase);
$assertSame(
    false,
    $property->getValue($injectedRepository),
    'Injected repositories must preserve strict legacy behavior unless reconciliation is explicit'
);
""",
    """$injectedDatabase = new StagingInviteLifecycleFakeDatabase([]);
$injectedRepository = new RuntimeInviteRepository($config, $router, $injectedDatabase);
$assertSame(
    true,
    $property->getValue($injectedRepository),
    'Every staging repository view must recognize the same DB-only lifecycle history semantics'
);

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$productionRepository = new RuntimeInviteRepository($productionConfig, $router, $injectedDatabase);
$assertSame(
    false,
    $property->getValue($productionRepository),
    'Production repositories must not inherit staging lifecycle reconciliation implicitly'
);
""",
)

replace_exact(
    'bot/tests/StagingInviteLifecycleParityReconciliationTest.php',
    "$assertContains(\"?? ((\\$config['environment'] ?? null) === 'staging' && \\$database === null);\", $source, 'Live staging default must be explicit');",
    "$assertContains(\"?? ((\\$config['environment'] ?? null) === 'staging');\", $source, 'Staging lifecycle semantics must be environment-scoped');",
)

replace_exact(
    'bot/tests/StagingTestOnlyInviteOrphanRecoveryContractTest.php',
    """$assert(str_contains($service, \"throw new RuntimeException('Staging test-only orphan recovery refuses match-referenced A/B invite.');\"),
    'Recovery must refuse match-referenced invites.');
""",
    """$assert(str_contains($service, \"if ($matchId !== '' || $matchRefs !== 0) {\")
    && str_contains($service, 'Runtime invite parity intentionally')
    && !str_contains($service, \"throw new RuntimeException('Staging test-only orphan recovery refuses match-referenced A/B invite.');\"),
    'Recovery must preserve match-referenced A/B history without treating it as an orphan candidate.');
""",
)
