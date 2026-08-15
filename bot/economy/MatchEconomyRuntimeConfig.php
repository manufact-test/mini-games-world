<?php
declare(strict_types=1);

require_once __DIR__ . '/EconomyConfigSimulator.php';
require_once __DIR__ . '/EconomyConfigDefinition.php';
require_once __DIR__ . '/EconomyConfigService.php';

final class MatchEconomyRuntimeConfig
{
    public static function apply(array $applicationConfig): array
    {
        $snapshot = self::snapshot($applicationConfig);
        $match = $snapshot['config']['match'] ?? null;
        if (!is_array($match)) {
            throw new RuntimeException('Canonical normal-match economy config is missing.');
        }

        $entry = (int)($match['entry_cost'] ?? 0);
        $winner = (int)($match['winner_reward'] ?? 0);
        $sink = (int)($match['system_sink'] ?? 0);
        $draw = (int)($match['draw_refund'] ?? 0);
        $pot = $entry * 2;

        if ($entry <= 0 || $winner < 0 || $sink < 0 || $draw < 0) {
            throw new RuntimeException('Canonical normal-match economy contains invalid runtime values.');
        }
        if ($winner + $sink !== $pot || ($draw * 2) !== $pot) {
            throw new RuntimeException('Canonical normal-match economy is not balanced.');
        }

        // Compatibility projection only. EconomyConfigService remains the sole
        // source of truth; legacy match executors receive its current values.
        $applicationConfig['match_bet'] = $entry;
        $applicationConfig['commission_rate'] = $pot > 0 ? ($sink / $pot) : 0.0;
        $applicationConfig['canonical_match_economy'] = [
            'entry_cost' => $entry,
            'winner_reward' => $winner,
            'system_sink' => $sink,
            'draw_refund' => $draw,
            'config_version' => (int)($snapshot['version'] ?? 0),
            'config_sha256' => (string)($snapshot['config_sha256'] ?? ''),
        ];

        return $applicationConfig;
    }

    public static function publicStatus(array $applicationConfig): array
    {
        $match = $applicationConfig['canonical_match_economy'] ?? null;
        if (!is_array($match)) {
            throw new RuntimeException('Canonical normal-match runtime policy is unavailable.');
        }
        return $match;
    }

    private static function snapshot(array $applicationConfig): array
    {
        $injected = $applicationConfig['canonical_economy_snapshot'] ?? null;
        if (is_array($injected)) {
            $environment = strtolower(trim((string)($applicationConfig['environment'] ?? 'local')));
            if ($environment !== 'local' && empty($applicationConfig['allow_economy_defaults_for_tests'])) {
                throw new RuntimeException('Injected economy snapshot is test-only.');
            }
            $candidate = $injected['config'] ?? null;
            if (!is_array($candidate)) {
                throw new RuntimeException('Injected canonical economy snapshot is invalid.');
            }
            $normalized = EconomyConfigDefinition::normalize($candidate);
            return [
                'version' => (int)($injected['version'] ?? 1),
                'config_sha256' => EconomyConfigDefinition::sha256($normalized),
                'config' => $normalized,
            ];
        }

        if (!class_exists('DatabaseConfig') || !class_exists('PdoConnectionFactory')) {
            throw new RuntimeException('Canonical economy database dependencies are unavailable.');
        }
        $databaseConfig = DatabaseConfig::fromApplicationConfig($applicationConfig);
        if ($databaseConfig->enabled()) {
            return (new EconomyConfigService(PdoConnectionFactory::create($databaseConfig)))->current();
        }

        $environment = strtolower(trim((string)($applicationConfig['environment'] ?? 'production')));
        if ($environment === 'local' || !empty($applicationConfig['allow_economy_defaults_for_tests'])) {
            $defaults = EconomyConfigDefinition::defaults();
            return [
                'version' => 0,
                'config_sha256' => EconomyConfigDefinition::sha256($defaults),
                'config' => $defaults,
            ];
        }

        throw new RuntimeException('Canonical normal-match economy requires an enabled database.');
    }
}
