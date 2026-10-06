<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/DatabaseConfig.php';
require_once dirname(__DIR__) . '/storage/RuntimeStorageRouter.php';
require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

final class FeatureFlagService
{
    public const BUILD = 'v102-mvp14-production-preflight';

    private const GAME_IDS = [
        'tictactoe',
        'four_in_a_row',
        'battleship',
        'checkers',
        'reversi',
        'chess',
        'go',
        'domino',
    ];

    private const FEATURE_DEFAULTS = [
        'matchmaking' => true,
        'invitations' => true,
        'payments' => true,
        'shop' => true,
        'tournaments' => false,
        'ads' => false,
    ];

    public function __construct(private array $config) {}

    public function maintenanceEnabled(): bool
    {
        return $this->boolValue($this->flags()['maintenance_mode'] ?? false, false);
    }

    public function maintenanceMessage(): string
    {
        $message = trim((string)($this->flags()['maintenance_message'] ?? ''));
        return $message !== ''
            ? $message
            : ServerLocalization::copy('runtime_status.maintenance_default_full', 'Maintenance is in progress. Mini Games World will be back soon.');
    }

    public function financialReadOnly(): bool
    {
        return $this->boolValue($this->flags()['financial_read_only'] ?? false, false);
    }

    public function featureEnabled(string $feature): bool
    {
        $default = self::FEATURE_DEFAULTS[$feature] ?? false;
        $features = $this->flags()['features'] ?? [];
        if (!is_array($features) || !array_key_exists($feature, $features)) return $default;
        return $this->boolValue($features[$feature], $default);
    }

    public function gameEnabled(string $gameType): bool
    {
        if (!in_array($gameType, self::GAME_IDS, true)) return false;
        $games = $this->flags()['games'] ?? [];
        if (!is_array($games) || !array_key_exists($gameType, $games)) return true;
        return $this->boolValue($games[$gameType], true);
    }

    public function newMatchBlockReason(?string $gameType = null): ?string
    {
        if ($this->maintenanceEnabled()) return $this->maintenanceMessage();
        if (!$this->featureEnabled('matchmaking')) return ServerLocalization::copy('runtime_status.matchmaking_disabled', 'Matchmaking is temporarily disabled.');
        if ($this->financialReadOnly()) {
            return ServerLocalization::copy('runtime_status.new_matches_read_only_full', 'New matches are temporarily unavailable. Active matches can be completed.');
        }
        if ($gameType !== null && $gameType !== '' && !$this->gameEnabled($gameType)) {
            return ServerLocalization::copy('runtime_status.game_unavailable', 'This game is temporarily unavailable. Choose another game.');
        }
        return null;
    }

    public function invitationBlockReason(?string $gameType = null): ?string
    {
        if (!$this->featureEnabled('invitations')) return ServerLocalization::copy('runtime_status.invitations_disabled', 'Invitations are temporarily disabled.');
        return $this->newMatchBlockReason($gameType);
    }

    public function paymentBlockReason(): ?string
    {
        if ($this->maintenanceEnabled()) return $this->maintenanceMessage();
        if ($this->financialReadOnly()) return ServerLocalization::copy('runtime_status.finance_read_only_full', 'Financial operations are temporarily read-only.');
        if (!$this->featureEnabled('payments')) return ServerLocalization::copy('runtime_status.payments_disabled', 'Top-ups are temporarily disabled.');
        return null;
    }

    public function shopBlockReason(): ?string
    {
        if ($this->maintenanceEnabled()) return $this->maintenanceMessage();
        if ($this->financialReadOnly()) return ServerLocalization::copy('runtime_status.finance_read_only_full', 'Financial operations are temporarily read-only.');
        if (!$this->featureEnabled('shop')) return ServerLocalization::copy('runtime_status.shop_disabled', 'Order placement is temporarily disabled.');
        return null;
    }

    public function assertNewMatchAllowed(?string $gameType = null): void
    {
        $reason = $this->newMatchBlockReason($gameType);
        if ($reason !== null) throw new RuntimeException($reason);
    }

    public function assertInvitationAllowed(?string $gameType = null): void
    {
        $reason = $this->invitationBlockReason($gameType);
        if ($reason !== null) throw new RuntimeException($reason);
    }

    public function publicStatus(): array
    {
        $games = [];
        foreach (self::GAME_IDS as $gameType) $games[$gameType] = $this->gameEnabled($gameType);

        $features = [];
        foreach (array_keys(self::FEATURE_DEFAULTS) as $feature) {
            $features[$feature] = $this->featureEnabled($feature);
        }

        return [
            'build' => self::BUILD,
            'environment' => (string)($this->config['environment'] ?? 'production'),
            'maintenance' => [
                'enabled' => $this->maintenanceEnabled(),
                'message' => $this->maintenanceEnabled() ? $this->maintenanceMessage() : '',
            ],
            'financial_read_only' => $this->financialReadOnly(),
            'features' => $features,
            'games' => $games,
            'database_runtime' => (new RuntimeStorageRouter($this->config))->publicStatus(),
            'alerts' => $this->adminAlerts(),
        ];
    }

    public function adminAlerts(): array
    {
        $alerts = [];
        if ($this->maintenanceEnabled()) $alerts[] = ServerLocalization::copy('server.feature_flags.admin.maintenance', 'Maintenance mode is enabled.');
        if ($this->financialReadOnly()) $alerts[] = ServerLocalization::copy('server.feature_flags.admin.finance_read_only', 'Financial operations are read-only.');

        foreach (self::FEATURE_DEFAULTS as $feature => $default) {
            if ($default && !$this->featureEnabled($feature)) {
                $alerts[] = ServerLocalization::copy('server.feature_flags.admin.feature_disabled', 'Feature disabled: {feature}.', ['feature'=>$feature]);
            }
        }
        foreach (self::GAME_IDS as $gameType) {
            if (!$this->gameEnabled($gameType)) $alerts[] = ServerLocalization::copy('server.feature_flags.admin.game_disabled', 'Game disabled: {game}.', ['game'=>$gameType]);
        }

        $databaseRuntime = new RuntimeStorageRouter($this->config);
        foreach ($databaseRuntime->enabledModules() as $module) {
            $alerts[] = ServerLocalization::copy('server.feature_flags.admin.db_runtime_enabled', 'Test DB runtime is enabled for module: {module}.', ['module'=>$module]);
        }

        return $alerts;
    }

    public function activeGameActionsAllowed(): bool
    {
        return !$this->maintenanceEnabled();
    }

    private function flags(): array
    {
        $flags = $this->config['feature_flags'] ?? [];
        return is_array($flags) ? $flags : [];
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) return true;
            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) return false;
        }
        return $default;
    }
}
