<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/localization/ServerLocalization.php';

$catalog = new LocalizationCatalog($root . '/app/locales');
$checked = 0;
$assert = static function (bool $condition, string $message) use (&$checked): void {
    $checked++;
    if (!$condition) throw new RuntimeException($message);
};

foreach (['server.welcome.start_text', 'server.welcome.invite_text',
    'server.welcome.start_button', 'server.welcome.invite_button',
    'server.webhook.start_hint', 'server.webhook.unavailable',
    'server.telegram.start_text', 'server.telegram.start_button',
    'server.account_link.telegram.prompt', 'server.account_link.telegram.confirm_button'
] as $key) {
    $ru = $catalog->translate($key, ['nickname'=>'Tester'], 'ru');
    $en = $catalog->translate($key, ['nickname'=>'Tester'], 'en');
    $assert($ru !== '' && $en !== '', $key . ' must be present in both catalogs.');
    $assert(preg_match('/[А-Яа-яЁё]/u', $en) !== 1, $key . ' EN copy must not paint Russian.');
}

$_SERVER['HTTP_X_MGW_LOCALE'] = 'en';
$assert(ServerLocalization::copy('server.webhook.start_hint', 'FALLBACK')
    === $catalog->translate('server.webhook.start_hint', [], 'en'),
    'Non-Telegram server HTTP locale must remain intact.');

ServerLocalization::bindTelegramUpdate(['message'=>['from'=>['language_code'=>'ru-RU']]]);
$assert(ServerLocalization::copy('server.welcome.start_text', 'FALLBACK')
    === $catalog->translate('server.welcome.start_text', [], 'ru'),
    'Telegram RU must override conflicting HTTP locale header.');

ServerLocalization::bindTelegramUpdate(['message'=>['from'=>['language_code'=>'en-US']]]);
$assert(ServerLocalization::copy('server.welcome.invite_text', 'FALLBACK')
    === $catalog->translate('server.welcome.invite_text', [], 'en'),
    'Telegram EN must localize invite copy.');

ServerLocalization::bindTelegramUpdate(['callback_query'=>['from'=>['language_code'=>'en']]]);
$assert(ServerLocalization::copy('server.account_link.telegram.confirm_button', 'FALLBACK')
    === $catalog->translate('server.account_link.telegram.confirm_button', [], 'en'),
    'Telegram callback language must localize callback labels.');

ServerLocalization::bindTelegramUpdate(['message'=>['from'=>['language_code'=>'de']]]);
$assert(ServerLocalization::copy('server.welcome.start_text', 'FALLBACK')
    === $catalog->translate('server.welcome.start_text', [], 'ru'),
    'Unsupported language must select RU.');

ServerLocalization::bindTelegramUpdate([]);
$assert(ServerLocalization::copy('server.webhook.start_hint', 'FALLBACK')
    === $catalog->translate('server.webhook.start_hint', [], 'ru'),
    'Missing sender language must not fall through to HTTP header.');

$webhook = file_get_contents($root . '/bot/webhook.php');
$assert(is_string($webhook)
    && strpos($webhook, 'TelegramWebhookSecurity::incomingAuthorized')
       < strpos($webhook, 'ServerLocalization::bindTelegramUpdate($update)')
    && strpos($webhook, 'ServerLocalization::bindTelegramUpdate($update)')
       < strpos($webhook, '$maintenanceGuard = new MaintenanceWebhookGuard'),
    'Locale binding must happen after auth and before any bot guard.');

echo "Mvp27_3TelegramWebhookLocaleTest: {$checked} assertions passed\n";
