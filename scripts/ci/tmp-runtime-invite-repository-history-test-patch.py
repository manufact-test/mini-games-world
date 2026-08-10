from pathlib import Path

path = Path('bot/tests/RuntimeInviteRepositoryTest.php')
text = path.read_text(encoding='utf-8')
old = r'''$assertThrows(
    static fn() => $repository->synchronize($raceData),
    'counts differ',
    'A parity failure after an insert must abort the whole DB synchronization'
);
$assertSame(
    [],
    $database->fetchAll(
        'SELECT invite_id FROM mgw_invites WHERE invite_id = :invite_id',
        ['invite_id' => 'invite-runtime-2']
    ),
    'A failed full synchronization must roll back rows inserted earlier in the same pass'
);
$database->execute(
    'DELETE FROM mgw_invites WHERE invite_id = :invite_id',
    ['invite_id' => 'invite-runtime-extra']
);
'''
new = r'''$stagingRace = $repository->synchronize($raceData);
$assertSame(true, $stagingRace['parity'], 'Staging synchronization must reconcile a DB-only invite tail');
$assertSame(1, $stagingRace['pruned_invite_rows'], 'Staging synchronization must prune exactly one unreferenced DB-only invite');
$assertSame(
    [],
    $database->fetchAll(
        'SELECT invite_id FROM mgw_invites WHERE invite_id = :invite_id',
        ['invite_id' => 'invite-runtime-extra']
    ),
    'Staging reconciliation must remove the DB-only invite tail'
);
$assertSame(
    1,
    count($database->fetchAll(
        'SELECT invite_id FROM mgw_invites WHERE invite_id = :invite_id',
        ['invite_id' => 'invite-runtime-2']
    )),
    'Staging synchronization must keep the newly inserted JSON-backed invite'
);
$database->execute(
    'DELETE FROM mgw_invites WHERE invite_id = :invite_id',
    ['invite_id' => 'invite-runtime-2']
);
(new RealtimeDatabaseStore($database))->upsertInvite($extraRow);

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$productionRepository = new RuntimeInviteRepository(
    $productionConfig,
    new RuntimeStorageRouter($config),
    $database
);
$assertThrows(
    static fn() => $productionRepository->synchronize($raceData),
    'counts differ',
    'Production-injected parity failure after an insert must abort the whole DB synchronization'
);
$assertSame(
    [],
    $database->fetchAll(
        'SELECT invite_id FROM mgw_invites WHERE invite_id = :invite_id',
        ['invite_id' => 'invite-runtime-2']
    ),
    'Production strict synchronization must roll back rows inserted earlier in the same pass'
);
$database->execute(
    'DELETE FROM mgw_invites WHERE invite_id = :invite_id',
    ['invite_id' => 'invite-runtime-extra']
);
'''
if text.count(old) != 1:
    raise SystemExit(f'expected one race block, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
