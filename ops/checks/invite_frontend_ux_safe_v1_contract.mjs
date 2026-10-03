import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (ok, message) => { if (!ok) throw new Error(message); };

const invites = read('app/assets/js/games/game-invites-v110.js');
const css = read('app/assets/css/components/game-invites.css');
const mainCss = read('app/assets/css/main.css');
const manifest = read('app/runtime/client/version-manifest.php');
const rematch = read('app/assets/js/games/game-invites-v110-rematch-policy-v175.js');
const startContract = read('bot/tests/ProductionV110InviteStartSyncOwnershipContractTest.php');
const pickerE2e = read('e2e/staging/d1-real-user-regressions-v127.spec.mjs');
const twoContext = read('e2e/staging/two-context.spec.mjs');

assert(
  invites.includes("inviteRequest('create_link_draft', { ...normalized, prepareMessage:true }, { prefetch:true })")
    && invites.includes('tg.shareMessage(preparedId')
    && invites.includes("inviteRequest('confirm_shared'")
    && invites.includes('showOwnerWaiting(currentInvite);')
    && !invites.includes("if (getInitData()) {\n      currentInvite = null;\n      openFallbackShare(draftInvite);"),
  'Accepted native prepared Telegram Share and sender waiting ownership must remain intact.'
);

assert(
  invites.includes("cancelWarmShareDraft();\n      openPlayerPicker(currentContext(), event.currentTarget);")
    && invites.includes('Preserve the accepted ready-first picker')
    && !invites.includes('showPlayerPickerLoading(context, requestGeneration);')
    && pickerE2e.includes('Never replace it with an empty/loading player-picker frame'),
  'Player picker must keep the accepted ready-first presentation.'
);

assert(
  invites.includes("if (action === 'start') {\n    button.setAttribute('aria-busy', 'true');\n    beginInviteStartTransition();")
    && startContract.includes("button.setAttribute('aria-busy', 'true')")
    && css.includes('[data-invite-action="start"].btn.primary:disabled')
    && css.includes('background:var(--sk-gradient-primary)'),
  'Explicit Start must keep accepted copy/primary presentation while duplicate input is blocked.'
);

const syncStart = invites.indexOf('async function syncNow');
const activeGame = invites.indexOf("if (result?.active_game?.id && String(result.active_game.status || '') === 'active')", syncStart);
const processEvents = invites.indexOf('processInviteEvents(result.invite_events', syncStart);
assert(
  activeGame >= 0 && processEvents >= 0 && activeGame < processEvents
    && invites.includes('if (activeInviteToken) consumeInviteNotification(activeInviteToken);')
    && invites.includes("const inviteToken = String(currentInvite?.token || '');\n  if (inviteToken) consumeInviteNotification(inviteToken);"),
  'Active game must consume invite presentation before stale invite events can repaint.'
);

assert(
  rematch.includes("? t('game_screen.result.new_opponent')")
    && rematch.includes(": t('game_invites.rematch.play_again')")
    && twoContext.includes("toHaveText('Сыграть ещё'")
    && twoContext.includes("toHaveText('Найти нового соперника')")
    && twoContext.includes("hasText:'Сыграть ещё' })).toHaveCount(1)"),
  'Result sheet must keep rematch and new-opponent actions distinct with only one Play Again label.'
);

assert(
  mainCss.includes("game-invites.css?v=86&invite_acceptance=safe-v2")
    && manifest.includes('game-invites-v110-rematch-policy-v175.js?v=3')
    && manifest.includes('game-invites-v110.js?v=1150')
    && (manifest.match(/invite_acceptance=safe-v2/g) || []).length >= 5,
  'Frontend corrective must publish fresh cache suffixes without changing frozen numeric owner versions.'
);

console.log('INVITE_FRONTEND_UX_SAFE_V1=PASS');
