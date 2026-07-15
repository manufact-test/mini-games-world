<?php
declare(strict_types=1);

if (!defined('MINIGAMES_INTERNAL')) {
    http_response_code(404);
    exit;
}

/*
 * Copy these keys into the private config for the selected environment.
 * Never commit real hosts, tokens, data paths, database names or admin IDs.
 */
return [
    'environment' => 'local', // production | staging | local
    'base_url' => 'http://localhost:8080',
    'allowed_hosts' => ['localhost', '127.0.0.1'],
    'data_dir' => dirname(__DIR__) . '/data',

    /* Local may use the placeholder. Production and staging require real,
     * different Telegram bot tokens in their private configs. */
    'bot_token' => 'PASTE_BOT_TOKEN_HERE',

    'allow_browser_dev_user' => true,
    'force_browser_dev_user' => false,

    /* Required for staging. Values belong only in the private config or
     * environment variables; they are fingerprints/locations, not secrets. */
    'environment_guard' => [
        'production_hosts' => [],
        'production_bot_token_sha256' => '',
        'production_data_dir' => '',
        'production_database_sha256' => '',
    ],
];
