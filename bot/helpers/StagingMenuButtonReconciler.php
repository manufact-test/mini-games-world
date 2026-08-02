<?php
declare(strict_types=1);

final class StagingMenuButtonReconciler
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const MARKER_PREFIX = '.staging-menu-button-';

    public function __construct(
        private TelegramService $telegram,
        private array $config
    ) {
    }

    public function reconcile(): void
    {
        if (($this->config['environment'] ?? '') !== 'staging') {
            return;
        }

        $baseUrl = rtrim(trim((string)($this->config['base_url'] ?? '')), '/');
        $scheme = strtolower((string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: ''));
        $host = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
        if ($scheme !== 'https' || $host !== self::STAGING_HOST) {
            throw new RuntimeException('Staging menu button reconciliation refused an unexpected base URL.');
        }

        $expectedUsername = strtolower(ltrim(trim((string)($this->config['staging_bot_username'] ?? '')), '@'));
        if ($expectedUsername === '') {
            throw new RuntimeException('Staging bot username is unavailable.');
        }

        $webAppUrl = $baseUrl . '/app/';
        $identity = hash('sha256', $expectedUsername . "\n" . $webAppUrl);
        $markerFile = $this->markerFile($identity);
        if (is_file($markerFile)) {
            return;
        }

        $result = $this->telegram->api('setChatMenuButton', [
            'menu_button' => [
                'type' => 'web_app',
                'text' => 'Открыть игру',
                'web_app' => ['url' => $webAppUrl],
            ],
        ]);
        if (($result['ok'] ?? null) !== true) {
            throw new RuntimeException('Telegram rejected the staging Mini App menu button.');
        }

        $directory = dirname($markerFile);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the staging menu button marker directory.');
        }

        $temporary = $markerFile . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $identity . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the staging menu button marker.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $markerFile)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish the staging menu button marker.');
        }
        @chmod($markerFile, 0600);
    }

    private function markerFile(string $identity): string
    {
        $dataDir = trim((string)($this->config['data_dir'] ?? ''));
        if ($dataDir === '') {
            $dataDir = dirname(__DIR__) . '/data';
        }

        return rtrim($dataDir, '/\\')
            . DIRECTORY_SEPARATOR
            . self::MARKER_PREFIX
            . substr($identity, 0, 24);
    }
}
