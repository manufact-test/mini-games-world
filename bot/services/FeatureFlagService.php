<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/database/DatabaseConfig.php';
require_once dirname(__DIR__) . '/storage/RuntimeStorageRouter.php';

final class FeatureFlagService
{
    public const BUILD = 'v97-mvp14-staging-operations-runner';

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
            : 'Идут технические работы. Активные партии можно завершить, новые матчи временно недоступны.';
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
        if (!$this->featureEnabled('matchmaking')) return 'Подбор соперников временно отключён.';
        if ($this->financialReadOnly()) {
            return 'Новые матчи временно недоступны. Уже начатые партии можно завершить.';
        }
        if ($gameType !== null && $gameType !== '' && !$this->gameEnabled($gameType)) {
            return 'Эта игра временно недоступна. Выберите другую игру.';
        }
        return null;
    }

    public function invitationBlockReason(?string $gameType = null): ?string
    {
        if (!$this->featureEnabled('invitations')) return 'Приглашения временно отключены.';
        return $this->newMatchBlockReason($gameType);
    }

    public function paymentBlockReason(): ?string
    {
        if ($this->maintenanceEnabled()) return $this->maintenanceMessage();
        if ($this->financialReadOnly()) return 'Финансовые операции временно переведены в режим только для чтения.';
        if (!$this->featureEnabled('payments')) return 'Пополнение временно отключено.';
        return null;
    }

    public function shopBlockReason(): ?string
    {
        if ($this->maintenanceEnabled()) return $this->maintenanceMessage();
        if ($this->financialReadOnly()) return 'Финансовые операции временно переведены в режим только для чтения.';
        if (!$this->featureEnabled('shop')) return 'Оформление заказов временно отключено.';
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
        if ($this->maintenanceEnabled()) $alerts[] = 'Включён режим технических работ.';
        if ($this->financialReadOnly()) $alerts[] = 'Финансовые операции работают только на чтение.';

        foreach (self::FEATURE_DEFAULTS as $feature => $default) {
            if ($default && !$this->featureEnabled($feature)) {
                $alerts[] = 'Отключена функция: ' . $feature . '.';
            }
        }
        foreach (self::GAME_IDS as $gameType) {
            if (!$this->gameEnabled($gameType)) $alerts[] = 'Отключена игра: ' . $gameType . '.';
        }

        $databaseRuntime = new RuntimeStorageRouter($this->config);
        foreach ($databaseRuntime->enabledModules() as $module) {
            $alerts[] = 'Тестовый DB runtime включён для модуля: ' . $module . '.';
        }

        return $alerts;
    }

    public function activeGameActionsAllowed(): bool
    {
        return true;
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
