<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$client = file_get_contents($root . '/app/assets/js/api/client.js');
$profile = file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$ui = file_get_contents($root . '/app/assets/js/ui.js');
$v110 = file_get_contents($root . '/app/v110.php');
$manifest = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');

foreach ([
    'api client' => $client,
    'profile screen' => $profile,
    'shared ui' => $ui,
    'v110 entrypoint' => $v110,
    'staging fingerprint manifest' => $manifest,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('MVP-15.2 source unavailable: ' . $label);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($client, 'mgwProfile: () => requestUrl(`${window.location.origin}/bot/profile.php`)')
        && str_contains($client, "profile: () => request('profile')"),
    'Client must expose the canonical MGW profile endpoint while retaining legacy profile only for transitional stats/economy compatibility.'
);

$assert(
    str_contains($profile, 'api.mgwProfile()')
        && str_contains($profile, 'api.profile()')
        && str_contains($profile, 'canonicalProfile = identityResult?.profile'),
    'Visible profile must request canonical identity separately from the transitional legacy stats/economy response.'
);

$assert(
    str_contains($profile, 'renderCanonicalProfile(canonicalProfile)')
        && str_contains($profile, 'profile.display_name')
        && str_contains($profile, 'profile.mgw_id')
        && str_contains($profile, 'profile.created_at'),
    'Visible name, MGW id and registration date must be owned by the canonical profile response.'
);

$assert(
    str_contains($profile, 'profile?.avatar?.external_ref')
        && str_contains($profile, 'el.dataset.photoOwner = mgwId')
        && str_contains($profile, "['http:', 'https:'].includes(parsed.protocol)")
        && str_contains($profile, 'initials(displayName)'),
    'Canonical avatar must be owned by MGW id, accept only HTTP(S) external refs and fall back to initials.'
);

$assert(
    !str_contains($profile, 'renderUser(')
        && !str_contains($profile, 'window.Telegram')
        && !str_contains($profile, 'initDataUnsafe'),
    'Profile screen must not retain a legacy renderer or direct Telegram SDK avatar fallback as a second visible identity owner.'
);

$assert(
    !str_contains($ui, "'profileName'")
        && !str_contains($ui, "'profileAvatar'")
        && !str_contains($ui, "getElementById('profileDate')"),
    'Shared legacy renderUser must no longer write profile identity DOM owned by the canonical profile screen.'
);

$assert(
    str_contains($v110, 'api/client.js?v=1132&profile=mgw-canonical')
        && str_contains($v110, 'ui.js?v=90&profile=single-owner')
        && str_contains($v110, 'profile-screen-v110.js?v=1109&profile=mgw-canonical')
        && str_contains($v110, 'X-MGW-Profile-Graph: mvp15-2-canonical-mgw-id-avatar'),
    'v110 must publish one cache-addressed canonical profile graph.'
);

$assert(
    str_contains($v110, 'X-MGW-Battleship-Miss-Handoff: 900ms')
        && str_contains($v110, 'X-MGW-Battleship-Shot-Feedback: hit-sunk-impact-miss-static')
        && str_contains($v110, 'X-MGW-TTT-Clock: authoritative-turn-clock-v7-handoff-state-retained'),
    'Accepted game timing/feedback publication invariants must remain unchanged.'
);

foreach ([
    'app/assets/js/api/client.js',
    'app/assets/js/screens/profile-screen-v110.js',
    'app/assets/js/ui.js',
    'bot/profile.php',
    'bot/accounts/MgwProfileService.php',
] as $path) {
    $assert(str_contains($manifest, $path), 'Exact staging fingerprint must include: ' . $path);
}

fwrite(STDOUT, "Mvp15UnifiedProfileClientContractTest: {$assertions} assertions passed\n");
