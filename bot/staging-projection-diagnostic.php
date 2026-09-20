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

    // Exact staging deploys may introduce additive schema that is required by
    // the just-deployed runtime. Apply only the repository's managed pending
    // migrations after GitHub OIDC has authenticated this exact staging push.
    // This endpoint is staging-only and cannot authorize production migration.
    $migrationController = new ManagedMigrationController(
        new MigrationRunner($db, __DIR__ . '/database/migrations'),
        ManagedMigrationConfig::fromApplicationConfig($config)
    );
    $migrationResult = $migrationController->run();

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

    $tournament = $db->fetchAll(
        "SELECT tournament_id, tournament_state, game_type, capacity
         FROM mgw_tournaments
         WHERE active_slot='official'
         ORDER BY created_at_utc DESC
         LIMIT 1"
    );
    $activeRegistrations = 0;
    if ($tournament !== []) {
        $activeRegistrations = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM mgw_tournament_registrations
             WHERE tournament_id=:tournament_id
               AND registration_state='registered'",
            ['tournament_id'=>(string)$tournament[0]['tournament_id']]
        );
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
        'tournament'=>$tournament[0] ?? null,
        'registered_count'=>$activeRegistrations,
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
