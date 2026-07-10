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
];
