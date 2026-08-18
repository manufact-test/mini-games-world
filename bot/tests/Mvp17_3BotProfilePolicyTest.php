<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/BotProfilePolicy.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$policy = new BotProfilePolicy();
$stored = [
    'id' => 'game_demo',
    'is_bot_game' => true,
    'bot_id' => 'bot_demo',
    'bot_name' => 'Mia',
    'bot_difficulty' => 'hard',
    'player_ids' => ['user_1', 'bot_demo'],
    'player_names' => ['user_1' => 'Player', 'bot_demo' => 'Mia'],
];

$policy->ensureStoredProfile($stored);
$profile = $stored['player_profiles']['bot_demo'] ?? null;
$assert(is_array($profile), 'Stored bot match must receive a presentation profile.');
$assert(($profile['display_name'] ?? '') === 'Mia', 'Presentation profile must retain the invented public name.');
$assert(is_array($profile['avatar'] ?? null) && ($profile['avatar']['label'] ?? '') === 'M', 'Presentation profile must include an invented avatar.');
$assert(is_array($profile['cosmetics'] ?? null) && ($profile['cosmetics']['frame'] ?? '') !== '', 'Presentation profile must include cosmetic presentation data.');
$assert(!empty($stored['is_bot_game']) && ($stored['bot_difficulty'] ?? '') === 'hard', 'Stored analytics/economy markers must remain intact.');

$public = [
    'id' => 'game_demo',
    'players' => [
        ['id' => 'user_1', 'name' => 'Player'],
        ['id' => 'bot_demo', 'name' => 'Mia'],
    ],
    'is_bot_game' => true,
    'bot_difficulty' => 'hard',
    'bot_id' => 'bot_demo',
    'bot_name' => 'Mia',
];
$sanitized = $policy->sanitizePublicGame($public, $stored);

foreach (['is_bot_game', 'bot_difficulty', 'bot_id', 'bot_name', 'bot_profile'] as $technicalKey) {
    $assert(!array_key_exists($technicalKey, $sanitized), "Public game must not expose {$technicalKey}.");
}
$opponent = $sanitized['players'][1] ?? null;
$assert(is_array($opponent) && ($opponent['name'] ?? '') === 'Mia', 'Public opponent must keep an ordinary invented name.');
$assert(is_array($opponent['avatar'] ?? null) && ($opponent['avatar']['variant'] ?? '') !== '', 'Public opponent must receive avatar presentation data.');
$assert(is_array($opponent['cosmetics'] ?? null) && ($opponent['cosmetics']['accent'] ?? '') !== '', 'Public opponent must receive cosmetic presentation data.');
$assert(!empty($stored['is_bot_game']) && ($stored['bot_difficulty'] ?? '') === 'hard', 'Sanitization must not mutate stored server markers.');

fwrite(STDOUT, "Mvp17_3BotProfilePolicyTest: {$assertions} assertions passed\n");
