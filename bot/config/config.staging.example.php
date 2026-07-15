<?php
declare(strict_types=1);

if (!defined('MINIGAMES_INTERNAL')) {
    http_response_code(404);
    exit;
}

/*
 * Copy these keys into ../_private_mgw/config.php next to public_html.
 * Never commit real domains, bot tokens, setup keys or admin IDs.
 */
return [
    'environment' => 'staging',
    'base_url' => 'https://staging.example.invalid',
    'allowed_hosts' => ['staging.example.invalid'],

    /* config.php is inside _private_mgw, so this resolves to its sibling folder. */
    'data_dir' => dirname(__DIR__) . '/mgw_staging_data',

    /* Token and username of the separate Telegram test bot. */
    'bot_token' => 'PASTE_STAGING_BOT_TOKEN_HERE',
    'staging_bot_username' => 'example_mgw_test_bot',

    /* Separate long key for the staging webhook setup page. */
    'staging_setup_key' => 'PASTE_RANDOM_SETUP_KEY_AT_LEAST_20_CHARACTERS',

    /* Copy the existing production admin_ids block into the private staging config.
     * The same administrators may control staging, but the IDs must stay outside GitHub. */
    'admin_ids' => [],

    'allow_browser_dev_user' => false,
    'force_browser_dev_user' => false,
    'external_payments_enabled' => false,
    'payment_mode' => 'disabled',
    'telegram_stars_mode' => 'disabled',
    'google_play_billing_mode' => 'disabled',

    'weekly_match_timezone' => 'Europe/Moscow',
    'weekly_match_start_at' => '2026-07-13 12:00:00',
    'weekly_match_bonus_amount' => 50,
    'weekly_match_min_completed' => 3,

    /* Production metadata is used only to reject accidental environment mixing.
     * It does not connect staging to production or send traffic there. */
    'environment_guard' => [
        'production_hosts' => ['production.example.invalid'],
        'production_bot_token_sha256' => '',
        'production_data_dir' => dirname(__DIR__) . '/mgw_data',
        'production_database_sha256' => '',
    ],
];
