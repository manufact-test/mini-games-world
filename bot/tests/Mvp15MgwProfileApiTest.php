<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/database/DatabaseConnectionInterface.php';
require_once $root . '/bot/database/PdoDatabaseConnection.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/accounts/MgwProfileService.php';

$pdo = new PDO('sqlite::memory:');
$database = new PdoDatabaseConnection($pdo);
$database->execute('CREATE TABLE mgw_users (
    mgw_id TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    display_name TEXT NOT NULL,
    username TEXT NULL,
    avatar_provider TEXT NULL,
    avatar_external_ref TEXT NULL,
    avatar_storage_key TEXT NULL,
    avatar_mime_type TEXT NULL,
    avatar_width INTEGER NULL,
    avatar_height INTEGER NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    last_seen_at_utc TEXT NOT NULL
)');
$database->execute('CREATE TABLE mgw_identities (
    identity_id INTEGER PRIMARY KEY AUTOINCREMENT,
    mgw_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    provider_subject TEXT NOT NULL,
    provider_username TEXT NULL,
    linked_at_utc TEXT NOT NULL,
    last_authenticated_at_utc TEXT NOT NULL
)');

$mgwId = MgwIdGenerator::generate();
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username,
        avatar_provider, avatar_external_ref, avatar_storage_key,
        avatar_mime_type, avatar_width, avatar_height,
        created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, :username,
        :avatar_provider, :avatar_external_ref, NULL,
        NULL, NULL, NULL,
        :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'MGW Player',
        'username' => 'player',
        'avatar_provider' => 'telegram',
        'avatar_external_ref' => 'https://example.test/avatar.jpg',
        'created_at' => '2026-08-14 20:00:00.000000',
        'updated_at' => '2026-08-14 20:01:00.000000',
        'last_seen_at' => '2026-08-14 20:02:00.000000',
    ]
);
foreach ([
    ['telegram', '972585905', 'player', '2026-08-14 20:00:00.000000'],
    ['google', 'google-subject-private', 'player@example.test', '2026-08-14 20:01:00.000000'],
] as [$provider, $subject, $username, $linkedAt]) {
    $database->execute(
        'INSERT INTO mgw_identities (
            mgw_id, provider, provider_subject, provider_username,
            linked_at_utc, last_authenticated_at_utc
         ) VALUES (
            :mgw_id, :provider, :provider_subject, :provider_username,
            :linked_at, :last_authenticated_at
         )',
        [
            'mgw_id' => $mgwId,
            'provider' => $provider,
            'provider_subject' => $subject,
            'provider_username' => $username,
            'linked_at' => $linkedAt,
            'last_authenticated_at' => '2026-08-14 20:02:00.000000',
        ]
    );
}

$profile = (new MgwProfileService($database))->publicProfile($mgwId);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert($profile['mgw_id'] === $mgwId, 'MGW id must be the canonical public profile identity.');
$assert($profile['display_name'] === 'MGW Player', 'Display name must come from mgw_users.');
$assert(($profile['avatar']['provider'] ?? null) === 'telegram', 'Avatar metadata must remain behind the unified profile contract.');
$assert(count($profile['identities'] ?? []) === 2, 'Profile must support multiple linked providers without changing its MGW id.');
$assert(array_column($profile['identities'], 'provider') === ['telegram', 'google'], 'Linked providers must be represented as metadata, not account ids.');
$encoded = json_encode($profile, JSON_THROW_ON_ERROR);
$assert(!str_contains($encoded, '972585905'), 'Provider subject must never leak through the public profile contract.');
$assert(!str_contains($encoded, 'google-subject-private'), 'Google/provider subject must never leak through the public profile contract.');

$endpoint = file_get_contents($root . '/bot/profile.php');
$assert(is_string($endpoint) && str_contains($endpoint, 'getUserFromRequest($payload)'), 'Canonical AuthService must remain the provider authentication owner.');
$assert(str_contains($endpoint, "['mgw_id']"), 'Profile endpoint must consume the authenticated internal MGW id.');
$assert(!str_contains($endpoint, "['telegram_id']"), 'Canonical profile endpoint must not use Telegram id as its account key.');
$assert(!str_contains($endpoint, 'StorageFactory::createJson'), 'MGW profile API must not read legacy JSON as a competing profile owner.');
$assert(!str_contains($endpoint, 'google'), 'MVP-15.1 must prepare provider neutrality without coupling current behavior to Google auth.');

fwrite(STDOUT, "Mvp15MgwProfileApiTest: {$assertions} assertions passed\n");
