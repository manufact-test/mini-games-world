import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(path, 'utf8');
const en = JSON.parse(read('app/locales/en.json'));
const ru = JSON.parse(read('app/locales/ru.json'));
const owners = [
  'mgw-profile-backgrounds',
  'mgw-profile-badges',
  'mgw-profile-frames',
  'mgw-profile-reactions',
  'mgw-profile-entry-effects',
  'mgw-profile-victory-effects-v4',
];
const ids = [
  'starter-default-01','starter-default-02','starter-default-03',
  'store-avatar-01','store-avatar-02','store-avatar-03','store-avatar-04',
  'profile-background-01','profile-background-02','profile-background-03','profile-background-04',
  'profile-badge-spark','profile-badge-crest','profile-badge-pulse',
  'profile-entry-effect-01','profile-entry-effect-02','profile-entry-effect-03',
  'profile-victory-effect-01','profile-victory-effect-02','profile-victory-effect-03',
  'profile-reaction-wave','profile-reaction-clap','profile-reaction-heart',
  'profile-reaction-fire','profile-reaction-pack-4','profile-reaction-pack-large',
];
for (const id of ids) {
  assert.ok(ru.store.products[id], `Missing RU product: ${id}`);
  assert.ok(en.store.products[id], `Missing EN product: ${id}`);
  assert.notEqual(ru.store.products[id], en.store.products[id], `Untranslated product: ${id}`);
}
assert.equal(en.store.products['profile-background-04'], 'Quantum Storm');
assert.equal(ru.store.products['profile-background-04'], 'Квантовый шторм');
for (const locale of [en,ru]) {
  assert.ok(locale.shell.home_match_room_label);
  assert.ok(locale.profile.reactions.item_subtitles.single);
  assert.ok(locale.profile.reactions.item_subtitles.pack4);
  assert.ok(locale.profile.reactions.item_subtitles.pack8);
}
for (const name of owners) {
  const src = read(`app/assets/js/profile/${name}.js`);
  assert.match(src, /mgw:locale-changed/);
  assert.match(src, /getI18n\(\)\.locale/, `Missing locale in cosmetic cache signature: ${name}`);
  assert.match(src, /scheduleDecorate/);
}
for (const name of ['mgw-profile-reactions','mgw-profile-entry-effects','mgw-profile-victory-effects-v4']) {
  assert.match(read(`app/assets/js/profile/${name}.js`), /store\.products\.\$\{itemId\}/, `Catalog owner missing: ${name}`);
}
const home = read('app/assets/js/screens/home-screen.js');
assert.match(home, /mgw:locale-changed[\s\S]{0,130}renderStats\(state\.stats\)/);
const weekly = read('app/assets/js/screens/weekly-match-info.js');
assert.match(weekly, /mgw:locale-changed[\s\S]{0,80}syncWeeklyMatchButton/);
const store = read('app/assets/js/screens/store-screen.js');
assert.match(store, /mgw:locale-changed[\s\S]{0,900}renderStore\(\)/);
assert.match(store, /labelKey:tab\.labelKey/);
assert.match(store, /localizedOfferName\(offer, t\('store\.profile\.avatar_name'/);
const runtime = read('app/assets/js/localization/runtime-dom.js');
assert.match(runtime, /home_match_room_label/);
assert.match(runtime, /weekly_match\.button_aria/);
assert.match(read('app/assets/js/localization/i18n.js'), /mgw:locale-changed/);
console.log('MVP-27.2 in-place locale and cosmetic cards: PASS');

/* Regression: CSS used to hide real translations and paint hard-coded RU via ::before/after.
 * The accepted cosmetics visuals remain CSS-owned, but player copy is catalog-owned. */
for (const path of [
  'app/assets/css/production-v105-store-entry-effects-polish.css',
  'app/assets/css/production-v110-victory-effects-card-parity.css',
  'app/assets/css/production-v110-profile-store-visual-repair-v2.css',
  'app/assets/css/production-v111-profile-avatar-geometry.css',
  'app/assets/css/components/mgw-profile-backgrounds.css',
  'app/assets/css/main.css',
]) {
  const css = read(path);
  assert.doesNotMatch(css, /content\s*:\s*['"][^'"]*[А-Яа-яЁё]/, `Russian CSS pseudo-copy leaked: ${path}`);
}
assert.match(read('app/assets/css/components/mgw-profile-backgrounds.css'), /content:attr\(data-mgw-profile-subtitle\)/);
for (const path of ['app/assets/js/screens/profile-screen-v110.js', 'app/assets/js/profile/mgw-profile-badges.js', 'app/assets/js/profile/mgw-profile-frames.js']) {
  assert.match(read(path), /data-mgw-profile-subtitle/);
}
for (const lang of [en,ru]) {
  assert.ok(lang.profile.collection.avatar_subtitles.starter);
  assert.ok(lang.profile.collection.avatar_subtitles.purchased);
}
assert.equal(en.store.products['profile-entry-effect-01'], 'Celestial Gate');
assert.equal(ru.store.products['profile-entry-effect-01'], 'Небесные врата');
console.log('MVP-27.2 CSS-owned Russian copy regression: PASS');

for (const [path, marker] of [
  ['app/assets/css/main.css', 'mgw-profile-backgrounds.css?v=3'],
  ['app/assets/css/production-v106-store-avatar-frame-density.css', 'production-v105-store-entry-effects-polish.css?v=7'],
  ['app/assets/css/production-v107-four-ios-grid-owned.css', 'production-v106-store-avatar-frame-density.css?v=12'],
  ['app/assets/css/production-v108-profile-entry-preview-live-owner.css', 'production-v107-four-ios-grid-owned.css?v=2'],
  ['app/assets/css/production-v108-profile-entry-preview-live-owner-checkers-fit.css', 'production-v108-profile-entry-preview-live-owner.css?v=2'],
  ['app/assets/css/production-v108-profile-entry-preview-live-owner-checkers-fit-granite-v2.css', 'production-v108-profile-entry-preview-live-owner-checkers-fit.css?v=13'],
]) assert.ok(read(path).includes(marker), `Missing CSS cache invalidation ${path}`);
for (const marker of ['mvp27_2_pseudo_copy=v1', 'mgw-profile-victory-effects-card-parity.js']) {
  assert.ok(read('app/runtime/client/version-manifest.php').includes(marker));
}
assert.match(read('app/assets/css/main.css'), /content:attr\(data-mgw-frame-subtitle\)/);
assert.match(read('app/assets/js/profile/mgw-profile-frames.js'), /data-mgw-frame-subtitle/);
