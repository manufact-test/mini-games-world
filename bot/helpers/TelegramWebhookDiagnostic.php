<?php
declare(strict_types=1);

final class TelegramWebhookDiagnostic
{
    private bool $enabled;
    private string $reportFile;

    public function __construct(array $config, ?string $configFile)
    {
        $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
        $privateDir = is_string($configFile) && $configFile !== '' ? dirname($configFile) : '';

        $this->enabled = $environment === 'staging' && $privateDir !== '' && is_dir($privateDir);
        $this->reportFile = $this->enabled ? $privateDir . '/telegram-webhook-diagnostic.json' : '';
    }

    public function recordReceived(array $update): void
    {
        $this->write($this->report('received', $update));
    }

    public function recordHandled(array $update, string $handler): void
    {
        $report = $this->report('handled', $update);
        $report['handler'] = $this->sanitizeLabel($handler);
        $this->write($report);
    }

    public function recordError(array $update, Throwable $error): void
    {
        $report = $this->report('error', $update);
        $report['error_class'] = $this->sanitizeLabel($error::class);
        $report['error_message'] = $this->sanitizeError($error->getMessage());
        $this->write($report);
    }

    public static function sanitizedUpdateSummary(array $update): array
    {
        $type = 'unknown';
        $action = 'unknown';

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $type = 'callback_query';
            $data = trim((string)($update['callback_query']['data'] ?? ''));
            if (str_starts_with($data, 'admin:')) {
                $parts = explode(':', $data);
                $actionName = preg_replace('/[^a-z0-9_\-]/i', '', (string)($parts[1] ?? ''));
                $action = $actionName !== '' ? 'admin:' . $actionName . ':*' : 'admin:*';
            } elseif ($data !== '') {
                $action = 'callback';
            }
        } elseif (isset($update['message']) && is_array($update['message'])) {
            $type = 'message';
            $action = self::messageAction((string)($update['message']['text'] ?? ''));
        } elseif (isset($update['edited_message']) && is_array($update['edited_message'])) {
            $type = 'edited_message';
            $action = self::messageAction((string)($update['edited_message']['text'] ?? ''));
        }

        return [
            'update_type' => $type,
            'action' => $action,
        ];
    }

    private static function messageAction(string $text): string
    {
        $text = trim($text);
        if ($text === '') return 'empty_message';
        if (!str_starts_with($text, '/')) return 'text_message';

        $token = preg_split('/\s+/', $text, 2)[0] ?? '';
        $token = explode('@', (string)$token, 2)[0];
        $token = preg_replace('/[^a-z0-9_\/\-]/i', '', $token);
        return $token !== '' ? $token : 'command';
    }

    private function report(string $stage, array $update): array
    {
        $summary = self::sanitizedUpdateSummary($update);
        return [
            'ok' => $stage !== 'error',
            'report_type' => 'mvp-14.8.3-telegram-webhook-diagnostic',
            'environment' => 'staging',
            'stage' => $stage,
            'update_type' => $summary['update_type'],
            'action' => $summary['action'],
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    private function sanitizeLabel(string $value): string
    {
        $value = preg_replace('/[^a-z0-9_\\\-]/i', '', trim($value));
        return mb_substr((string)$value, 0, 120);
    }

    private function sanitizeError(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/\b(?:pay_|tx_|game_|inv_)?[A-Za-z0-9_-]{8,}\b/i', '[redacted]', $message);
        $message = preg_replace('/\b\d{5,}\b/', '[number]', (string)$message);
        return mb_substr((string)$message, 0, 500);
    }

    private function write(array $report): void
    {
        if (!$this->enabled || $this->reportFile === '') return;

        try {
            $report['report_fingerprint'] = hash('sha256', json_encode(
                $report,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            $json = json_encode(
                $report,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL;

            $temporary = $this->reportFile . '.tmp';
            if (file_put_contents($temporary, $json, LOCK_EX) === false) return;
            @chmod($temporary, 0600);
            @rename($temporary, $this->reportFile);
            @chmod($this->reportFile, 0600);
        } catch (Throwable) {
            // Diagnostics must never break webhook processing.
        }
    }
}
