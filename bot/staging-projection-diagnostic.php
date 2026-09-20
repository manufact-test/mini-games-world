<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'method_not_allowed'], 405);
    }
    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        json_response(['ok'=>false,'error'=>'staging_only'], 403);
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) !== 1) {
        json_response(['ok'=>false,'error'=>'oidc_required'], 403);
    }
    (new GitHubActionsOidcVerifier($config))->verifyAndConsume(trim((string)$m[1]));

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('database_disabled');
    }
    $db = PdoConnectionFactory::create($databaseConfig);

    $migrationVersion = '20260920_0050_add_tournament_rules_consent';
    $migrationPath = __DIR__ . '/database/migrations/' . $migrationVersion . '.php';
    $currentMigrationChecksum = hash_file('sha256', $migrationPath);
    $appliedMigration = $db->fetchAll(
        'SELECT version,checksum,applied_at_utc
         FROM mgw_schema_migrations
         WHERE version=:version
         LIMIT 1',
        ['version'=>$migrationVersion]
    );
    $appliedMigrationChecksum = trim((string)($appliedMigration[0]['checksum'] ?? ''));
    if (!is_string($currentMigrationChecksum) || $currentMigrationChecksum === '') {
        throw new RuntimeException('Could not calculate current tournament migration checksum.');
    }
    if ($appliedMigrationChecksum !== ''
        && !hash_equals(strtolower($appliedMigrationChecksum), strtolower($currentMigrationChecksum))) {
        json_response([
            'ok'=>false,
            'error'=>'migration_checksum_mismatch',
            'migration'=>[
                'version'=>$migrationVersion,
                'applied_checksum'=>$appliedMigrationChecksum,
                'current_checksum'=>$currentMigrationChecksum,
                'applied_at_utc'=>(string)($appliedMigration[0]['applied_at_utc'] ?? ''),
            ],
        ], 409);
    }

    // Exact staging deploys may introduce additive schema that is required by
    // the just-deployed runtime. Apply only the repository's managed pending
    // migrations after GitHub OIDC has authenticated this exact staging push.
    // This endpoint is staging-only and cannot authorize production migration.
    $migrationController = new ManagedMigrationController(
        new MigrationRunner($db, __DIR__ . '/database/migrations'),
        ManagedMigrationConfig::fromApplicationConfig($config)
    );
    $migrationResult = $migrationController->run();

    $tournamentSnapshot = (new TournamentRegistrationService(
        $db,
        new LedgerWriteService($db)
    ))->snapshot();

    // The canonical browser shell can preload the public rating archive while
    // an unrelated game test is running. Prove that read owner here so an HTTP
    // 500 becomes an exact OIDC-protected staging diagnostic instead of a
    // generic browser "Ошибка API: 500".
    try {
        $ratingArchiveOverview = (new RatingArchiveService($db))->publicOverview();
    } catch (Throwable $ratingArchiveError) {
        throw new RuntimeException(
            'rating_archive_probe_failed: '
            . get_class($ratingArchiveError)
            . ': '
            . $ratingArchiveError->getMessage(),
            0,
            $ratingArchiveError
        );
    }

    $events = $db->fetchAll(
        'SELECT state_revision, status, attempt_count, last_error, projection_version
         FROM mgw_runtime_primary_projection_outbox
         ORDER BY state_revision DESC
         LIMIT 12'
    );

    $failures = [];
    foreach ($events as $row) {
        if (!is_array($row)) continue;
        $error = trim((string)($row['last_error'] ?? ''));
        if ($error === '' && (string)($row['status'] ?? '') === 'completed') continue;
        $failures[] = [
            'state_revision'=>(int)($row['state_revision'] ?? 0),
            'status'=>(string)($row['status'] ?? ''),
            'attempt_count'=>(int)($row['attempt_count'] ?? 0),
            'last_error'=>substr($error, 0, 1200),
            'projection_version'=>(string)($row['projection_version'] ?? ''),
        ];
    }

    json_response([
        'ok'=>true,
        'service'=>'staging-projection-diagnostic',
        'managed_migrations'=>[
            'action'=>(string)($migrationResult['action'] ?? ''),
            'executed_count'=>(int)($migrationResult['executed_count'] ?? 0),
            'pending_after'=>(int)($migrationResult['after']['pending_count'] ?? -1),
        ],
        'failures'=>$failures,
        'tournament'=>$tournamentSnapshot['tournament'] ?? null,
        'registered_count'=>(int)($tournamentSnapshot['tournament']['registered_count'] ?? 0),
        'rating_archive'=>[
            'competition_state'=>(string)($ratingArchiveOverview['competition_state'] ?? ''),
            'current_season_id'=>(string)($ratingArchiveOverview['current_season_id'] ?? ''),
            'season_count'=>count(is_array($ratingArchiveOverview['seasons'] ?? null) ? $ratingArchiveOverview['seasons'] : []),
            'hall_of_fame_count'=>count(is_array($ratingArchiveOverview['hall_of_fame'] ?? null) ? $ratingArchiveOverview['hall_of_fame'] : []),
        ],
        'rules'=>isset($tournamentSnapshot['tournament']['rules']) && is_array($tournamentSnapshot['tournament']['rules'])
            ? [
                'version'=>(string)($tournamentSnapshot['tournament']['rules']['version'] ?? ''),
                'language'=>(string)($tournamentSnapshot['tournament']['rules']['language'] ?? ''),
                'sha256'=>(string)($tournamentSnapshot['tournament']['rules']['sha256'] ?? ''),
            ]
            : null,
        'generated_at'=>gmdate(DATE_ATOM),
    ]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging projection diagnostic] ' . $error->getMessage());
    json_response([
        'ok'=>false,
        'error'=>'diagnostic_failed',
        'exception'=>get_class($error),
        'detail'=>substr($error->getMessage(), 0, 1600),
    ], 500);
}
