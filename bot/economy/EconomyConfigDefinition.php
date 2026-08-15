<?php
declare(strict_types=1);

final class EconomyConfigDefinition
{
    public const SCHEMA_VERSION = 1;

    /**
     * One canonical server-side economy configuration. These values are the
     * approved roadmap defaults; later MVPs consume them instead of creating
     * another client/runtime constant owner.
     */
    public static function defaults(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'match' => [
                'entry_cost' => 100,
                'winner_reward' => 180,
                'system_sink' => 20,
                'draw_refund' => 100,
            ],
            'bonuses' => [
                'starter' => 1000,
                'weekly' => 500,
                'weekly_match_threshold' => 3,
                'first_game' => 50,
            ],
            'rewarded_ads' => [
                'reward' => 25,
                'daily_limit' => 12,
                'cooldown_seconds' => 60,
                'daily_coin_cap' => 300,
            ],
            'coin_packages' => [
                ['id' => 'coins_5000', 'coins' => 5000, 'price_eur_cents' => 499, 'enabled' => true],
                ['id' => 'coins_10500', 'coins' => 10500, 'price_eur_cents' => 999, 'enabled' => true],
                ['id' => 'coins_27500', 'coins' => 27500, 'price_eur_cents' => 2499, 'enabled' => true],
                ['id' => 'coins_57500', 'coins' => 57500, 'price_eur_cents' => 4999, 'enabled' => true],
                ['id' => 'coins_120000', 'coins' => 120000, 'price_eur_cents' => 9999, 'enabled' => true],
            ],
        ];
    }

    public static function normalize(array $candidate): array
    {
        self::assertExactKeys($candidate, ['schema_version', 'match', 'bonuses', 'rewarded_ads', 'coin_packages'], 'config');
        if (($candidate['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported economy config schema version.');
        }

        $match = self::object($candidate['match'] ?? null, 'match');
        self::assertExactKeys($match, ['entry_cost', 'winner_reward', 'system_sink', 'draw_refund'], 'match');

        $bonuses = self::object($candidate['bonuses'] ?? null, 'bonuses');
        self::assertExactKeys($bonuses, ['starter', 'weekly', 'weekly_match_threshold', 'first_game'], 'bonuses');

        $ads = self::object($candidate['rewarded_ads'] ?? null, 'rewarded_ads');
        self::assertExactKeys($ads, ['reward', 'daily_limit', 'cooldown_seconds', 'daily_coin_cap'], 'rewarded_ads');

        $packages = $candidate['coin_packages'] ?? null;
        if (!is_array($packages) || !array_is_list($packages) || count($packages) !== 5) {
            throw new InvalidArgumentException('coin_packages must contain the five canonical package slots.');
        }

        $normalizedPackages = [];
        $seenIds = [];
        foreach ($packages as $index => $package) {
            $package = self::object($package, 'coin_packages[' . $index . ']');
            self::assertExactKeys($package, ['id', 'coins', 'price_eur_cents', 'enabled'], 'coin_packages[' . $index . ']');
            $id = trim((string)($package['id'] ?? ''));
            if (!preg_match('/^coins_[1-9][0-9]{2,8}$/', $id)) {
                throw new InvalidArgumentException('Invalid coin package id.');
            }
            if (isset($seenIds[$id])) {
                throw new InvalidArgumentException('Duplicate coin package id.');
            }
            $seenIds[$id] = true;
            if (!is_bool($package['enabled'] ?? null)) {
                throw new InvalidArgumentException('Coin package enabled must be boolean.');
            }
            $normalizedPackages[] = [
                'id' => $id,
                'coins' => self::integer($package['coins'] ?? null, 1, 100000000, 'coin package coins'),
                'price_eur_cents' => self::integer($package['price_eur_cents'] ?? null, 1, 10000000, 'coin package EUR price'),
                'enabled' => $package['enabled'],
            ];
        }

        $expectedIds = array_column(self::defaults()['coin_packages'], 'id');
        if (array_column($normalizedPackages, 'id') !== $expectedIds) {
            throw new InvalidArgumentException('Coin package slots or ordering differ from the canonical catalogue.');
        }

        $normalized = [
            'schema_version' => self::SCHEMA_VERSION,
            'match' => [
                'entry_cost' => self::integer($match['entry_cost'] ?? null, 0, 10000000, 'match entry cost'),
                'winner_reward' => self::integer($match['winner_reward'] ?? null, 0, 20000000, 'winner reward'),
                'system_sink' => self::integer($match['system_sink'] ?? null, 0, 20000000, 'system sink'),
                'draw_refund' => self::integer($match['draw_refund'] ?? null, 0, 10000000, 'draw refund'),
            ],
            'bonuses' => [
                'starter' => self::integer($bonuses['starter'] ?? null, 0, 10000000, 'starter bonus'),
                'weekly' => self::integer($bonuses['weekly'] ?? null, 0, 10000000, 'weekly bonus'),
                'weekly_match_threshold' => self::integer($bonuses['weekly_match_threshold'] ?? null, 1, 1000, 'weekly match threshold'),
                'first_game' => self::integer($bonuses['first_game'] ?? null, 0, 10000000, 'first-game bonus'),
            ],
            'rewarded_ads' => [
                'reward' => self::integer($ads['reward'] ?? null, 0, 10000000, 'rewarded-ad reward'),
                'daily_limit' => self::integer($ads['daily_limit'] ?? null, 0, 1000, 'rewarded-ad daily limit'),
                'cooldown_seconds' => self::integer($ads['cooldown_seconds'] ?? null, 0, 86400, 'rewarded-ad cooldown'),
                'daily_coin_cap' => self::integer($ads['daily_coin_cap'] ?? null, 0, 100000000, 'rewarded-ad daily coin cap'),
            ],
            'coin_packages' => $normalizedPackages,
        ];

        EconomyConfigSimulator::assertSafe($normalized);
        return $normalized;
    }

    public static function canonicalJson(array $config): string
    {
        return json_encode(self::normalize($config), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function sha256(array $config): string
    {
        return hash('sha256', self::canonicalJson($config));
    }

    private static function object(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException($name . ' must be an object.');
        }
        return $value;
    }

    private static function integer(mixed $value, int $min, int $max, string $name): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Invalid ' . $name . '.');
        }
        return $value;
    }

    private static function assertExactKeys(array $value, array $expected, string $name): void
    {
        $keys = array_keys($value);
        sort($keys);
        $expectedKeys = $expected;
        sort($expectedKeys);
        if ($keys !== $expectedKeys) {
            throw new InvalidArgumentException($name . ' contains missing or unknown fields.');
        }
    }
}
