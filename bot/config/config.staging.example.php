<?php
declare(strict_types=1);

if (!defined('MINIGAMES_INTERNAL')) {
    http_response_code(404);
    exit;
}

/*
 * Copy only the required keys into the private staging config.
 * Never commit real domains, paths, bot tokens, setup keys or admin IDs.
 */
return [
    'environment' => 'staging',
    'base_url' => 'https://staging.example.invalid',
    'allowed_hosts' => ['staging.example.invalid'],

    /* Must be a dedicated staging directory, never the production mgw_data. */
    'data_dir' => '/home/ACCOUNT/mgw_staging_data',

    /* Token and username of the separate Telegram test bot. */
    'bot_token' => 'PASTE_STAGING_BOT_TOKEN_HERE',
    'staging_bot_username' => 'example_mgw_test_bot',

    /* Store only SHA-256 of the setup key. Generate outside the repository. */
    'staging_setup_key_sha256' => 'PASTE_64_CHARACTER_SHA256_HERE',

    'allow_browser_dev_user' => false,
    'force_browser_dev_user' => false,
    'external_payments_enabled' => false,
    'payment_mode' => 'disabled',
    'telegram_stars_mode' => 'disabled',
    'google_play_billing_mode' => 'disabled',

    /* Production metadata is used only for collision detection. */
    'environment_guard' => [
        'production_hosts' => ['production.example.invalid'],
        'production_bot_token_sha256' => 'PASTE_PRODUCTION_BOT_TOKEN_SHA256_HERE',
        'production_data_dir' => '/home/ACCOUNT/mgw_data',
        'production_database_sha256' => '',
    ],
];
