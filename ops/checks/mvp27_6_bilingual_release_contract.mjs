import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createI18n } from '../../app/assets/js/localization/i18n.js';

// MVP-27.6 release QA. This checks actual runtime interpolation/formatting,
// both full player-facing catalogs and an exact classified legacy/server remainder.
const json = path => JSON.parse(readFileSync(path, 'utf8'));
const manifest = json('app/locales/manifest.json');
const ru = json('app/locales/ru.json');
const en = json('app/locales/en.json');
const payload = { manifest, catalogs: { ru, en } };
const flatten = (value, prefix = '', output = new Map()) => {
  for (const [name, entry] of Object.entries(value)) {
    const key = prefix ? prefix + '.' + name : name;
    if (entry && typeof entry === 'object' && !Array.isArray(entry)) {
      flatten(entry, key, output);
    } else if (!key.startsWith('_meta.')) {
      assert.equal(typeof entry, 'string', 'Non-string catalog value: ' + key);
      output.set(key, entry);
    }
  }
  return output;
};
const tokens = value => [...String(value).matchAll(/\{([A-Za-z0-9_]+)\}/g)]
  .map(match => match[1]).sort();
const ruLeaves = flatten(ru), enLeaves = flatten(en);

// These are not currently English player-facing owners: legacy Match/Gold/prize
// paths and Admin-only feature diagnostics. The EXACT list is pinned so the
// exception cannot silently expand. Final manual QA must still check reachability.
const classifiedServerRemainder = new Set([
  'server.feature_flags.admin.maintenance',
  'server.feature_flags.admin.finance_read_only',
  'server.feature_flags.admin.feature_disabled',
  'server.feature_flags.admin.game_disabled',
  'server.feature_flags.admin.db_runtime_enabled',
  'server.shop_history.reject_reason_missing',
  'server.shop_history.prize_fallback',
  'server.shop_history.statuses.pending',
  'server.shop_history.statuses.processing',
  'server.shop_history.statuses.done',
  'server.shop_history.statuses.rejected',
  'server.shop_history.statuses.cancelled',
  'server.shop_history.statuses.unknown',
  'server.shop_history.invalid_request',
  'server.shop_history.user_not_found',
  'server.payment.match_rate_label',
  'server.payment.statuses.pending',
  'server.payment.statuses.paid',
  'server.payment.statuses.rejected',
  'server.payment.statuses.cancelled',
  'server.shop_catalog.invalid_prize',
  'server.shop_catalog.prize_unavailable',
  'server.shop_catalog.denomination_unavailable',
  'server.prizes.countries.RU',
  'server.prizes.countries.BY',
  'server.prizes.countries.WORLD',
  'server.prizes.ozon_ru.title',
  'server.prizes.ozon_ru.description',
  'server.prizes.ozon_ru.image_alt',
  'server.prizes.wildberries_ru.title',
  'server.prizes.wildberries_ru.description',
  'server.prizes.wildberries_ru.image_alt',
  'server.prizes.wildberries_by.title',
  'server.prizes.wildberries_by.description',
  'server.prizes.wildberries_by.image_alt',
  'server.prizes.aliexpress_world.title',
  'server.prizes.aliexpress_world.description',
  'server.prizes.aliexpress_world.image_alt',
  'server.response.generic_failed',
  'server.response.payment_created_balance_unchanged',
  'server.response.balance_after_admin',
  'server.response.legacy.prize_order',
  'server.response.legacy.prize_order_prefix',
  'server.response.legacy.prize_store_prefix',
  'server.response.legacy.balance_operation',
  'server.response.legacy.first_match_coins',
  'server.response.legacy.starter_bonus',
]);
const missing = [...ruLeaves.keys()].filter(key => !enLeaves.has(key)).sort();
assert.deepEqual(missing, [...classifiedServerRemainder].sort(),
  'Untranslated RU keys have changed; classify new live ownership before extending this allowlist.');
assert.deepEqual([...enLeaves.keys()].filter(key => !ruLeaves.has(key)), [],
  'English catalog has orphaned keys.');
const parserAliases = new Set([
  'games.router.aliases.domino', 'games.router.aliases.go',
  'games.router.aliases.chess', 'games.router.aliases.reversi',
  'games.router.aliases.checkers', 'games.router.aliases.battleship',
  'games.router.aliases.four_in_a_row',
]);
for (const [key, english] of enLeaves) {
  const russian = ruLeaves.get(key);
  assert.equal(typeof russian, 'string', 'Missing RU owner: ' + key);
  assert.deepEqual(tokens(english), tokens(russian), 'Placeholder mismatch: ' + key);
  assert.ok(russian === '' ? english === '' : english.trim() !== '',
    'Missing nonempty English translation: ' + key);
  if (parserAliases.has(key)) {
    assert.equal(english, russian, 'Historic Russian parser alias must remain compatible: ' + key);
  } else {
    assert.doesNotMatch(english, /[\u0400-\u052f]/u, 'Russian copy leaked into English: ' + key);
  }
}
assert.deepEqual(manifest.supported_locales, ['ru', 'en']);
assert.equal(manifest.default_locale, 'ru');
assert.equal(manifest.fallback_locale, 'ru');
assert.equal(en._meta.status, 'active');

const russian = createI18n(payload, 'ru-RU');
const english = createI18n(payload, 'en-US');
assert.equal(russian.locale, 'ru');
assert.equal(english.locale, 'en');
assert.equal(createI18n(payload, 'pl-PL').locale, 'ru', 'Unsupported OS locale must fall back to Russian.');
assert.equal(createI18n(payload).locale, 'ru', 'Unspecified locale must use RU default.');
assert.throws(() => english.t('missing.release.qa.key'), /Missing translation key/);

const games = [
  'chess','checkers','reversi','go','domino',
  'tictactoe','four_in_a_row','battleship',
].sort();
assert.deepEqual(Object.keys(manifest.rules.games).sort(), games);
for (const game of games) {
  const meta = manifest.rules.games[game];
  assert.deepEqual([...meta.languages].sort(), ['en','ru']);
  for (const locale of [russian, english]) {
    const rule = locale.rules(game);
    assert.equal(rule.locale, locale.locale);
    assert.equal(rule.title, locale.t(meta.title_key));
    assert.ok(rule.title.trim().length > 0);
  }
}

for (const [locale, cases] of [
  [russian, [[1, '1 коин'], [2, '2 коина'], [5, '5 коинов'], [21, '21 коин']]],
  [english, [[1, '1 coin'], [2, '2 coins'], [5, '5 coins'], [21, '21 coins']]],
]) {
  for (const [count, expected] of cases) {
    assert.equal(locale.plural('units.coin', count), expected,
      'Localized coin plural (' + locale.locale + '/' + count + ')');
  }
  assert.ok(locale.formatNumber(1234567).replace(/\u00a0|\u202f|\s/g, ' ').length >= 7);
  for (const style of ['short', 'long']) {
    assert.ok(locale.formatDate(new Date('2026-10-09T12:30:00Z'), style).length > 6);
    assert.ok(locale.formatDateTime(new Date('2026-10-09T12:30:00Z'), style).length > 8);
  }
}
assert.equal(english.formatNumber(1234567), '1,234,567');
assert.notEqual(russian.formatDate('2026-10-09T12:30:00Z', 'long'),
  english.formatDate('2026-10-09T12:30:00Z', 'long'));
assert.equal(english.t('game_invites' in en ? 'settings.language_saved' : 'settings.language_saved'),
  en.settings.language_saved);
assert.equal(english.t('notifications.unread_count', { count: 7 }).includes('7'), true);
console.log('MVP-27.6 bilingual runtime catalog/8 games/placeholders/plural/date/fallback PASS; ' +
  enLeaves.size + ' EN leaves, ' + missing.length + ' classified legacy/admin RU-only keys.');
