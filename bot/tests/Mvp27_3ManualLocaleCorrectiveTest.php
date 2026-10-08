<?php
declare(strict_types=1);

// Standalone test: no live DB, credentials, network or Telegram sends.
$root = dirname(__DIR__, 2);
require_once $root . '/bot/localization/ServerLocalization.php';

final class FakeTelegramLocaleStore
{
    public static array $data = ['users'=>[
        '111' => ['username'=>'one', 'balance'=>50],
        '222' => ['username'=>'two', 'balance'=>80],
    ]];

    public function readOnlySections(array $sections, callable $callback): mixed
    {
        return $callback(self::$data);
    }

    public function transaction(callable $callback): mixed
    {
        return $callback(self::$data);
    }
}

final class StorageFactory
{
    public static function createJson(string $directory): FakeTelegramLocaleStore
    {
        return new FakeTelegramLocaleStore();
    }
}

require_once $root . '/bot/localization/TelegramLocalePreference.php';

$checked = 0;
$assert = static function (bool $ok, string $message) use (&$checked): void {
    $checked++;
    if (!$ok) throw new RuntimeException($message);
};
$catalog = new LocalizationCatalog($root . '/app/locales');
$config = ['data_dir'=>'offline-test'];

$assert(TelegramLocalePreference::read($config, '111') === null, 'Unset Telegram preference must not override platform.');
$assert(TelegramLocalePreference::save($config, '111', 'en'), 'Existing Telegram actor must save EN.');
$assert(TelegramLocalePreference::fromUpdate($config, ['message'=>['from'=>['id'=>111]]]) === 'en',
    'Webhook must find the authenticated message actor.');
$assert(TelegramLocalePreference::fromUpdate($config, ['callback_query'=>['from'=>['id'=>111]]]) === 'en',
    'Webhook must find the authenticated callback actor.');
$assert(!TelegramLocalePreference::save($config, '222', 'de'), 'Unsupported locale cannot be stored.');
$assert(!TelegramLocalePreference::save($config, '333', 'ru'), 'Absent runtime user cannot be created indirectly.');
$assert(TelegramLocalePreference::read($config, 'not-numeric') === null, 'Non-Telegram identity cannot be read.');
$assert(FakeTelegramLocaleStore::$data['users']['222']['balance'] === 80,
    'Saving one actor language must not mutate another account or balance.');

ServerLocalization::bindTelegramUpdate(['message'=>['from'=>['language_code'=>'ru-RU']]]);
ServerLocalization::preferAuthenticatedTelegramLocale(TelegramLocalePreference::read($config, '111'));
$assert(ServerLocalization::copy('server.welcome.start_text', 'FALLBACK')
    === $catalog->translate('server.welcome.start_text', [], 'en'),
    'Explicit EN must override Telegram RU for /start.');
ServerLocalization::bindTelegramUpdate(['message'=>['from'=>['language_code'=>'en']]]);
ServerLocalization::preferAuthenticatedTelegramLocale(null);
$assert(ServerLocalization::copy('server.welcome.start_button', 'FALLBACK')
    === $catalog->translate('server.welcome.start_button', [], 'en'),
    'Without an override, Telegram EN stays EN.');
$assert(TelegramLocalePreference::save($config, '111', 'ru'), 'RU override must be saveable.');
ServerLocalization::bindTelegramUpdate(['message'=>['from'=>['language_code'=>'en']]]);
ServerLocalization::preferAuthenticatedTelegramLocale(TelegramLocalePreference::read($config, '111'));
$assert(ServerLocalization::copy('server.welcome.start_button', 'FALLBACK')
    === $catalog->translate('server.welcome.start_button', [], 'ru'),
    'Explicit RU must override Telegram EN.');
$assert(FakeTelegramLocaleStore::$data['users']['111']['balance'] === 50,
    'Saving preference may not modify the actor balance.');

$gameTypes = ['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'];
foreach ($gameTypes as $type) {
    foreach (['ru','en'] as $locale) {
        $name = $catalog->translate('game_invites.game_titles.' . $type, [], $locale);
        $assert($name !== '', "Missing {$locale} game title: {$type}");
        if ($locale === 'en') $assert(preg_match('/[А-Яа-яЁё]/u', $name) !== 1, "English game title is Russian: {$type}");
    }
}

$endpoint = file_get_contents($root . '/bot/telegram-locale.php');
$bot = file_get_contents($root . '/bot/webhook.php');
$invites = file_get_contents($root . '/bot/invites.php');
$client = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
$i18n = file_get_contents($root . '/app/assets/js/localization/i18n.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
$assert(str_contains($endpoint, 'getTelegramUserFromInitData($initData, false)'),
    'Preference endpoint must use HMAC-verified Telegram initData only.');
$assert(!str_contains($endpoint, 'getUserFromRequest('),
    'Preference endpoint must not accept browser-dev or Android-auth fallback.');
$assert(str_contains($bot, 'TelegramLocalePreference::fromUpdate($config, $update)'),
    'Authenticated webhook must consult persisted Telegram-channel preference.');
$assert(str_contains($invites, "'HTTP_X_MGW_LOCALE'") && str_contains($invites, 'mgw_invite_game_title($invite)'),
    'Share and prepared messages must use request locale and game_type translations.');
$transportStart = strpos($client, 'async function postJson(');
$transportEnd = $transportStart === false ? false : strpos($client, "\nfunction cloneInvite(", $transportStart);
$transportSource = $transportStart !== false && $transportEnd !== false
    ? substr($client, $transportStart, $transportEnd - $transportStart)
    : '';
$assert(str_contains($transportSource, "'X-MGW-Locale':getI18n().locale"),
    'Actual prepared/direct/rematch invite postJson transport must propagate selected locale, not only invite-watch.');
$assert(str_contains($client, 'gameTitle(String(invite?.game_type || \'\'))'),
    'Waiting game title must be derived from game_type rather than stored Russian title.');
$assert(str_contains($client, '${getI18n().locale}'),
    'Prewarmed prepared messages must be scoped by locale.');
$assert(str_contains($i18n, '/bot/telegram-locale.php') && str_contains($i18n, 'syncTelegramBotLocale(activated)'),
    'Explicit settings selection must propagate to the Telegram-channel preference.');
$assert(str_contains($manifest, 'mvp27_3=telegram-channel-locale-v1')
    && str_contains($manifest, './assets/js/games/game-invites-v110.js?v=1150&mvp27_3=locale-share-v1'),
    'Real v110 import map must invalidate both modified clients.');

echo "Mvp27_3ManualLocaleCorrectiveTest: {$checked} assertions passed\n";
