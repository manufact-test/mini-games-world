<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$profile = $read('app/assets/js/screens/profile-screen-v110.js');
$ru = $read('app/locales/ru.json');
$manifest = $read('app/runtime/client/version-manifest.php');

$assert(
    !str_contains($profile, 'profile-v2-achievements')
        && !str_contains($profile, "profile.achievements_title")
        && !str_contains($profile, "profile.achievement_soon"),
    'Profile must not render fake future-achievement cards.'
);
$assert(
    !str_contains($ru, '"achievements_note":"Места под будущие достижения и награды."')
        && !str_contains($ru, '"achievement_soon":"Скоро"'),
    'RU catalog must not retain unfinished future-achievement copy.'
);
$assert(
    str_contains($profile, 'tournamentVisiblePermanentRewards')
        && str_contains($profile, 'const achievements = permanent.filter')
        && str_contains($profile, 'Достижения'),
    'Real tournament achievement/reward presentation must remain intact.'
);

$assert(
    str_contains($manifest, "profile-screen-v110.js?v=1133&mvp25_1=real-achievements-only-v1"),
    'Changed Profile source must publish a fresh cache identity so cached placeholder code cannot execute against the new locale catalog.'
);

fwrite(STDOUT, "Mvp25_1ProfilePlaceholderCleanupContractTest: {$assertions} assertions passed\n");
