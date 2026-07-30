<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Auth;

final readonly class TelegramInitDataVerifier
{
    /** @var \Closure():int */
    private \Closure $clock;

    /** @param null|callable():int $clock */
    public function __construct(
        private string $botToken,
        private int $maxAgeSec,
        private int $clockSkewSec,
        ?callable $clock = null,
    ) {
        if ($this->maxAgeSec < 60) {
            throw new \InvalidArgumentException('Telegram initData max age must be at least 60 seconds.');
        }
        if ($this->clockSkewSec < 0) {
            throw new \InvalidArgumentException('Telegram initData clock skew cannot be negative.');
        }
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): int => time();
    }

    public function verify(string $initData): AuthenticatedIdentity
    {
        if (trim($this->botToken) === '') {
            throw new \RuntimeException('Clean Telegram authentication is not configured.');
        }

        $data = $this->parseUniqueQuery($initData);
        $hash = strtolower(trim((string)($data['hash'] ?? '')));
        $userJson = (string)($data['user'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || $userJson === '') {
            throw new \RuntimeException('Не удалось подтвердить запуск через Telegram.');
        }

        unset($data['hash']);
        ksort($data, SORT_STRING);
        $parts = [];
        foreach ($data as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $checkString = implode("\n", $parts);
        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $checkString, $secretKey);
        if (!hash_equals($calculated, $hash)) {
            throw new \RuntimeException('Не удалось подтвердить запуск через Telegram.');
        }

        $authDate = (int)($data['auth_date'] ?? 0);
        $now = ($this->clock)();
        if ($authDate <= 0
            || $authDate > $now + $this->clockSkewSec
            || $now - $authDate > $this->maxAgeSec) {
            throw new \RuntimeException('Срок подтверждения запуска через Telegram истёк. Откройте приложение заново.');
        }

        $user = json_decode($userJson, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($user)) {
            throw new \RuntimeException('Не удалось прочитать профиль Telegram.');
        }

        $telegramId = trim((string)($user['id'] ?? ''));
        if (!preg_match('/^[0-9]{1,24}$/', $telegramId)) {
            throw new \RuntimeException('Не удалось определить пользователя Telegram.');
        }

        return new AuthenticatedIdentity(
            accountId: 'tg_' . $telegramId,
            method: 'telegram',
            telegramId: $telegramId,
            firstName: $this->bounded($user['first_name'] ?? '', 80),
            lastName: $this->bounded($user['last_name'] ?? '', 80),
            username: $this->bounded($user['username'] ?? '', 64),
            languageCode: $this->bounded($user['language_code'] ?? '', 16),
        );
    }

    /** @return array<string,string> */
    private function parseUniqueQuery(string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 16384) {
            throw new \RuntimeException('Не удалось подтвердить запуск через Telegram.');
        }

        $result = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') continue;
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($rawKey);
            $value = urldecode($rawValue);
            if ($key === '' || array_key_exists($key, $result)) {
                throw new \RuntimeException('Не удалось подтвердить запуск через Telegram.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function bounded(mixed $value, int $limit): string
    {
        return substr(trim((string)$value), 0, $limit);
    }
}
