import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const server = readFileSync('bot/services/HistoryService.php', 'utf8');
const client = readFileSync('app/assets/js/screens/profile-screen-v110.js', 'utf8');
const ru = JSON.parse(readFileSync('app/locales/ru.json', 'utf8'));
const en = JSON.parse(readFileSync('app/locales/en.json', 'utf8'));
const suffixes = [
  'active', 'not_started', 'draw', 'victory_timeout',
  'victory_opponent_left', 'victory', 'technical_defeat', 'defeat',
];
for (const suffix of suffixes) {
  const key = 'server.history.match.' + suffix;
  assert.match(server, new RegExp("'"+key.replaceAll('.','\\.')+"'"),
    'HistoryService must keep an explicit result classification: ' + key);
  assert.ok(ru.server.history.match[suffix] && en.server.history.match[suffix],
    'Both locale catalogs must resolve structural history key: ' + key);
}
assert.match(server, /'result_key'\s*=>\s*\$resultKey/);
assert.match(client, /match\?\.result_key/);
assert.match(client, /const resultLabel = knownResult \? t\(resultKey\)/);
assert.match(client, /escapeHtml\(resultLabel\)/);
assert.doesNotMatch(client, /escapeHtml\(String\(match\?\.result \|\| '—'\)\)/);
console.log('MVP-27.6 Profile match history result keys / RU+EN rendering PASS');
