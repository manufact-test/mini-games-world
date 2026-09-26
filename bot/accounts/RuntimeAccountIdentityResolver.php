<?php
declare(strict_types=1);

final class RuntimeAccountIdentityResolver
{
    public function __construct(
        private array $config,
        private ?RuntimeStorageRouter $router = null,
        private ?DatabaseConnectionInterface $database = null
    ) {
        $this->router ??= new RuntimeStorageRouter($this->config);
    }

    public function attach(array $user, string $sessionId): array
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            return $user;
        }

        // Before the staged router is enabled, preserve the existing DB identity
        // behavior. Once enabled, accounts must be explicitly routed to DB.
        if ($this->router->enabled()
            && $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            return $user;
        }

        $database = $this->database ?? PdoConnectionFactory::create($databaseConfig);
        $initDataMaxAge = max(60, (int)($this->config['telegram_init_data_max_age_sec'] ?? 86400));
        $initDataClockSkew = max(0, (int)($this->config['telegram_init_data_clock_skew_sec'] ?? 300));
        $deletedIdentityBlockSec = max(
            300,
            (int)($this->config['account_deleted_identity_block_sec'] ?? ($initDataMaxAge + $initDataClockSkew))
        );
        $accounts = new AccountIdentityService(
            $database,
            (int)($this->config['mgw_account_session_ttl_sec'] ?? 2592000),
            (string)($this->config['bot_token'] ?? ''),
            $deletedIdentityBlockSec
        );
        $identity = $accounts->resolveTelegramUser($user, $sessionId);
        $user['mgw_id'] = $identity['mgw_id'];
        $user['mgw_identity_provider'] = $identity['provider'];

        if ($this->router->enabled()) {
            $ownership = (new RuntimeAccountOwnershipService($database))->ensure(
                (string)$identity['provider'],
                (string)$identity['provider_subject'],
                (string)$identity['mgw_id']
            );
            $user['mgw_account_ref'] = $ownership['account_ref'];
        }

        return $user;
    }
}
