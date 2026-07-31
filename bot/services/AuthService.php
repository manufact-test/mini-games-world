<?php
declare(strict_types=1);

final class AuthService
{
    public function __construct(private array $config) {}

    public function getUserFromRequest(array $payload): array
    {
        $initData = (string)($payload['initData'] ?? '');
        if ($initData !== '') {
            $user = $this->validateTelegramInitData($initData);
            if ($user) {
                return $this->attachMgwIdentity($user, (string)($payload['sessionId'] ?? ''));
            }
        }

        if ($this->browserDevUserAllowed()) {
            $devId = (string)($_COOKIE['mgw_dev_user_id'] ?? '');
            if ($devId === '') {
                $devId = 'dev_' . random_int(100000, 999999);
                setcookie('mgw_dev_user_id', $devId, time() + 60 * 60 * 24 * 365, '/');
            }
            return $this->attachMgwIdentity([
                'id' => $devId,
                'first_name' => 'Тестовый игрок',
                'username' => 'test_' . preg_replace('/\D+/', '', $devId),
                'language_code' => 'ru',
                'is_dev_user' => true,
            ], (string)($payload['sessionId'] ?? ''));
        }

        throw new RuntimeException('Откройте приложение через Telegram.');
    }

    private function attachMgwIdentity(array $user, string $sessionId): array
    {
        return (new RuntimeAccountIdentityResolver($this->config))->attach($user, $sessionId);
    }

    private function browserDevUserAllowed(): bool
    {
        if (empty($this->config['allow_browser_dev_user'])) {
            return false;
        }

        // На публичном домене тестовых пользователей создавать нельзя:
        // один и тот же человек с телефона и браузера будет считаться разными игроками.
        // Dev-режим оставляем только для localhost или при явном force_browser_dev_user=true.
        if (!empty($this->config['force_browser_dev_user'])) {
            return true;
        }

        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_starts_with($host, 'localhost:')
            || str_starts_with($host, '127.0.0.1:');
    }

    private function validateTelegramInitData(string $initData): ?array
    {
        parse_str($initData, $data);
        if (empty($data['hash']) || empty($data['user'])) {
            return null;
        }

        $hash = (string)$data['hash'];
        unset($data['hash']);
        ksort($data);

        $checkParts = [];
        foreach ($data as $key => $value) {
            $checkParts[] = $key . '=' . $value;
        }
        $checkString = implode("\n", $checkParts);
        $secretKey = hash_hmac('sha256', (string)$this->config['bot_token'], 'WebAppData', true);
        $calculated = hash_hmac('sha256', $checkString, $secretKey);

        if (!hash_equals($calculated, $hash)) {
            return null;
        }

        // Telegram signs auth_date together with the rest of initData. A valid
        // signature alone is not enough: without an age check an old captured
        // initData string could be replayed indefinitely.
        $authDate = (int)($data['auth_date'] ?? 0);
        $maxAge = max(60, (int)($this->config['telegram_init_data_max_age_sec'] ?? 86400));
        $clockSkew = max(0, (int)($this->config['telegram_init_data_clock_skew_sec'] ?? 300));
        $now = time();

        if ($authDate <= 0
            || $authDate > $now + $clockSkew
            || $now - $authDate > $maxAge) {
            return null;
        }

        $user = json_decode((string)$data['user'], true);
        return is_array($user) ? $user : null;
    }
}
