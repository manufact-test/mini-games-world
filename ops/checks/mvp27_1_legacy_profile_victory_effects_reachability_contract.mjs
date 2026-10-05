import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const CANDIDATE = 'app/assets/js/profile/mgw-profile-victory-effects.js';
const BASENAME = 'mgw-profile-victory-effects.js';
const CANONICAL_SPECIFIER = './assets/js/profile/mgw-profile-victory-effects.js?v=1&mvp19_3=victory-effects';
const ACTIVE_WRAPPER = 'app/assets/js/profile/mgw-profile-victory-effects-card-parity.js';
const ACTIVE_OWNER = 'app/assets/js/profile/mgw-profile-victory-effects-v4.js';

const read = p => fs.readFileSync(p, 'utf8');
const normalized = p => p.split(path.sep).join('/');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

function collect(dir, extensions, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collect(full, extensions, out);
    else if (entry.isFile() && extensions.has(path.extname(entry.name))) out.push(normalized(full));
  }
  return out;
}

const appSources = collect('app', new Set(['.js', '.php'])).sort();
const refs = [];
for (const file of appSources) {
  if (file === CANDIDATE) continue;
  read(file).split(/\r?\n/).forEach((line, index) => {
    if (line.includes(BASENAME)) refs.push({ file, line:index + 1, text:line.trim() });
  });
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const manifest = read('app/runtime/client/version-manifest.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const wrapper = read(ACTIVE_WRAPPER);
const activeOwner = read(ACTIVE_OWNER);
const candidate = read(CANDIDATE);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must retain canonical bootstrap replacement');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(!v110.includes(BASENAME), 'Factual v110 HTML entry must not directly load the physical legacy Victory Effects source');

assert.ok(manifest.includes(CANONICAL_SPECIFIER), 'Version manifest must retain the canonical legacy Victory Effects specifier key');
assert.ok(
  manifest.includes("mgw-profile-victory-effects-card-parity.js?v=13")
    && manifest.includes('mvp27_1=localized-v1'),
  'Canonical Victory Effects specifier must resolve to the localized card-parity wrapper'
);
assert.ok(
  wrapper.includes("mgw-profile-victory-effects-v4.js?v=3")
    && wrapper.includes('mvp27_1=localized-v1'),
  'Active card-parity wrapper must resolve to localized v4 owner'
);
assert.ok(activeOwner.includes("from '@mgw/i18n'"), 'Active v4 Victory Effects owner must use canonical i18n');
assert.equal(countCyrillicLines(activeOwner), 0, 'Active v4 Victory Effects owner must remain free of hardcoded Cyrillic');

assert.equal(countCyrillicLines(candidate), 15,
  'Legacy physical Victory Effects Cyrillic evidence count changed; re-prove before changing debt');
assert.ok(candidate.includes('export function initMgwProfileVictoryEffects()'),
  'Legacy physical Victory Effects source must retain implementation evidence');

const unsafeJsRefs = refs.filter(ref =>
  ref.file.endsWith('.js')
  && !ref.text.includes('mgw-profile-victory-effects.js?v=1&mvp19_3=victory-effects')
);
assert.deepEqual(unsafeJsRefs, [],
  'A JS owner bypasses the canonical mapped Victory Effects specifier and may load the physical legacy source');

const phpRefFiles = [...new Set(refs.filter(ref => ref.file.endsWith('.php')).map(ref => ref.file))];
assert.deepEqual(phpRefFiles, ['app/runtime/client/version-manifest.php'],
  'Physical legacy Victory Effects source acquired an unexpected PHP/HTML runtime reference');

assert.ok(audit.includes("'app/assets/js/profile/mgw-profile-victory-effects.js'"),
  'Hardcoded-text audit must classify the physical legacy Victory Effects source');

assert.ok(Number(baseline.scanned_files) <= 723, 'Victory Effects reachability successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1992, 'Victory Effects reachability total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 339, 'Victory Effects reachability client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy Profile Victory Effects reachability: OK — physical legacy source is shadowed by canonical manifest resolution to localized card-parity -> v4 ownership.');
console.log('MVP27_1_LEGACY_PROFILE_VICTORY_EFFECTS_REFS=' + JSON.stringify(refs));
