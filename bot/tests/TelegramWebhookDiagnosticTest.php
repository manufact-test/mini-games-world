<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/TelegramWebhookDiagnostic.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!$actual) throw new RuntimeException($message);
};

$tempDir = sys_get_temp_dir() . '/mgw-webhook-diag-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Could not create temporary diagnostic directory.');
}
$configFile = $tempDir . '/config.php';
file_put_contents($configFile, "<?php return [];\n");

try {
    $callback = [
        'callback_query' => [
            'id' => 'callback-secret-12345678',
            'from' => ['id' => 123456789],
            'data' => 'admin:payment_apply:ABC12345',
            'message' => ['chat' => ['id' => 123456789]],
        ],
    ];

    $summary = TelegramWebhookDiagnostic::sanitizedUpdateSummary($callback);
    $assertSame('callback_query', $summary['update_type'], 'Callback type must be retained');
    $assertSame('admin:payment_apply:*', $summary['action'], 'Payment ID must be removed from action');

    $diagnostic = new TelegramWebhookDiagnostic(['environment' => 'staging'], $configFile);
    $diagnostic->recordReceived($callback);

    $reportFile = $tempDir . '/telegram-webhook-diagnostic.json';
    $assertTrue(is_file($reportFile), 'Staging diagnostic must write private report');
    $serialized = (string)file_get_contents($reportFile);
    $assertTrue(!str_contains($serialized, '123456789'), 'Telegram IDs must not be written');
    $assertTrue(!str_contains($serialized, 'ABC12345'), 'Payment IDs must not be written');
    $assertTrue(!str_contains($serialized, 'callback-secret'), 'Callback IDs must not be written');

    $diagnostic->recordError($callback, new RuntimeException('Failed payment pay_ABC12345 for user 123456789'));
    $errorReport = json_decode((string)file_get_contents($reportFile), true, 512, JSON_THROW_ON_ERROR);
    $assertSame('error', $errorReport['stage'] ?? null, 'Error stage must be recorded');
    $assertTrue(!str_contains((string)($errorReport['error_message'] ?? ''), 'ABC12345'), 'Error must redact payment ID');
    $assertTrue(!str_contains((string)($errorReport['error_message'] ?? ''), '123456789'), 'Error must redact Telegram ID');

    unlink($reportFile);
    $production = new TelegramWebhookDiagnostic(['environment' => 'production'], $configFile);
    $production->recordReceived($callback);
    $assertTrue(!is_file($reportFile), 'Production diagnostics must stay disabled');
} finally {
    foreach (glob($tempDir . '/*') ?: [] as $file) @unlink($file);
    @rmdir($tempDir);
}

fwrite(STDOUT, "TelegramWebhookDiagnosticTest: {$assertions} assertions passed\n");
