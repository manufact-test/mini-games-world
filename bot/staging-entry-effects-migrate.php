<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

try {
    require __DIR__ . '/core/bootstrap.php';
    require_once __DIR__ . '/services/GitHubActionsOidcVerifier.php';
    require_once __DIR__ . '/services/StagingEntryEffectsMigrationService.php';

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 64) {
        throw new RuntimeException('Invalid staging migration request.');
    }
    $payload = json_decode($raw !== '' ? $raw : '{}', true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || $payload !== []) {
        throw new RuntimeException('Staging migration request must be empty.');
    }

    $authorization = trim((string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    ));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) !== 1) {
        throw new RuntimeException('Staging migration requires GitHub Actions OIDC.');
    }
    $credential = trim((string)$match[1]);
    if (substr_count($credential, '.') !== 2) {
        throw new RuntimeException('Staging migration requires GitHub Actions OIDC.');
    }

    $claims = (new GitHubActionsOidcVerifier($config))->verifyAndConsume($credential);
    $result = (new StagingEntryEffectsMigrationService($config))->applyIfExactlyPending($_SERVER);

    echo json_encode(
        $result + [
            'authorization_mode' => 'github_actions_oidc',
            'run_id' => (string)($claims['run_id'] ?? ''),
            'sha' => (string)($claims['sha'] ?? ''),
        ],
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging Entry Effects migration] denied: ' . get_class($error) . ' ' . $error->getMessage());
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'service' => 'mini-games-world-staging-entry-effects-migration',
        'error' => 'migration_unavailable',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
