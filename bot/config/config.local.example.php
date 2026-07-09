<?php
return [
    // Copy this file to bot/config/config.local.php on Hostinger if you need local-only overrides.
    // Do not commit config.local.php to GitHub.

    // Example: move runtime JSON data outside public_html.
    // From bot/config this points to a folder next to public_html: /home/.../mgw_data
    'data_dir' => dirname(__DIR__, 3) . '/mgw_data',

    // Example: keep bot token outside the tracked config.php.
    // 'bot_token' => 'PASTE_REAL_BOT_TOKEN_HERE',
];
