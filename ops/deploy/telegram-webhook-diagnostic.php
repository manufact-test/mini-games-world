<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/core/bootstrap.php';

$exitCode = 0;

try {
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment !== 'staging') {
        throw new RuntimeException('Telegram webhook diagnostics are enabled only in staging.');
    }

    $privateDir = is_string($configFile ?? null)
        ? dirname($configFile)
        : dirname($projectRoot) . '/_private_mgw';
    $reportFile = $privateDir . '/telegram-webhook-diagnostic.json';

    if (!is_file($reportFile)) {
        $result = [
            'ok' => true,
            'report_type' => 'mvp-14.8.3-telegram-webhook-diagnostic-reader',
            'environment' => $environment,
            'diagnostic_found' => false,
            'message' => 'No webhook diagnostic event has been recorded yet.',
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    } else {
        $decoded = json_decode((string)file_get_contents($reportFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || (string)($decoded['report_type'] ?? '') !== 'mvp-14.8.3-telegram-webhook-diagnostic'
            || !array_key_exists('sensitive_identifiers_exposed', $decoded)) {
            throw new RuntimeException('Webhook diagnostic report has an unexpected format.');
        }

        $result = [
            'ok' => true,
            'report_type' => 'mvp-14.8.3-telegram-webhook-diagnostic-reader',
            'environment' => $environment,
            'diagnostic_found' => true,
            'diagnostic' => $decoded,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    $result['report_fingerprint'] = hash('sha256', json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));

    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, json_encode([
        'ok' => false,
        'report_type' => 'mvp-14.8.3-telegram-webhook-diagnostic-reader',
        'sensitive_identifiers_exposed' => false,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
}

exit($exitCode);
