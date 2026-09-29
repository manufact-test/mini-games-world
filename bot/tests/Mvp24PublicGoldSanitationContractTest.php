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

$htaccess = $read('.htaccess');
$russianPage = $read('site/russian-page.php');
$sitemap = $read('sitemap.xml');
$landing = $read('site/landing/landing-v18.php');
$blogIndex = $read('site/blog/index.html');
$friends = $read('site/blog/telegram-games-with-friends/index.html');
$miniApps = $read('site/blog/telegram-mini-app-games/index.html');
$ticTacToe = $read('site/blog/tic-tac-toe-online/index.html');
$upcoming = $read('site/blog/upcoming-games/index.html');
$terms = $read('site/legal/terms/index.html');

$assert(
    str_contains($htaccess, 'RewriteRule ^blog/(?:gold-room|bot-and-match-coins)(?:/.*)?$ /blog/ [R=301,L]'),
    'Retired Gold/Match commerce articles must redirect to the current blog index.'
);
$assert(
    !str_contains($russianPage, "'gold-room'")
        && !str_contains($russianPage, "'bot-and-match-coins'"),
    'Retired commerce articles must not remain active russian-page routes.'
);
$assert(
    !str_contains($sitemap, '/blog/gold-room/')
        && !str_contains($sitemap, '/blog/bot-and-match-coins/'),
    'Retired commerce articles must not remain in sitemap.xml.'
);

foreach ([
    'landing' => $landing,
    'blog index' => $blogIndex,
    'friends article' => $friends,
    'Mini Apps article' => $miniApps,
    'tic-tac-toe article' => $ticTacToe,
    'upcoming article' => $upcoming,
    'terms' => $terms,
] as $label => $source) {
    $assert(
        preg_match('/Gold-комнат|Gold room|Match-комнат|Match room|Match and Gold|балансы Match и Gold|Match and Gold balances/u', $source) !== 1,
        $label . ' must not publish retired Match/Gold room claims.'
    );
}

$assert(
    str_contains($terms, 'единый баланс виртуальных MGW-коинов')
        && str_contains($terms, 'have no cash value')
        && str_contains($terms, 'не подлежат выводу за реальные деньги'),
    'Terms must describe the active unified virtual-coin model without legacy cash-out promises.'
);

fwrite(STDOUT, "Mvp24PublicGoldSanitationContractTest: {$assertions} assertions passed\n");
