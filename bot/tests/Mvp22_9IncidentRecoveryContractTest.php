<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$page = $read('app/admin.php');
$shell = $read('app/assets/js/admin-shell.js');
$client = $read('app/assets/js/admin-incident.js');
$css = $read('app/assets/css/admin-incident.css');
$endpoint = $read('bot/admin-incident.php');
$service = $read('bot/incident/IncidentRecoveryService.php');
$identity = $read('bot/accounts/AccountIdentityService.php');
$migration = $read('bot/database/migrations/20260926_0068_create_incident_recovery.php');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'bot/admin-incident.php',
    'bot/incident/IncidentRecoveryService.php',
    'bot/database/migrations/20260926_0068_create_incident_recovery.php',
    'app/assets/js/admin-incident.js',
    'app/assets/css/admin-incident.css',
] as $path) {
    $assert(str_contains($manifest, $path), 'Exact staging fingerprint must include ' . $path);
}

$assert(
    str_contains($page, 'data-incident-api="../bot/admin-incident.php"')
        && str_contains($page, 'data-admin-nav-target="incident"')
        && str_contains($page, 'data-admin-section="incident"')
        && str_contains($shell, "incident:['Инциденты'"),
    'Incident recovery must extend the existing Web Admin navigation.'
);

$assert(
    str_contains($endpoint, 'new RuntimeFeatureFlagAdminService')
        && str_contains($endpoint, 'new SystemAdminService')
        && str_contains($endpoint, 'new AccountIdentityService')
        && str_contains($endpoint, "maintenance_mode'] = true")
        && str_contains($endpoint, "financial_read_only'] = true"),
    'Security mode must reuse the existing runtime/system/session owners.'
);

$assert(
    str_contains($identity, 'public function activeSessionCount')
        && str_contains($identity, 'public function revokeAllActiveSessions')
        && str_contains($identity, 'UPDATE mgw_sessions')
        && !str_contains($migration, 'CREATE TABLE IF NOT EXISTS mgw_sessions'),
    'Session revoke must stay on the canonical account/session owner without a second session store.'
);

$assert(
    str_contains($service, "ACTION_PENDING = 'pending_second_review'")
        && str_contains($service, "hash_equals((string)\$before['requested_by_ref'], \$actorRef)")
        && str_contains($service, 'Опасное действие должен подтвердить другой администратор.')
        && str_contains($client, 'другим администратором'),
    'High-risk incident actions must require a distinct second administrator.'
);

foreach ([
    'enable_security_mode',
    'disable_security_mode',
    'revoke_all_sessions',
] as $action) {
    $assert(str_contains($service, "'{$action}'") && str_contains($page, 'data-incident-request-risk="' . $action . '"'), 'Missing bounded high-risk action: ' . $action);
}

foreach ([
    'telegram_bot_token',
    'database_credentials',
    'account_data_hook_secret',
] as $key) {
    $assert(str_contains($service, "'{$key}'") && str_contains($page, 'data-incident-key-row="' . $key . '"'), 'Missing incident key checklist item: ' . $key);
}

$assert(
    str_contains($page, 'Никогда не вставляйте сюда сами токены, пароли или секреты.')
        && str_contains($service, 'assertNoSecretMaterial')
        && !str_contains($page, 'type="password"'),
    'Key checklist/evidence UI must never solicit secret values.'
);

$assert(
    str_contains($migration, 'mgw_incidents')
        && str_contains($migration, 'mgw_incident_actions')
        && str_contains($migration, 'mgw_incident_key_checks')
        && str_contains($migration, 'mgw_incident_evidence')
        && str_contains($migration, 'mgw_incident_restore_status')
        && str_contains($migration, 'mgw_incident_audit'),
    'Incident state, evidence, restore status and audit must be durable.'
);

$assert(
    str_contains($endpoint, "in_array(\$environment, ['staging','local'], true)")
        && str_contains($endpoint, "'run_staging_rehearsal'")
        && str_contains($page, 'data-incident-run-rehearsal')
        && str_contains($page, 'не изменяет production'),
    'Incident simulation must be staging/local only and visibly non-production.'
);

$assert(
    str_contains($page, 'Она не выполняет молча восстановление базы данных или откат production.')
        && !str_contains($endpoint, 'DROP TABLE')
        && !str_contains($endpoint, 'DELETE FROM mgw_ledger')
        && !str_contains($endpoint, 'git reset'),
    'Recovery console must not contain silent destructive restore shortcuts.'
);

$assert(
    str_contains($client, 'renderPendingActions')
        && str_contains($client, 'renderKeyChecks')
        && str_contains($client, 'renderRestore')
        && str_contains($client, 'renderHistory')
        && !str_contains($client, 'setInterval('),
    'Incident client must render bounded operator state without a polling repair owner.'
);

$assert(
    str_contains($css, '@media(max-width:640px)')
        && str_contains($css, '.mgw-admin__incident-key')
        && str_contains($css, '.mgw-admin__incident-review'),
    'Incident workspace must keep a dedicated mobile layout.'
);

fwrite(STDOUT, "MVP-22.9 incident recovery contract OK ({$assertions} assertions).\n");
