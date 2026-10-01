<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/validators.php';
require_once __DIR__ . '/../storage/JsonDatabase.php';

function mgw_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function mgw_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) mgw_remove_tree($child);
        elseif (is_file($child)) unlink($child);
    }
    rmdir($path);
}

// Cyrillic occupies two UTF-8 bytes per letter. The historical byte cap must
// remain bounded, but truncation may never leave a half code point behind.
$clean = clean_string('Абвгдежз', 13);
mgw_assert(strlen($clean) <= 13, 'clean_string must retain the configured byte cap');
mgw_assert(preg_match('//u', $clean) === 1, 'clean_string must always return valid UTF-8');

// A malformed value must never make JsonDatabase replace an entire changed
// section with [] during persistence.
$tmp = sys_get_temp_dir() . '/mgw-mvp26-6-utf8-' . bin2hex(random_bytes(6));
try {
    $db = new JsonDatabase($tmp);
    $db->transaction(static function (array &$data): void {
        $data['users']['u1'] = [
            'id'=>'u1',
            'first_name'=>"Игрок\xC3",
        ];
    });

    $usersRaw = file_get_contents($tmp . '/users.json');
    mgw_assert(is_string($usersRaw) && trim($usersRaw) !== '[]', 'invalid UTF-8 must not erase users.json');
    $users = json_decode((string)$usersRaw, true);
    mgw_assert(is_array($users) && isset($users['u1']), 'persisted user must survive UTF-8 substitution');
    mgw_assert(
        preg_match('//u', (string)($users['u1']['first_name'] ?? '')) === 1,
        'persisted user string must be valid UTF-8'
    );
} finally {
    mgw_remove_tree($tmp);
}

$inviteSource = file_get_contents(__DIR__ . '/../invites.php') ?: '';
mgw_assert(
    str_contains($inviteSource, 'JSON_INVALID_UTF8_SUBSTITUTE')
        && str_contains($inviteSource, 'mgw_invite_row_fingerprints'),
    'invite fingerprints must tolerate malformed historical UTF-8'
);

$pickerSource = file_get_contents(__DIR__ . '/../invite-opponents.php') ?: '';
mgw_assert(
    str_contains($pickerSource, 'FriendGraphService')
        && str_contains($pickerSource, 'runtimeSubjectForMgwId')
        && str_contains($pickerSource, "'friends'"),
    'invite picker must merge canonical friends through the existing invite runtime subject'
);

$inviteGuardSource = file_get_contents(__DIR__ . '/../social/SocialInviteGuard.php') ?: '';
mgw_assert(
    str_contains($inviteGuardSource, 'RuntimeAccountOwnershipService')
        && str_contains($inviteGuardSource, "['legacy_user_id']"),
    'Android invite targets must resolve through provider-neutral runtime ownership'
);

$css = file_get_contents(dirname(__DIR__, 2) . '/app/assets/css/main.css') ?: '';
mgw_assert(
    str_contains($css, '.support-file-chip button')
        && str_contains($css, 'align-self:center;')
        && !str_contains($css, 'transform:translateY(-1px);'),
    'support attachment remove control must be centered without glyph offset'
);

$v110 = file_get_contents(dirname(__DIR__, 2) . '/app/v110.php') ?: '';
mgw_assert(
    str_contains($v110, "\$assets['main_css'] . '&mvp26_6=support-remove-align-v1'"),
    'active v110 renderer must cache-bust the support alignment corrective'
);

$gameScreen = file_get_contents(dirname(__DIR__, 2) . '/app/assets/js/screens/game-screen-v102.js') ?: '';
mgw_assert(
    str_contains($gameScreen, "String(error?.code || '') !== 'network_unavailable'"),
    'background game polling must not surface transient network-unavailable toast'
);

$manifest = file_get_contents(dirname(__DIR__, 2) . '/app/runtime/client/version-manifest.php') ?: '';
mgw_assert(
    str_contains($manifest, 'game-screen-v102.js?v=114')
        && str_contains($manifest, 'mvp26_6=android-poll-network-silent-v1'),
    'active game-screen owner must cache-bust the Android poll corrective'
);

$navigation = file_get_contents(dirname(__DIR__, 2) . '/android-app/app/src/main/java/com/minigamesworld/app/NavigationPolicy.java') ?: '';
$activity = file_get_contents(dirname(__DIR__, 2) . '/android-app/app/src/main/java/com/minigamesworld/app/MainActivity.java') ?: '';
mgw_assert(
    str_contains($navigation, 'nativeTelegramShareDeepLink')
        && str_contains($navigation, 'tg://msg_url?')
        && str_contains($activity, 'openTelegramShareDirect'),
    'standalone Android Telegram share must use the direct native deep link with browser fallback'
);

echo "MVP-26.6 Android parity corrective PASS\n";
