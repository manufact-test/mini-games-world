import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const cyrillic = /[\u0400-\u04FF]/;

const entry = read('app/v110.php');
assert.ok(!cyrillic.test(entry), 'Active Telegram v110 entry must contain zero direct Cyrillic source lines');
for (const token of [
  "require_once __DIR__ . '/runtime/localization/LocalizationCatalog.php';",
  "mgw_v110_public_copy('entry.open_failed'",
  "$localizationCatalog->translate('entry.preloader_loading')",
  "$localizationCatalog->translate('entry.tagline')",
  "htmlspecialchars(",
]) {
  assert.ok(entry.includes(token), `Active v110 localization ownership missing: ${token}`);
}

for (const invariant of [
  "$indexPath = __DIR__ . '/index.html';",
  "$manifestPath = __DIR__ . '/runtime/client/version-manifest.php';",
  "['imports' => $imports]",
  "id=\"mgw-localization\"",
  "substr_count($html, '<script type=\"module\" src=\"') !== 1",
  "header('X-MGW-Localization: keys-v1');",
  "header('X-MGW-Locale: ' . $localizationCatalog->defaultLocale());",
]) {
  assert.ok(entry.includes(invariant), `Active v110 entry invariant changed: ${invariant}`);
}

const tombstone = read('app/v120.php');
assert.ok(tombstone.includes("v120 failed production acceptance and must never execute again"),
  'v120 compatibility tombstone ownership changed');
assert.ok(tombstone.includes("$target = '/app/v110.php?v=1123';"),
  'v120 tombstone must continue redirecting stale launches to v110');

const locale = JSON.parse(read('app/locales/ru.json'));
assert.ok(Number(locale?._meta?.version) >= 51, 'RU locale revision must be 51 or newer');
assert.equal(locale?.entry?.open_failed, 'Не удалось открыть Mini Games World. Попробуйте ещё раз через минуту.');
assert.equal(locale?.entry?.preloader_loading, 'Готовим игровую комнату');
assert.equal(locale?.entry?.tagline, 'Те самые игры. То самое чувство.');

const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
assert.equal(Number(baseline.cyrillic_lines_total), 3074, 'Active entry total debt ratchet changed unexpectedly');
assert.equal(Number(baseline.by_scope?.client), 1396, 'Active entry client debt changed unexpectedly');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Active entry backend debt changed unexpectedly');
assert.equal(Number(baseline.by_scope?.['client-entry']), 25, 'Active entry client-entry debt must be 25 after v110 migration');

console.log('MVP-27.1 active client-entry localization contract: OK — factual Telegram v110 entry has zero direct Cyrillic and remains canonically localized.');
