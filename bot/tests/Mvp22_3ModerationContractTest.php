<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/moderation/ModerationService.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/20260925_0063_create_moderation_restrictions_appeals.php') ?: '';
$reports = file_get_contents($root . '/social/PlayerReportService.php') ?: '';
$adminEndpoint = file_get_contents($root . '/admin-reports.php') ?: '';
$userEndpoint = file_get_contents($root . '/moderation.php') ?: '';
$friendsEndpoint = file_get_contents($root . '/friends.php') ?: '';
$invitesEndpoint = file_get_contents($root . '/invites.php') ?: '';
$apiEndpoint = file_get_contents($root . '/api.php') ?: '';
$profileEndpoint = file_get_contents($root . '/profile-v2.php') ?: '';
$friendsUi = file_get_contents(dirname($root) . '/app/assets/js/screens/friends-screen-v110.js') ?: '';
$homeUi = file_get_contents(dirname($root) . '/app/assets/js/screens/home-screen.js') ?: '';
$profileUi = file_get_contents(dirname($root) . '/app/assets/js/screens/profile-screen-v110.js') ?: '';
$adminUi = file_get_contents(dirname($root) . '/app/assets/js/admin-reports.js') ?: '';
$client = file_get_contents(dirname($root) . '/app/assets/js/api/client.js') ?: '';
$mainCss = file_get_contents(dirname($root) . '/app/assets/css/main.css') ?: '';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    "'nickname' => 'Недопустимый никнейм'",
    "'avatar' => 'Недопустимый аватар'",
    "'spam' => 'Спам'",
    "'cheating' => 'Нечестная игра'",
    "'stalling' => 'Затягивание игры'",
    "'other' => 'Другое'",
] as $category) {
    $assert(str_contains($reports, $category), 'Player reports must expose MVP-22.3 category: ' . $category);
}
$assert(str_contains($friendsUi, "['nickname','Недопустимый никнейм']"), 'Player report UI must expose nickname reports.');
$assert(str_contains($friendsUi, "['avatar','Недопустимый аватар']"), 'Player report UI must expose avatar reports.');
$assert(str_contains($friendsUi, "['stalling','Затягивание игры']"), 'Player report UI must expose stalling reports.');
$assert(str_contains($homeUi, "document.getElementById('supportBtn')?.addEventListener('click',()=>openPlayerReportSheet())"), 'Main menu complaint entry must open the player-report flow instead of Support.');
$assert(!str_contains($homeUi, "supportBtn')?.addEventListener('click',()=>openSupportForm('complaint'))"), 'Main menu complaint entry must never create a generic Support complaint ticket.');
$assert(str_contains($homeUi, 'PLAYER_REPORT_REASONS'), 'Main menu player-report flow must expose the canonical moderation reasons.');
$assert(str_contains($homeUi, "action:'lookup'"), 'Main menu player-report flow must let the user find a target player.');
$assert(str_contains($homeUi, "action:'report'"), 'Main menu player-report flow must submit to PlayerReportService through the Friends API.');
$assert(!str_contains($homeUi, '<option value="complaint">Жалоба</option>'), 'Support category selector must not masquerade as the player moderation flow.');
$assert(str_contains($mainCss, '.support-ticket-select-wrap::after{'), 'Support select must retain a dedicated chevron owner.');
$assert(str_contains($mainCss, 'border-right:2px solid currentColor;') && str_contains($mainCss, 'border-bottom:2px solid currentColor;'), 'Support select chevron must be geometry-based instead of the misaligned text glyph.');
$assert(!str_contains($mainCss, "content:'⌄';"), 'Broken text-glyph Support arrows must not return.');

$assert(str_contains($migration, 'mgw_moderation_actions'), 'Moderation actions must be durable.');
$assert(str_contains($migration, 'mgw_moderation_appeals'), 'Appeals must be durable.');
$assert(str_contains($migration, 'mgw_moderation_events'), 'Moderation review trail must be durable.');

$assert(str_contains($service, 'public function warning('), 'Moderation must support warnings.');
$assert(str_contains($service, 'public function restrict('), 'Moderation must support scoped/time-bounded restrictions.');
$assert(str_contains($service, 'RESTRICTION_SCOPES'), 'Restriction scope must be server-owned.');
$assert(str_contains($service, 'RESTRICTION_DURATIONS'), 'Restriction duration choices must be server-owned.');
$assert(str_contains($service, 'public function submitAppeal('), 'Player appeal flow must be server-owned.');
$assert(str_contains($service, 'public function reviewAppeal('), 'Appeal review must be explicit.');
$assert(str_contains($service, 'public function recommendPermanentBan('), 'Permanent ban must start as a recommendation.');
$assert(str_contains($service, 'public function reviewPermanentBan('), 'Permanent ban must have an explicit second-review action.');
$assert(str_contains($service, "hash_equals((string)\$action['created_by_admin_ref'], \$adminRef)"), 'Permanent ban must reject same-admin second review.');
$assert(str_contains($service, "'status'=>'banned'"), 'Canonical banned status may only be written by the confirmed permanent-ban path.');
$assert(!str_contains(strtolower($reports), 'auto-ban') && !str_contains(strtolower($reports), 'autoban'), 'Submitting a report must never auto-ban a player.');
$assert(!preg_match('/UPDATE\s+mgw_users\s+SET\s+status/i', $reports), 'PlayerReportService must never mutate account status.');

foreach (["'warning'","'restrict'","'recommend_ban'","'review_ban'","'review_appeal'"] as $action) {
    $assert(str_contains($adminEndpoint, $action), 'Admin moderation endpoint must expose action ' . $action);
}
$assert(str_contains($adminUi, 'Решение и история модерации'), 'Admin moderation controls must be collapsed behind a clear disclosure.');
$assert(str_contains($adminUi, 'Подтверждение должен выполнить другой администратор.'), 'Admin UI must explain second-admin permanent-ban review.');

$assert(str_contains($userEndpoint, "'appeal'"), 'Player moderation endpoint must expose appeal submission.');
$assert(str_contains($client, 'moderationSnapshot'), 'Client must expose moderation snapshot.');
$assert(str_contains($client, 'moderationAppeal'), 'Client must expose appeal submission.');
$assert(str_contains($profileUi, 'data-open-moderation-center'), 'Profile must expose the user-facing moderation center.');
$assert(str_contains($profileUi, 'Подать апелляцию'), 'Profile moderation center must expose appeal UX.');

$assert(str_contains($profileEndpoint, "assertAllowed(\$mgwId, 'profile')"), 'Profile updates must enforce profile restrictions.');
$assert(str_contains($friendsEndpoint, "assertAllowed(\$actorMgwId, 'social')"), 'Friend creation must enforce social restrictions.');
$assert(str_contains($invitesEndpoint, "assertAllowed(\$actorMgwId, 'gameplay')"), 'Invite entry must enforce gameplay restrictions.');
$assert(str_contains($apiEndpoint, "assertAllowed(\$moderationMgwId, 'gameplay')"), 'Matchmaking/tournament entry must enforce gameplay restrictions.');
$assert(str_contains($profileEndpoint, 'Read access remains available'), 'Restriction enforcement must preserve read access to the appeal surface.');

fwrite(STDOUT, "MVP-22.3 moderation static contract OK ($assertions assertions).\n");
