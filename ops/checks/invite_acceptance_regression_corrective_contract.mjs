import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const endpoint = read('bot/invite-opponents.php');
const service = read('bot/services/InviteOpponentService.php');
const invites = read('app/assets/js/games/game-invites-v110.js');
const inviteCss = read('app/assets/css/components/game-invites.css');
const mainCss = read('app/assets/css/main.css');
const manifest = read('app/runtime/client/version-manifest.php');

assert(
  endpoint.includes("$identityProvider = strtolower(trim((string)($tgUser['mgw_identity_provider'] ?? '')))") &&
  endpoint.includes("$isAndroidActor = $identityProvider === 'android_device';") &&
  endpoint.includes('if ($isAndroidActor && MgwIdGenerator::isValid($actorMgwId))'),
  'Expensive canonical-friends merge must remain Android-only; Telegram keeps the fast runtime picker.',
);
assert(
  service.includes('bool $includeTestUsers = false') &&
  service.includes("(!$includeTestUsers && $this->isStagingTestUser($candidateId, $candidate))") &&
  service.includes("'stg_test_player_'") &&
  service.includes("'stg_tour_'") &&
  service.includes("'dev_'"),
  'Real-player picker must exclude staging/dev identities while test actors can opt in.',
);

assert(
  invites.includes("inviteRequest('create_link_draft', { ...normalized, prepareMessage:false }, { prefetch:true })"),
  'Default Share prewarm must not call Telegram PreparedInlineMessage.',
);
assert(
  invites.includes('if (getInitData()) {') &&
  invites.includes('openFallbackShare(draftInvite);'),
  'Real Telegram Share must open the ordinary Telegram recipient surface immediately.',
);
assert(
  invites.includes("document.querySelector('[data-open-player-picker]')?.addEventListener('click', event => {\n      cancelWarmShareDraft();") &&
  invites.includes("async function createDirectInvite(context, inviteeId, button, opponentNameOverride = ''){\n  if (!inviteeId || button.disabled) return;\n  cancelWarmShareDraft();"),
  'Direct-player path must cancel irrelevant Share prewarm.',
);

assert(
  invites.includes("const inviteToken = String(currentInvite?.token || '');\n  if (inviteToken) consumeInviteNotification(inviteToken);") &&
  invites.indexOf("if (result?.active_game?.id && String(result.active_game.status || '') === 'active')") <
    invites.indexOf('processInviteEvents(result.invite_events', invites.indexOf('async function syncNow')),
  'Active game must consume invite presentation before stale invite events can repaint.',
);
assert(
  invites.includes("if (action === 'start') {\n    button.setAttribute('aria-busy', 'true');\n    beginInviteStartTransition();"),
  'Start must block duplicate input without replacing the accepted CTA copy.',
);
assert(
  inviteCss.includes('[data-invite-action="start"].btn.primary:disabled') &&
  inviteCss.includes('background:var(--sk-gradient-primary)') &&
  inviteCss.includes('-webkit-text-fill-color:#fff'),
  'Busy Start must keep the accepted bright primary presentation.',
);
assert(
  mainCss.includes("game-invites.css?v=86&invite_acceptance=corrective-v1") &&
  (manifest.match(/invite_acceptance=corrective-v1/g) || []).length >= 4,
  'Corrective JS and CSS must publish fresh client cache identities.',
);

console.log('INVITE_ACCEPTANCE_REGRESSION_CORRECTIVE=PASS');
