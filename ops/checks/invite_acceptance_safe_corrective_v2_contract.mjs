import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const invites = read('app/assets/js/games/game-invites-v110.js');
const inviteCss = read('app/assets/css/components/game-invites.css');
const mainCss = read('app/assets/css/main.css');
const manifest = read('app/runtime/client/version-manifest.php');
const endpoint = read('bot/invite-opponents.php');
const inviteEndpoint = read('bot/invites.php');
const rematchPolicy = read('app/assets/js/games/game-invites-v110-rematch-policy-v175.js');

assert(
  endpoint.includes("$isAndroidActor = $identityProvider === 'android_device';")
    && endpoint.includes('if ($isAndroidActor && MgwIdGenerator::isValid($actorMgwId))'),
  'Telegram picker must not pay for the Android canonical friend-graph merge.',
);

assert(
  invites.includes("cancelWarmShareDraft();\n      openPlayerPicker(currentContext(), event.currentTarget);")
    && !invites.includes('showPlayerPickerLoading(context, requestGeneration);')
    && invites.includes('Preserve the accepted ready-first picker'),
  'Player picker must keep the accepted ready-first no-loading-frame behavior.',
);

assert(
  invites.includes("prepareMessage:true")
    && invites.includes('tg.shareMessage(preparedId')
    && invites.includes("inviteRequest('confirm_shared'")
    && !invites.includes("if (getInitData()) {\n      currentInvite = null;\n      openFallbackShare(draftInvite);"),
  'Telegram Share must remain native prepared-message sharing with confirmation, never auto-jump to t.me/share.',
);

assert(
  inviteEndpoint.includes("$action !== 'create_link_draft'")
    && inviteEndpoint.includes('not yet shared product state'),
  'Unshared link drafts must not run DB projection on the prepared-share critical path.',
);

assert(
  invites.includes("if (action === 'start') {\n    button.setAttribute('aria-busy', 'true');\n    beginInviteStartTransition();")
    && inviteCss.includes('[data-invite-action="start"].btn.primary:disabled')
    && inviteCss.includes('background:var(--sk-gradient-primary)'),
  'Start must block duplicate input without changing the accepted primary CTA presentation.',
);

const syncStart = invites.indexOf('async function syncNow');
const activeGame = invites.indexOf("if (result?.active_game?.id && String(result.active_game.status || '') === 'active')", syncStart);
const processEvents = invites.indexOf('processInviteEvents(result.invite_events', syncStart);
assert(
  activeGame >= 0 && processEvents >= 0 && activeGame < processEvents
    && invites.includes('if (activeInviteToken) consumeInviteNotification(activeInviteToken);')
    && invites.includes("const inviteToken = String(currentInvite?.token || '');\n  if (inviteToken) consumeInviteNotification(inviteToken);"),
  'Active match entry must consume invite presentation before stale invite events can repaint.',
);

assert(
  rematchPolicy.includes("? t('game_screen.result.new_opponent')")
    && rematchPolicy.includes(": t('game_invites.rematch.play_again')"),
  'Direct rematch and new-opponent result actions must have distinct labels.',
);

assert(
  mainCss.includes("game-invites.css?v=86&invite_acceptance=safe-v2")
    && manifest.includes('game-invites-v110-rematch-policy-v175.js?v=4')
    && manifest.includes('game-invites-v110.js?v=1151')
    && (manifest.match(/invite_acceptance=safe-v2/g) || []).length >= 3,
  'Safe corrective assets must publish fresh cache identities.',
);

console.log('INVITE_ACCEPTANCE_SAFE_CORRECTIVE_V2=PASS');
