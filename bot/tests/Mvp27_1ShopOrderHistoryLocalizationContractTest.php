<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ShopOrderHistoryService.php';

$service = new ShopOrderHistoryService();

$db = [
    'shop_orders' => [
        [
            'id' => 'shop_pending',
            'user_id' => 'MGW-TEST',
            'status' => 'pending',
            'amount' => 10,
            'created_at' => '2026-10-06 10:00:00',
        ],
        [
            'id' => 'shop_rejected',
            'user_id' => 'MGW-TEST',
            'status' => 'rejected',
            'amount' => 20,
            'created_at' => '2026-10-06 11:00:00',
            'updated_at' => '2026-10-06 12:00:00',
        ],
        [
            'id' => 'shop_done',
            'user_id' => 'MGW-TEST',
            'status' => 'done',
            'amount' => 30,
            'prize_title' => 'Custom prize',
            'created_at' => '2026-10-06 09:00:00',
            'updated_at' => '2026-10-06 13:00:00',
        ],
        [
            'id' => 'shop_other',
            'user_id' => 'MGW-OTHER',
            'status' => 'processing',
            'amount' => 40,
            'created_at' => '2026-10-06 14:00:00',
        ],
    ],
];

$orders = $service->userOrders($db, 'MGW-TEST', 20);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(count($orders) === 3, 'Only the requested user orders must be returned.');
$assert(array_column($orders, 'id') === ['shop_done', 'shop_rejected', 'shop_pending'], 'Order activity sorting changed.');

$done = $orders[0];
$assert($done['status'] === 'done', 'Done status normalization changed.');
$assert($done['status_label'] === 'Выполнена', 'Done visible RU label changed.');
$assert($done['status_tone'] === 'success', 'Done status tone changed.');
$assert($done['prize_title'] === 'Custom prize', 'Explicit prize title changed.');

$rejected = $orders[1];
$assert($rejected['status_label'] === 'Отклонена', 'Rejected visible RU label changed.');
$assert($rejected['status_tone'] === 'danger', 'Rejected status tone changed.');
$assert($rejected['reject_reason'] === 'Причина не указана.', 'Rejected fallback reason changed.');
$assert($rejected['prize_title'] === 'Приз', 'Prize fallback changed.');

$pending = $orders[2];
$assert($pending['status_label'] === 'Ожидает обработки', 'Pending visible RU label changed.');
$assert($pending['status_tone'] === 'warning', 'Pending status tone changed.');

$limited = $service->userOrders($db, 'MGW-TEST', 1);
$assert(count($limited) === 1 && $limited[0]['id'] === 'shop_done', 'Order limit semantics changed.');
$assert($service->userOrders($db, '', 20) === [], 'Empty user id semantics changed.');

fwrite(STDOUT, 'Mvp27_1ShopOrderHistoryLocalizationContractTest: ' . $assertions . " assertions passed\n");
