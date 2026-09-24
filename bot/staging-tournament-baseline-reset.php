<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';
require_once __DIR__ . '/tournaments/StagingTournamentBaselineResetService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'method_not_allowed'], 405);
    }
    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        json_response(['ok'=>false,'error'=>'staging_only'], 403);
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 8192) {
        json_response(['ok'=>false,'error'=>'invalid_request'], 400);
    }
    $payload = json_decode($raw !== '' ? $raw : '{}', true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'invalid_request'], 400);
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) !== 1) {
        json_response(['ok'=>false,'error'=>'oidc_required'], 403);
    }
    $claims = (new GitHubActionsOidcVerifier($config))->verifyAndConsume(trim((string)$match[1]));

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        throw new RuntimeException('database_disabled');
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new StagingTournamentBaselineResetService(
        $config,
        $database,
        new LedgerWriteService($database)
    );

    $action = strtolower(trim((string)($payload['action'] ?? 'preview')));
    if ($action === 'preview') {
        json_response([
            'ok'=>true,
            'action'=>'preview',
            'authorized_sha'=>(string)($claims['sha'] ?? ''),
            'preview'=>$service->preview($_SERVER),
        ]);
    }
    if ($action === 'apply') {
        $expectedFingerprint = trim((string)($payload['expected_fingerprint'] ?? ''));
        $confirmation = trim((string)($payload['confirmation'] ?? ''));
        $result = $service->apply(
            $_SERVER,
            $expectedFingerprint,
            $confirmation,
            'github-actions:mvp21-baseline-reset'
        );
        json_response([
            'ok'=>true,
            'action'=>'apply',
            'authorized_sha'=>(string)($claims['sha'] ?? ''),
            'result'=>$result,
        ]);
    }

    json_response(['ok'=>false,'error'=>'unknown_action'], 422);
} catch (JsonException $error) {
    json_response(['ok'=>false,'error'=>'invalid_json'], 400);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld MVP-21 staging baseline reset] ' . $error->getMessage());
    json_response([
        'ok'=>false,
        'error'=>'mvp21_staging_baseline_reset_failed',
        'message'=>$error->getMessage(),
    ], 409);
}
