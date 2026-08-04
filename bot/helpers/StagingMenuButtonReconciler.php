<?php
declare(strict_types=1);

final class StagingMenuButtonReconciler
{
    private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com';
    private const MARKER_PREFIX = '.staging-menu-button-v2-';
    private const MARKER_TTL_SECONDS = 3600;

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

        if ($this->markerIsFresh($markerFile)) {
            return;
        }

        if ($this->menuButtonMatches($webAppUrl)) {
            $this->writeMarker($markerFile, $identity);
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

        if (!$this->menuButtonMatches($webAppUrl)) {
            throw new RuntimeException('Telegram did not persist the staging Mini App menu button.');
        }

        $this->writeMarker($markerFile, $identity);
    }

    private function menuButtonMatches(string $webAppUrl): bool
    {
        $response = $this->telegram->api('getChatMenuButton');
        if (($response['ok'] ?? null) !== true || !is_array($response['result'] ?? null)) {
            throw new RuntimeException('Telegram menu button state is unavailable.');
        }

        $button = $response['result'];
        return ($button['type'] ?? null) === 'web_app'
            && is_array($button['web_app'] ?? null)
            && (string)($button['web_app']['url'] ?? '') === $webAppUrl;
    }

    private function markerIsFresh(string $markerFile): bool
    {
        if (!is_file($markerFile)) {
            return false;
        }

        $modifiedAt = @filemtime($markerFile);
        return is_int($modifiedAt)
            && $modifiedAt > 0
            && (time() - $modifiedAt) < self::MARKER_TTL_SECONDS;
    }

    private function writeMarker(string $markerFile, string $identity): void
    {
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
