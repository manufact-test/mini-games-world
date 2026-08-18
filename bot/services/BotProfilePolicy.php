<?php
declare(strict_types=1);

/**
 * Owns presentation-only opponent identity for automated matches.
 *
 * Analytics/economy markers remain on the stored game. Public projections get
 * only ordinary player presentation data and never the automation marker or
 * difficulty value from this policy.
 */
final class BotProfilePolicy
{
    private const AVATAR_VARIANTS = ['ember', 'mint', 'violet', 'sky', 'sand', 'rose'];
    private const FRAME_VARIANTS = ['steel', 'bronze', 'onyx', 'ivory'];
    private const ACCENT_VARIANTS = ['arc', 'nova', 'orbit', 'pulse'];

    public function ensureStoredProfile(array &$game): void
    {
        if (empty($game['is_bot_game'])) return;

        $botId = trim((string)($game['bot_id'] ?? ''));
        if ($botId === '') {
            foreach ($game['player_ids'] ?? [] as $playerId) {
                $candidate = (string)$playerId;
                if (str_starts_with($candidate, 'bot_')) {
                    $botId = $candidate;
                    break;
                }
            }
        }
        if ($botId === '') return;

        $name = trim((string)($game['player_names'][$botId] ?? $game['bot_name'] ?? ''));
        if ($name === '') $name = 'Alex';

        $seedKey = $botId . '|' . (string)($game['id'] ?? '');
        $seed = (int)sprintf('%u', crc32($seedKey));
        $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
        $initial = strtoupper((string)$initial);

        if (!isset($game['player_profiles']) || !is_array($game['player_profiles'])) {
            $game['player_profiles'] = [];
        }

        $game['player_profiles'][$botId] = [
            'display_name' => $name,
            'avatar' => [
                'kind' => 'generated',
                'label' => $initial,
                'variant' => self::AVATAR_VARIANTS[$seed % count(self::AVATAR_VARIANTS)],
            ],
            'cosmetics' => [
                'frame' => self::FRAME_VARIANTS[$seed % count(self::FRAME_VARIANTS)],
                'accent' => self::ACCENT_VARIANTS[intdiv($seed, 7) % count(self::ACCENT_VARIANTS)],
            ],
        ];
    }

    public function sanitizePublicGame(array $public, array $storedGame): array
    {
        unset(
            $public['is_bot_game'],
            $public['bot_difficulty'],
            $public['bot_id'],
            $public['bot_name'],
            $public['bot_profile']
        );

        if (empty($storedGame['is_bot_game'])) return $public;

        $copy = $storedGame;
        $this->ensureStoredProfile($copy);
        $botId = trim((string)($copy['bot_id'] ?? ''));
        $profile = $botId !== '' && is_array($copy['player_profiles'][$botId] ?? null)
            ? $copy['player_profiles'][$botId]
            : null;

        if ($profile === null || !isset($public['players']) || !is_array($public['players'])) {
            return $public;
        }

        foreach ($public['players'] as &$player) {
            if (!is_array($player) || (string)($player['id'] ?? '') !== $botId) continue;
            $player['name'] = (string)($profile['display_name'] ?? $player['name'] ?? 'Игрок');
            $player['avatar'] = is_array($profile['avatar'] ?? null) ? $profile['avatar'] : [];
            $player['cosmetics'] = is_array($profile['cosmetics'] ?? null) ? $profile['cosmetics'] : [];
            break;
        }
        unset($player);

        return $public;
    }
}
