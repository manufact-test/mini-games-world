<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Auth;

use Mgw\CleanRuntime\Server\RuntimeConfig;

final readonly class RuntimeAuthenticationService
{
    public function __construct(
        private RuntimeConfig $config,
        private TelegramInitDataVerifier $telegramVerifier,
    ) {}

    /** @param array<string,mixed> $payload */
    public function authenticate(array $payload, string $installationId): AuthenticatedIdentity
    {
        $initData = trim((string)($payload['init_data'] ?? ''));
        if ($initData !== '') {
            return $this->telegramVerifier->verify($initData);
        }

        $launch = is_array($payload['launch'] ?? null) ? $payload['launch'] : [];
        if ((bool)($launch['telegram_available'] ?? false)) {
            throw new AuthenticationException('Telegram не передал данные авторизации. Закройте приложение и откройте заново.');
        }

        if (!$this->config->allowBrowserStagingIdentity) {
            throw new AuthenticationException('Откройте clean staging через Telegram.');
        }

        $installationId = trim($installationId);
        if (!preg_match('/^[a-zA-Z0-9_-]{20,80}$/', $installationId)) {
            throw new \InvalidArgumentException('Invalid clean runtime installation identifier.');
        }

        return new AuthenticatedIdentity(
            accountId: 'stg_' . substr(hash('sha256', $installationId), 0, 32),
            method: 'browser_staging',
            telegramId: null,
            firstName: 'Staging player',
            lastName: '',
            username: '',
            languageCode: 'ru',
        );
    }
}
