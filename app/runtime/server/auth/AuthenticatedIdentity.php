<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Auth;

final readonly class AuthenticatedIdentity
{
    public function __construct(
        public string $accountId,
        public string $method,
        public ?string $telegramId,
        public string $firstName,
        public string $lastName,
        public string $username,
        public string $languageCode,
    ) {
        if (!preg_match('/^[a-zA-Z0-9_-]{6,96}$/', $this->accountId)) {
            throw new \InvalidArgumentException('Invalid clean runtime account identifier.');
        }
        if (!in_array($this->method, ['telegram', 'browser_staging'], true)) {
            throw new \InvalidArgumentException('Invalid clean runtime authentication method.');
        }
    }

    /** @return array<string,mixed> */
    public function toRecord(): array
    {
        return [
            'id' => $this->accountId,
            'auth_method' => $this->method,
            'telegram_id' => $this->telegramId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'username' => $this->username,
            'language_code' => $this->languageCode,
        ];
    }
}
