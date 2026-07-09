<?php
return [
    // ВСТАВЬТЕ СЮДА АКТУАЛЬНЫЙ ТОКЕН БОТА. Старый токен лучше перевыпустить в BotFather.
    'bot_token' => '8945892179:AAF3az3EKUYoxyaqCnDUhoH842eAZbYrqqE',

    // Домен проекта без слеша в конце.
    'base_url' => 'https://lemonchiffon-gerbil-545102.hostingersite.com',

    // Ключ для одноразового запуска setup-webhook.php.
    // После установки webhook можно удалить setup-webhook.php или поменять этот ключ.
    'setup_secret' => 'mgw_setup_2029_privet_gkdhnvr3',

    // Для MVP можно оставить true. Перед платежами обязательно false.
    'allow_browser_dev_user' => true,

    // Комиссия клуба. 0.10 = 10% от банка при победе.
    'commission_rate' => 0.10,

    // Правила комнат.
    'match_bet' => 10,
    'gold_bets' => [10, 20, 30, 50, 100],
    'board_sizes' => [3, 5, 9],
    'move_timeout_sec' => 60,
    'queue_timeout_sec' => 60,

    // Магазин призов.
    // Минимальный заказ — 1000 коинов.
    // Коины становятся доступными для магазина только через Gold-оборот.
    'shop_min_order' => 1000,
    'shop_turnover_multiplier' => 1.0,

    // Стартовые коины для теста MVP.
    'initial_match_coins' => 50,
    'initial_gold_coins' => 0,

    // Скрытые админ-команды. Команды не показываем пользователям.
    // Даже если кто-то их узнает, доступ будет только у Telegram ID из admin_ids.
    'admin_command' => '/mgw_private_admin_7291',
    'admin_orders_command' => '/mgw_private_admin_7291_orders',
    'admin_support_command' => '/mgw_private_admin_7291_support',
    'admin_users_command' => '/mgw_private_admin_7291_users',

    // ID админов Telegram. Вставьте сюда ID цифрами, не @username.
    'admin_ids' => [
        972585905, // ID первого админа
        409159595, // ID второго админа
    ],

    // Старое поле оставляем для совместимости. Его можно не трогать.
    'admin_chat_id' => null,
];
