<?php
declare(strict_types=1);

require __DIR__ . '/includes/russian-only.php';

$pages = [
    'blog' => __DIR__ . '/blog/index.html',
    'tic-tac-toe-online' => __DIR__ . '/blog/tic-tac-toe-online/index.html',
    'telegram-mini-app-games' => __DIR__ . '/blog/telegram-mini-app-games/index.html',
    'telegram-games-with-friends' => __DIR__ . '/blog/telegram-games-with-friends/index.html',
    'upcoming-games' => __DIR__ . '/blog/upcoming-games/index.html',
    'gold-room' => __DIR__ . '/blog/gold-room/index.html',
    'bot-and-match-coins' => __DIR__ . '/blog/bot-and-match-coins/index.html',
    'privacy' => __DIR__ . '/legal/privacy/index.html',
    'cookies' => __DIR__ . '/legal/cookies/index.html',
    'terms' => __DIR__ . '/legal/terms/index.html',
];

$key = isset($_GET['page']) ? (string) $_GET['page'] : '';
$file = $pages[$key] ?? null;
if ($file === null || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Страница не найдена.';
    exit;
}

$html = file_get_contents($file);
if ($html === false || $html === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Не удалось загрузить страницу.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Content-Language: ru');
echo mgw_russian_only($html);
