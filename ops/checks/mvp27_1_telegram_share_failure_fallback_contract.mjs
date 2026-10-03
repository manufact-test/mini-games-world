import fs from 'node:fs';

const invitePath = 'app/assets/js/games/game-invites-v110.js';
const manifestPath = 'app/runtime/client/version-manifest.php';
const invites = fs.readFileSync(invitePath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(
  invites.includes("tg.onEvent('shareMessageFailed', event => {") &&
  invites.includes("settleNativeShare(false, String(event?.error || 'UNKNOWN_ERROR'))"),
  'Telegram failure-event must remain the authoritative detailed failure owner.',
);

const nativeStart = invites.indexOf('function openNativeShare(');
const settleStart = invites.indexOf('function settleNativeShare(', nativeStart);
assert(nativeStart >= 0 && settleStart > nativeStart, 'Native Share ownership functions are missing.');
const nativeBlock = invites.slice(nativeStart, settleStart);

assert(
  nativeBlock.includes("if (result === true) settleNativeShare(true, '', attempt);"),
  'shareMessage callback may settle success only.',
);
assert(
  !nativeBlock.includes("result === false ? 'USER_DECLINED'") &&
  !nativeBlock.includes('settleNativeShare(Boolean(result)'),
  'A boolean false callback must never be reinterpreted as USER_DECLINED.',
);
assert(
  nativeBlock.includes('showPreparedLink(attempt.invite, attempt.context);'),
  'A missing native callback/event must fall back to the prepared link surface.',
);

const confirmStart = invites.indexOf('async function confirmSharedInvite(', settleStart);
assert(confirmStart > settleStart, 'Share settlement boundary is missing.');
const settleBlock = invites.slice(settleStart, confirmStart);

assert(
  settleBlock.includes("String(errorCode || '') === 'USER_DECLINED'") &&
  settleBlock.includes('restoreWarmShareDraft(attempt);') &&
  settleBlock.includes('openInviteSetup(attempt.context.gameType, attempt.context);'),
  'Only explicit USER_DECLINED may silently restore invite setup.',
);
assert(
  settleBlock.includes('showPreparedLink(attempt.invite, attempt.context);'),
  'Technical native Share failures must expose the canonical link fallback.',
);
assert(
  !settleBlock.includes('void discardDraft(attempt.invite);'),
  'Technical native Share failure must not discard the still-usable link draft.',
);

const mappings = manifest
  .split('\n')
  .filter(line => line.includes("game-invites-v110.js?v=") && line.includes("=> './assets/js/games/game-invites-v110.js?v=1150"));
assert(mappings.length === 3, `Expected 3 canonical invite aliases, got ${mappings.length}.`);
assert(
  mappings.every(line => line.includes('&share_failure=technical-fallback-v1')),
  'Every factual canonical invite alias must publish the Share corrective cache identity.',
);

console.log('MVP27_1_TELEGRAM_SHARE_FAILURE_FALLBACK_CONTRACT=PASS');
