<?php
declare(strict_types=1);

final class InviteSignalService
{
    private const TTL_SEC = 30;
    private string $root;

    public function __construct(array $config)
    {
        $scope = hash('sha256', (string)($config['base_url'] ?? '') . '|' . (string)($config['data_dir'] ?? ''));
        $this->root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'mini-games-world-invite-signals-' . substr($scope, 0, 16);
    }

    public function publish(string $recipientId, array $invite): void
    {
        $recipientId = trim($recipientId);
        $token = strtolower(trim((string)($invite['token'] ?? '')));
        if ($recipientId === '' || !preg_match('/^[a-f0-9]{24}$/', $token)) return;
        if ((string)($invite['status'] ?? '') !== 'pending') return;

        $directory = $this->accountDirectory($recipientId);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;

        $payload = [
            'recipient_id' => $recipientId,
            'published_at' => time(),
            'expires_at' => time() + self::TTL_SEC,
            'invite' => $this->forInvitee($invite),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;

        $path = $this->signalPath($recipientId, $token);
        $temporary = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) return;
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) @unlink($temporary);
        $this->pruneDirectory($directory);
    }

    public function clear(string $recipientId, string $token): void
    {
        $recipientId = trim($recipientId);
        $token = strtolower(trim($token));
        if ($recipientId === '' || !preg_match('/^[a-f0-9]{24}$/', $token)) return;
        @unlink($this->signalPath($recipientId, $token));
    }

    public function latest(string $recipientId): ?array
    {
        $recipientId = trim($recipientId);
        if ($recipientId === '') return null;
        $directory = $this->accountDirectory($recipientId);
        if (!is_dir($directory)) return null;

        $now = time();
        $latest = null;
        $latestPublishedAt = 0;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.signal') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($payload)) {
                @unlink($path);
                continue;
            }
            $expiresAt = (int)($payload['expires_at'] ?? 0);
            $publishedAt = (int)($payload['published_at'] ?? 0);
            $invite = $payload['invite'] ?? null;
            if ($expiresAt <= $now || !is_array($invite) || (string)($invite['status'] ?? '') !== 'pending') {
                @unlink($path);
                continue;
            }
            if ($publishedAt < $latestPublishedAt) continue;
            $latest = $invite;
            $latestPublishedAt = $publishedAt;
        }
        return $latest;
    }

    private function forInvitee(array $invite): array
    {
        $invite['is_owner'] = false;
        $invite['is_invitee'] = true;
        $invite['is_participant'] = true;
        $invite['can_accept'] = true;
        $invite['can_decline'] = true;
        $invite['can_start'] = false;
        $invite['can_cancel'] = false;
        return $invite;
    }

    private function accountDirectory(string $recipientId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'account-' . hash('sha256', $recipientId);
    }

    private function signalPath(string $recipientId, string $token): string
    {
        return $this->accountDirectory($recipientId)
            . DIRECTORY_SEPARATOR . 'invite-' . hash('sha256', $token) . '.signal';
    }

    private function pruneDirectory(string $directory): void
    {
        $now = time();
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (!is_file($path)) continue;
            $modifiedAt = @filemtime($path) ?: 0;
            if ($modifiedAt <= 0 || $now - $modifiedAt > self::TTL_SEC + 30) @unlink($path);
        }
    }
}
