<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$appHtaccess = $read('app/.htaccess');
$launchUrl = $read('bot/helpers/WebAppLaunchUrl.php');
$home = $read('app/assets/js/screens/home-screen.js');
$api = $read('bot/api.php');
$reversiStore = $read('app/assets/js/screens/store-screen-reversi-store-v1.js');
$v120 = $read('app/v120.php');

$assert(
    str_contains($appHtaccess, 'DirectoryIndex v110.php index.html'),
    'Default /app/ entry must converge on canonical v110.'
);
$assert(
    str_contains($appHtaccess, 'RewriteRule ^v(?:97|98|99|10[0-9]|114)\\.php$ /app/v110.php [R=302,L,NE]'),
    'Retired executable app entrypoints must redirect to v110.'
);
$assert(
    str_contains($launchUrl, "private const ENTRY_PATH = '/app/v110.php"),
    'Telegram launch owner must remain v110.'
);
$assert(
    str_contains($v120, "header('Location: ' . " . '$target' . ", true, 302);"),
    'v120 compatibility tombstone must remain a redirect.'
);
$assert(
    !str_contains($home, 'inviteFriend')
        && !str_contains($home, 'Приглашения друзей появятся позже.'),
    'Dead Home invite placeholder must be removed.'
);
$assert(
    !str_contains($api, "case 'request_rematch':")
        && !str_contains($api, 'Реванш будет подключён следующим этапом.'),
    'Obsolete request_rematch placeholder action must be removed.'
);
$assert(
    !str_contains($reversiStore, 'manual-review-corrective')
        && str_contains($reversiStore, 'accepted-store-cosmetics-v1'),
    'Active Reversi Store asset identity must not carry manual-review residue.'
);

fwrite(STDOUT, "Mvp25_1RouteAndDeadResidueContractTest: {$assertions} assertions passed\n");
