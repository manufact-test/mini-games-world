<?php
declare(strict_types=1);

return [
    // Copy this file to the private Hostinger path:
    // ../_private_mgw/config.php relative to public_html.
    // Never commit real tokens or admin IDs.
    'bot_token' => 'PUT_TELEGRAM_BOT_TOKEN_HERE',
    'setup_secret' => 'CHANGE_ME_TO_LONG_RANDOM_SECRET',
    'admin_ids' => [
        'PUT_ADMIN_TELEGRAM_ID_HERE',
    ],

    // Optional. If empty, bootstrap uses an external mgw_data folder when it exists,
    // otherwise bot/data inside the project.
    'data_dir' => '',

    // MVP-9 weekly Match economy. The product schedule is Monday 12:00 Moscow time.
    'weekly_match_timezone' => 'Europe/Moscow',
    'weekly_match_start_at' => '2026-07-13 12:00:00',
    'weekly_match_bonus_amount' => 50,
    'weekly_match_min_completed' => 3,
];
