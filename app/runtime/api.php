<?php
declare(strict_types=1);

use Mgw\CleanRuntime\Server\Auth\AuthenticationException;
use Mgw\CleanRuntime\Server\Auth\RuntimeAuthenticationService;
use Mgw\CleanRuntime\Server\Auth\TelegramInitDataVerifier;
use Mgw\CleanRuntime\Server\RuntimeBootstrapService;
use Mgw\CleanRuntime\Server\RuntimeConfig;
use Mgw\CleanRuntime\Server\RuntimeKernel;
use Mgw\CleanRuntime\Server\Storage\JsonFileRuntimeRepository;

require_once __DIR__ . '/server/contracts/RuntimeRepository.php';
require_once __DIR__ . '/server/RuntimeConfig.php';
require_once __DIR__ . '/server/auth/AuthenticationException.php';
require_once __DIR__ . '/server/auth/AuthenticatedIdentity.php';
require_once __DIR__ . '/server/auth/TelegramInitDataVerifier.php';
require_once __DIR__ . '/server/auth/RuntimeAuthenticationService.php';
require_once __DIR__ . '/server/storage/JsonFileRuntimeRepository.php';
require_once __DIR__ . '/server/RuntimeBootstrapService.php';
require_once __DIR__ . '/server/RuntimeKernel.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = strtolower(trim((string)($_GET['action'] ?? 'health')));
    $payload = [];

    if ($method === 'POST') {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 32768) {
            throw new InvalidArgumentException('Clean runtime request is too large.');
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            throw new RuntimeException('Cannot read clean runtime request.');
        }
        $decoded = json_decode($raw !== '' ? $raw : '{}', true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Clean runtime request body must be an object.');
        }
        $payload = $decoded;
    }

    $config = RuntimeConfig::fromEnvironment();
    $repository = new JsonFileRuntimeRepository($config->dataDirectory);
    $telegramVerifier = new TelegramInitDataVerifier(
        $config->botToken,
        $config->telegramInitDataMaxAgeSec,
        $config->telegramInitDataClockSkewSec,
    );
    $authentication = new RuntimeAuthenticationService($config, $telegramVerifier);
    $service = new RuntimeBootstrapService($config, $repository, $authentication);
    $kernel = new RuntimeKernel($service);
    $response = $kernel->handle($method, $action, $payload);

    http_response_code($response['status']);
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid clean runtime JSON body.'], JSON_THROW_ON_ERROR);
} catch (AuthenticationException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('MGW clean runtime API failure: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Clean runtime staging server is unavailable.'], JSON_THROW_ON_ERROR);
}
