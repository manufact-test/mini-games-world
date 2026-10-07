import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const bootstrap = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const wrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const polish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const target = read('app/assets/js/production-clean-entry-v110.js');
const locale = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on factual v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must keep replacing legacy entry scripts with canonical bootstrap');
assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must retain exactly canonical clean-entry and main owners'
);

const cleanAlias = manifest.match(/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js\?v=(\d+)([^']*)'/);
assert.ok(cleanAlias && Number(cleanAlias[1]) >= 2 && cleanAlias[2].includes('mvp27_1=profile-selection-localized-v1'),
  'Manifest must publish the localized canonical clean-entry wrapper identity or a newer successor');
const polishImport = wrapper.match(/production-clean-entry-v110-mvp19-3-final-polish\.js\?v=(\d+)([^']*)/);
assert.ok(polishImport && Number(polishImport[1]) >= 1140 && polishImport[2].includes('mvp27_1=profile-selection-localized-v1'),
  'Canonical wrapper must publish the localized final-polish identity or a newer successor');
const targetImport = polish.match(/production-clean-entry-v110\.js\?v=(\d+)([^']*)/);
assert.ok(targetImport && Number(targetImport[1]) >= 1132 && targetImport[2].includes('mvp27_1=profile-selection-localized-v1'),
  'Final-polish must publish the localized active clean-entry identity or a newer successor');

assert.ok(target.includes("import { t } from '@mgw/i18n';"),
  'Active clean-entry must consume canonical @mgw/i18n');
assert.equal(countCyrillicLines(target), 0,
  'Active clean-entry must contain zero hardcoded Cyrillic after localization');

for (const token of [
  "document.addEventListener('mgw:app-ready'",
  "storeAvatarObserver = new MutationObserver(scheduleStoreAvatarDecoration)",
  "document.addEventListener('click', handleStoreAvatarSelection, true)",
  "card.classList.toggle('equipped', active)",
  "action.dataset.mgwStoreAvatarSelect = itemId",
  "action.disabled = active && !removable",
  "action.setAttribute('aria-pressed', active ? 'true' : 'false')",
  "const remove = itemId !== '' && itemId === selectedItemId && itemId !== DEFAULT_AVATAR_ITEM_ID",
  "const nextItemId = remove ? DEFAULT_AVATAR_ITEM_ID : itemId",
  "storeAvatarQueuedIntent = { itemId, removed:Boolean(removed) }",
  "if (!storeAvatarSaving) void drainStoreAvatarSelectionQueue()",
  "const result = await api.profileV2({ avatar_item_id:intent.itemId })",
  "if (pending?.itemId) applyStoreAvatarOptimistic(pending.itemId)",
  "state.user = mergeCanonicalMgwUser(state.user, result?.user || {}, state.mgwProfile)",
  "initMgwProfileBackgroundsOnDemand()",
]) assert.ok(target.includes(token), 'Active Store/Profile ownership invariant changed: ' + token);

assert.ok(
  target.includes('while (storeAvatarQueuedIntent)')
    && target.includes('storeAvatarQueuedIntent = null;')
    && target.includes('storeAvatarSaving = true;')
    && target.includes('storeAvatarSaving = false;'),
  'Active avatar selection must serialize server writes while latest visible intent remains replaceable.'
);

assert.ok(
  target.includes("mgw:avatar-selection-changed")
    && !target.includes("store.profile_selection.avatar.toast.removed")
    && !target.includes("store.profile_selection.avatar.toast.selected")
    && !/\btoast\s*\(/.test(target),
  'Avatar selection/removal must publish shared state silently with no delayed toast feedback.'
);

for (const key of [
  'store.profile_selection.status.selected',
  'store.profile_selection.status.owned',
  'store.profile_selection.actions.remove',
  'store.profile_selection.actions.selected',
  'store.profile_selection.actions.select',
  'store.profile_selection.avatar.errors.unconfirmed',
  'store.profile_selection.avatar.errors.remove',
  'store.profile_selection.avatar.errors.select',
]) assert.ok(target.includes(`t('${key}')`), 'Active clean-entry must use locale key: ' + key);

assert.ok(target.includes(
  "const selected = actionLabel === t('store.profile_selection.actions.remove') || actionLabel === t('store.profile_selection.actions.selected') || actionLabel === t('store.profile_selection.status.selected');"
), 'Localized sheet-state detection must preserve the prior visible-label ownership semantics');

assert.ok(Number(locale?._meta?.version) >= 53, 'RU locale revision must include active clean-entry localization');
assert.deepEqual(locale?.store?.profile_selection, {
  status:{
    selected:'Выбрано',
    owned:'В коллекции',
  },
  actions:{
    remove:'Снять',
    selected:'Выбрана',
    select:'Выбрать',
  },
  avatar:{
    toast:{
      removed:'Аватарка снята.',
      selected:'Аватарка выбрана.',
    },
    errors:{
      unconfirmed:'Профиль не подтвердил выбранную аватарку.',
      remove:'Не удалось снять аватарку.',
      select:'Не удалось выбрать аватарку.',
    },
  },
}, 'RU Store/Profile selection copy must preserve exact accepted visible wording');

assert.ok(Number(baseline.scanned_files) <= 713, 'Active clean-entry successor must not restore classified runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1852, 'Active clean-entry successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 199, 'Active clean-entry successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 active clean-entry profile selection localization: OK — 11 factual v110 Cyrillic lines moved to canonical locale ownership with Store/Profile behavior preserved.');
