<?php
declare(strict_types=1);

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/localization/ServerLocalization.php';
require_once __DIR__ . '/support/SupportTicketService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.support.attachment.method_not_allowed', 'Method not allowed.')], 405);
    }

    $attachmentId = trim((string)($_GET['attachment_id'] ?? ''));
    if (preg_match('/^ticketatt_[a-f0-9]{32}$/', $attachmentId) !== 1) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.support.attachment.invalid_attachment', 'Invalid attachment.')], 400);
    }

    $authenticatedUser = (new AuthService($config))->getUserFromRequest([]);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.support.attachment.profile_unavailable', 'The MGW profile is unavailable for this session.')], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.support.attachment.unavailable', 'MGW Support is temporarily unavailable.')], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $attachment = (new SupportTicketService($database))->attachmentForUser($attachmentId, $mgwId);

    $binary = base64_decode((string)($attachment['content_base64'] ?? ''), true);
    if ($binary === false) {
        throw new RuntimeException('Stored support attachment is invalid.');
    }

    $mime = trim((string)($attachment['mime_type'] ?? 'application/octet-stream'));
    if ($mime === '') $mime = 'application/octet-stream';

    $fileName = basename(trim((string)($attachment['file_name'] ?? 'attachment')));
    $fileName = preg_replace('/[\x00-\x1F\x7F"\\\/]+/u', '_', $fileName) ?? 'attachment';
    if ($fileName === '') $fileName = 'attachment';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)strlen($binary));
    $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: 'attachment';
    header(
        'Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName)
    );
    echo $binary;
    exit;
} catch (SupportTicketException $error) {
    $status = $error->reason === 'attachment_not_found' ? 404 : 422;
    json_response([
        'ok'=>false,
        'code'=>$error->reason,
        'error'=>mgw_public_api_error($error->getMessage()),
    ], $status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld support attachment download] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>ServerLocalization::copy('server.support.attachment.failed', 'The attachment could not be downloaded.')], 500);
}
