<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/landing-v10.php';
$html = (string) ob_get_clean();

$html = strtr($html, [
    'Еженедельные начисления активным игрокам' => 'Еженедельные начисления готовятся к запуску',
    'Пополнения и Магазин призов' => 'Заявки на пополнение и раздел магазина',
    'Funding and prize shop' => 'Top-up requests and prize-shop section',
    'Сейчас доступны заявки на пополнение, история операций и магазин сертификатов за Gold-коины.' => 'Сейчас доступны заявки на пополнение, история операций и раздел магазина призов; оформление заказа ещё дорабатывается.',
    'Top-up requests, transaction history and a certificate prize shop are available.' => 'Top-up requests, transaction history and a prize-shop section are available; the order flow is still being completed.',
    'Gold доступна в тестовом режиме: работают выбор ставки, отдельный баланс, заявки на пополнение, история операций и магазин призов.' => 'Gold доступна в тестовом режиме: работают выбор ставки, отдельный баланс, заявки на пополнение, история операций и раздел магазина призов.',
    'Gold is available in test mode with selectable stakes, a separate balance, top-up requests, transaction history and a prize shop.' => 'Gold is available in test mode with selectable stakes, a separate balance, top-up requests, transaction history and a prize-shop section.',
    'Выбор ставки, отдельный баланс, пополнения, история операций и подготовка к запуску.' => 'Тестовый режим, отдельный баланс, заявки на пополнение и раздел магазина призов.',
    'Stake selection, a separate balance, deposits, transaction history and launch preparation.' => 'Test mode, a separate balance, top-up requests, transaction history and a prize-shop section.',
    'Только необходимые' => 'Закрыть',
    'Necessary only' => 'Close',
    'Принять' => 'Понятно',
    'Accept' => 'Got it',
]);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
