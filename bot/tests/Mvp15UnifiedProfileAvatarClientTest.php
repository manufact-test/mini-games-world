<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'client' => $root . '/app/assets/js/api/client.js',
    'state' => $root . '/app/assets/js/state.js',
    'ui' => $root . '/app/assets/js/ui.js',
    'shell' => $root . '/app/assets/js/main-v110-handoff-shell.js',
    'profile' => $root . '/app/assets/js/screens/profile-screen-v110.js',
    'model' => $root . '/app/assets/js/profile/mgw-profile-model.js',
    'entry' => $root . '/app/v110.php',
    'manifest' => $root . '/bot/helpers/staging-e2e-runtime-files.txt',
];

$sources = [];
foreach ($files as $name => $path) {
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Unable to read {$name} source.");
    }
    $sources[$name] = $content;
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($sources['client'], 'mgwProfile: () => requestUrl(`${window.location.origin}/bot/profile.php`)'),
    'Visible client must expose the canonical /bot/profile.php owner.'
);
$assert(
    str_contains($sources['state'], 'mgwProfile: null'),
    'Client state must keep canonical MGW profile separate from legacy runtime user fields.'
);
$assert(
    str_contains($sources['shell'], 'const mgwProfileResult = await api.mgwProfile();'),
    'Boot must load the canonical MGW profile before visible identity rendering.'
);
$assert(
    str_contains($sources['shell'], 'state.user = applyCanonicalMgwProfile(result.user || {}, state.mgwProfile);'),
    'Boot must overlay canonical identity onto the compatibility runtime user.'
);
$assert(
    str_contains($sources['profile'], 'const canonicalResult = await api.mgwProfile();'),
    'Profile refresh must read canonical identity/avatar data.'
);
$assert(
    str_contains($sources['profile'], 'state.user = applyCanonicalMgwProfile(runtimeUser, state.mgwProfile);'),
    'Legacy profile refresh must not overwrite the canonical identity owner.'
);
$assert(
    str_contains($sources['model'], 'mgw_id: mgwId')
    && str_contains($sources['model'], 'display_name: displayName')
    && str_contains($sources['model'], 'photo_url: avatarUrl')
    && str_contains($sources['model'], 'registered_at: registeredAt'),
    'Canonical client model must own MGW id, visible name, avatar and registration date.'
);
$assert(
    str_contains($sources['model'], 'avatar.external_ref')
    && !str_contains($sources['model'], 'provider_subject')
    && !str_contains($sources['model'], 'telegram_id'),
    'Visible profile model must consume public avatar metadata without provider-private account keys.'
);
$assert(
    str_contains($sources['ui'], "const canonicalProfileLoaded = user?.mgw_profile_loaded === true;")
    && str_contains($sources['ui'], "const telegramPhotoUrl = canonicalProfileLoaded ? '' : currentTelegramPhotoUrl(telegramOwnerId);"),
    'Direct Telegram photo fallback must be disabled after the canonical MGW profile is loaded.'
);
$assert(
    str_contains($sources['ui'], 'user?.mgw_id || user?.id || user?.telegram_id'),
    'Avatar cache ownership must prefer the internal MGW id.'
);
$assert(
    str_contains($sources['entry'], 'X-MGW-Profile-API: provider-neutral-mgw-v1')
    && str_contains($sources['entry'], 'X-MGW-Profile-Consumer: unified-profile-avatar-v1'),
    'v110 publication identity must expose the canonical profile graph.'
);
foreach ([
    'app/assets/js/api/client.js',
    'app/assets/js/state.js',
    'app/assets/js/ui.js',
    'app/assets/js/screens/profile-screen-v110.js',
    'app/assets/js/profile/mgw-profile-model.js',
] as $runtimePath) {
    $assert(
        str_contains($sources['manifest'], $runtimePath),
        "Exact staging fingerprint must include {$runtimePath}."
    );
}

fwrite(STDOUT, "Mvp15UnifiedProfileAvatarClientTest: {$assertions} assertions passed\n");
