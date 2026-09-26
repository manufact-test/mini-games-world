<?php
declare(strict_types=1);

if (!defined('MINIGAMES_INTERNAL')) {
    http_response_code(404);
    exit;
}

/*
 * Copy these keys into the private config for the selected environment.
 * Never commit real hosts, tokens, data paths or admin IDs.
 * Database settings live separately in _private_mgw/database.php.
 */
return [
    'environment' => 'local', // production | staging | local
    'base_url' => 'http://localhost:8080',
    'allowed_hosts' => ['localhost', '127.0.0.1'],
    'data_dir' => dirname(__DIR__) . '/data',

    /* JSON remains active until the later database cutover MVP. */
    'storage_driver' => 'json',

    /* Local may use the placeholder. Production and staging require real,
     * different Telegram bot tokens in their private configs. */
    'bot_token' => 'PASTE_BOT_TOKEN_HERE',

    'allow_browser_dev_user' => true,
    'force_browser_dev_user' => false,

    /* MVP-22.8 account-data lifecycle. Real secrets and private paths belong
     * only in the environment's private config, never in source control. */
    'account_data_website_hook_secret' => 'PASTE_LONG_RANDOM_WEBSITE_HOOK_SECRET_HERE',
    'account_data_export_dir' => '',
    'account_data_media_root' => '',
    'account_data_export_rate_limit_sec' => 86400,
    'account_data_export_retention_sec' => 604800,
    // Defaults to Telegram initData max-age + clock-skew when omitted.
    'account_deleted_identity_block_sec' => 86700,

    /* Runtime controls are safe by default and preserve the current product.
     * Active games remain playable even when maintenance or a game flag is off. */
    'feature_flags' => [
        'maintenance_mode' => false,
        'maintenance_message' => '',
        'financial_read_only' => false,
        'features' => [
            'matchmaking' => true,
            'invitations' => true,
            'payments' => true,
            'shop' => true,
            'tournaments' => false,
            'ads' => false,
        ],
        'games' => [
            'tictactoe' => true,
            'four_in_a_row' => true,
            'battleship' => true,
            'checkers' => true,
            'reversi' => true,
            'chess' => true,
            'go' => true,
            'domino' => true,
        ],
    ],

    /* Required for staging. Database isolation is stored in the separate
     * _private_mgw/database.php file. */
    'environment_guard' => [
        'production_hosts' => [],
        'production_bot_token_sha256' => '',
        'production_data_dir' => '',
    ],
];
