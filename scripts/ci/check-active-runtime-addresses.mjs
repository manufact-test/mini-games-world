import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const rules = [
  {
    importer:'app/assets/js/production-clean-entry-v110.js',
    target:'app/assets/js/production-v101-speed-runtime-v102.js',
    specifier:'./production-v101-speed-runtime-v102.js',
  },
  {
    importer:'app/assets/js/main-v110-handoff-shell.js',
    target:'app/assets/js/games/game-invites-v110.js',
    specifier:'./games/game-invites-v110.js',
  },
  {
    importer:'app/assets/js/main-v110-handoff-shell.js',
    target:'app/assets/js/production-v110-presence.js',
    specifier:'./production-v110-presence.js',
  },
  {
    importer:'app/assets/js/main-v110.js',
    target:'app/assets/js/main-v110-handoff-shell.js',
    specifier:'./main-v110-handoff-shell.js',
  },
  {
    importer:'app/v110.php',
    target:'app/assets/js/production-clean-entry-v110.js',
    specifier:'./assets/js/production-clean-entry-v110.js',
  },
  {
    importer:'app/v110.php',
    target:'app/assets/js/main-v110.js',
    specifier:'./assets/js/main-v110.js',
  },
];

const errors = [];

function blobPrefix(path){
  const sha = execFileSync('git', ['hash-object', path], { encoding:'utf8' }).trim();
  if (!/^[a-f0-9]{40}$/.test(sha)) throw new Error(`Invalid git blob SHA for ${path}`);
  return sha.slice(0, 12);
}

function escapeRegExp(value){
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

for (const rule of rules) {
  const source = readFileSync(rule.importer, 'utf8').replace(/^\uFEFF/, '');
  const pattern = new RegExp(`${escapeRegExp(rule.specifier)}\\?([^'\"\\s]+)`);
  const match = source.match(pattern);
  if (!match) {
    errors.push(`${rule.importer}: active runtime reference missing for ${rule.specifier}`);
    continue;
  }

  const params = new URLSearchParams(match[1]);
  const actual = String(params.get('b') || '').toLowerCase();
  const expected = blobPrefix(rule.target);
  if (actual !== expected) {
    errors.push(`${rule.importer}: ${rule.specifier} must use b=${expected}, found ${actual || 'none'}`);
  }
}

if (errors.length > 0) {
  console.error('Active runtime address checks failed:');
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log(`Active runtime address checks passed: ${rules.length} content-addressed references`);
