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
        $accounts = new AccountIdentityService(
            $database,
            (int)($this->config['mgw_account_session_ttl_sec'] ?? 2592000)
        );
        $identity = $accounts->resolveTelegramUser($user, $sessionId);
        $user['mgw_id'] = $identity['mgw_id'];
        $user['mgw_identity_provider'] = $identity['provider'];

        // Visible game identity is MGW-owned. Carry the canonical nickname/avatar
        // alongside the verified provider identity so legacy JSON game/session
        // projections never need to fall back to Telegram name/username.
        $canonical = $accounts->findByIdentity(
            (string)$identity['provider'],
            (string)$identity['provider_subject']
        );
        if (is_array($canonical)) {
            $nickname = trim((string)($canonical['nickname'] ?? ''));
            $avatarItemId = trim((string)($canonical['equipped_avatar_item_id'] ?? ''));
            if ($nickname !== '') $user['mgw_nickname'] = $nickname;
            if ($avatarItemId !== '') $user['mgw_avatar_item_id'] = $avatarItemId;
        }

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
