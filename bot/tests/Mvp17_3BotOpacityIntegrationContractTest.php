<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtime = file_get_contents($root . '/services/GameRuntimeService.php');
$history = file_get_contents($root . '/services/HistoryService.php');
$gameService = file_get_contents($root . '/services/GameService.php');
$profiles = file_get_contents($root . '/services/BotProfilePolicy.php');

if (!is_string($runtime) || !is_string($history) || !is_string($gameService) || !is_string($profiles)) {
    throw new RuntimeException('MVP-17.3 runtime sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($runtime, "require_once __DIR__ . '/BotProfilePolicy.php';")
        && str_contains($runtime, '$this->botProfiles->ensureStoredProfile')
        && str_contains($runtime, '$this->botProfiles->sanitizePublicGame'),
    'GameRuntimeService must own one centralized bot presentation boundary.'
);
$assert(
    str_contains($runtime, "['easy' => 1, 'medium' => 69, 'hard' => 30]")
        && !str_contains($runtime, "['easy' => 8, 'medium' => 62, 'hard' => 30]")
        && !str_contains($runtime, "['easy' => 5, 'medium' => 65, 'hard' => 30]"),
    'Easy selection must be reduced to the agreed technical one-percent floor.'
);
$assert(
    str_contains($runtime, "['medium' => 20, 'hard' => 80]")
        && str_contains($runtime, "['medium' => 45, 'hard' => 55]"),
    'Adaptive Medium/Hard escalation must remain intact.'
);
$assert(
    !str_contains($history, "'is_bot_game' =>")
        && !str_contains($history, "'bot_difficulty' =>"),
    'User history projection must not disclose bot type or difficulty.'
);
$assert(
    str_contains($gameService, "'is_bot_game' => true")
        && str_contains($gameService, "'bot_difficulty' => \$botProfile['difficulty']"),
    'Stored game/transaction owner must retain internal bot markers for analytics and economy.'
);
$assert(
    str_contains($profiles, "unset(\n            \$public['is_bot_game']")
        && str_contains($profiles, "\$player['avatar']")
        && str_contains($profiles, "\$player['cosmetics']"),
    'Public projection must remove technical markers and expose ordinary avatar/cosmetics presentation data.'
);
$assert(
    !str_contains($profiles, "'rating'") && !str_contains($profiles, "'tournament'"),
    'Bot presentation policy must not create rating or tournament identity.'
);

fwrite(STDOUT, "Mvp17_3BotOpacityIntegrationContractTest: {$assertions} assertions passed\n");
