<?php
declare(strict_types=1);

final class EconomyConfigSimulator
{
    public static function simulate(array $config): array
    {
        $match = $config['match'] ?? [];
        $ads = $config['rewarded_ads'] ?? [];
        $bonuses = $config['bonuses'] ?? [];

        $entryCost = (int)($match['entry_cost'] ?? 0);
        $pot = $entryCost * 2;
        $winnerReward = (int)($match['winner_reward'] ?? 0);
        $systemSink = (int)($match['system_sink'] ?? 0);
        $drawRefund = (int)($match['draw_refund'] ?? 0);
        $adReward = (int)($ads['reward'] ?? 0);
        $adLimit = (int)($ads['daily_limit'] ?? 0);
        $adCap = (int)($ads['daily_coin_cap'] ?? 0);
        $adRequestedDaily = $adReward * $adLimit;

        return [
            'normal_match' => [
                'player_source' => $pot,
                'winner_reward' => $winnerReward,
                'system_sink' => $systemSink,
                'balanced' => $pot === ($winnerReward + $systemSink),
            ],
            'draw' => [
                'player_source' => $pot,
                'refund_total' => $drawRefund * 2,
                'balanced' => $pot === ($drawRefund * 2),
            ],
            'rewarded_ads' => [
                'reward_per_ad' => $adReward,
                'daily_limit' => $adLimit,
                'requested_daily_source' => $adRequestedDaily,
                'daily_coin_cap' => $adCap,
                'effective_daily_source' => min($adRequestedDaily, $adCap),
            ],
            'first_game_bonus' => [
                'per_game' => (int)($bonuses['first_game'] ?? 0),
                'games' => 8,
                'all_games_source' => (int)($bonuses['first_game'] ?? 0) * 8,
            ],
        ];
    }

    public static function assertSafe(array $config): void
    {
        $simulation = self::simulate($config);
        if (($simulation['normal_match']['balanced'] ?? false) !== true) {
            throw new InvalidArgumentException('Normal-match economy does not conserve the two-player entry pot.');
        }
        if (($simulation['draw']['balanced'] ?? false) !== true) {
            throw new InvalidArgumentException('Draw economy does not refund the two-player entry pot exactly.');
        }

        $ads = $simulation['rewarded_ads'];
        if ((int)$ads['requested_daily_source'] > 0 && (int)$ads['daily_coin_cap'] <= 0) {
            throw new InvalidArgumentException('Rewarded ads require a positive daily coin cap when rewards are enabled.');
        }
    }
}
