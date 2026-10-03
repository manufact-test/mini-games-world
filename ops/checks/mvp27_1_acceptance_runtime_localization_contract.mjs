import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const owner = read('app/assets/js/production-v110-acceptance-runtime.js');
const manifestPhp = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert(!/[\u0400-\u04FF]/.test(owner),
  'Active Acceptance Runtime must contain zero hardcoded Cyrillic.');
assert(owner.includes("import { t } from '@mgw/i18n';"),
  'Acceptance Runtime must use canonical @mgw/i18n.');
assert(owner.includes("t('acceptance_runtime.search.primary'") &&
  owner.includes("t('acceptance_runtime.search.board'") &&
  owner.includes("t('acceptance_runtime.search.domino_classic')"),
  'Search summary copy must resolve through canonical localization keys.');
assert(owner.includes("'games.tictactoe.name'") &&
  owner.includes("'games.four_in_a_row.name'") &&
  owner.includes("'games.battleship.name'") &&
  owner.includes("'games.domino.name'"),
  'Acceptance Runtime must reuse canonical game-name localization ownership.');
assert(owner.includes("t('game_screen.timer_seconds', { count:seconds })"),
  'Shared header clock must reuse canonical timer localization.');

for (const key of [
  'timeout_title','timeout_note','upcoming_title','prepare_note','first_move_note',
  'syncing_title','syncing_note','ready_title','ready_note',
]) {
  assert(owner.includes(\`acceptance_runtime.launch.\${key}\`),
    'Launch presentation must resolve localized key: ' + key);
}

assert(owner.includes('const LAUNCH_COUNTDOWN_STEP_MS = 1000;') &&
  owner.includes('const LAUNCH_READY_HOLD_MS = 260;') &&
  owner.includes('const TTT_FIRST_TAP_GRACE_MS = 400;'),
  'Accepted launch and first-tap timing constants must remain frozen.');
assert(owner.includes("document.addEventListener('mgw:phase-b-game-entering', primeLaunchState);") &&
  owner.includes("document.addEventListener('mgw:prime-launch-feedback', primeLaunchFeedback);") &&
  owner.includes("document.addEventListener('mgw:v110-ttt-clock-snapshot', acceptPendingClockSnapshot);"),
  'Accepted Phase-B and TTT event ownership must remain unchanged.');
assert(owner.includes('function guardPhaseBPreStartControls(event)') &&
  owner.includes('function guardAndTrackTicTacToe(event)') &&
  owner.includes('function validTicTacToeMove(button, { requireLaunch = true } = {})') &&
  owner.includes('queueDeferredTicTacToeTap(boardControl, game);') &&
  owner.includes('if (delay === null || delay > TTT_FIRST_TAP_GRACE_MS) return false;'),
  'Accepted action guard and deferred first-tap mechanics must remain unchanged.');
assert(owner.includes('immutable local projection of the authoritative server') &&
  owner.includes('function launchAllowsAction(game)') &&
  owner.includes('function launchAllowsLeave(game)') &&
  owner.includes('function launchPresentationStage(presentation, phase)'),
  'Clock projection and launch gating owners must remain unchanged.');

assert(Number(ru._meta?.version || 0) >= 45,
  'RU locale revision must retain Acceptance Runtime localization v45 or newer.');
assert(ru.acceptance_runtime?.search?.room_gold === 'Gold-комната',
  'Accepted RU Gold room copy must remain unchanged.');
assert(ru.acceptance_runtime?.search?.primary === '{game} · {room} · участие {bet} коинов',
  'Accepted RU search summary format must remain unchanged.');
assert(ru.acceptance_runtime?.launch?.upcoming_title === 'Матч скоро начнётся' &&
  ru.acceptance_runtime?.launch?.ready_title === 'Всё готово',
  'Accepted RU launch handoff copy must remain unchanged.');
assert(ru.game_screen?.timer_seconds === '{count} сек',
  'Canonical shared timer copy must remain unchanged.');

assert(manifestPhp.includes("./assets/js/production-v110-acceptance-runtime.js?v=133&clock=battleship-setup-single-writer&launch=server-active-gated-v3&terminal=clock-stable&input=first-tap-v1&mvp21_5=countdown-10-fresh60-v2&mvp27_1=acceptance-runtime-localized-v1"),
  'Canonical manifest must publish localized Acceptance Runtime while preserving accepted mechanics markers.');

assert(Number(baseline.cyrillic_lines_total) <= 3355 &&
  Number(baseline.by_scope?.client) <= 1674,
  'Acceptance Runtime localization debt must never regress above the accepted post-slice baseline.');
assert(Number(baseline.by_scope?.backend) <= 1653 &&
  Number(baseline.by_scope?.['client-entry']) <= 28,
  'Acceptance Runtime localization must not increase backend or client-entry debt.');

console.log('MVP27_1_ACCEPTANCE_RUNTIME_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_ACCEPTANCE_RUNTIME_HARDCODED_CYRILLIC=0');
