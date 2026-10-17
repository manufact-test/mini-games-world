import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const read = path => readFileSync(path, 'utf8');
const css = read('app/assets/css/games/battleship/game.css');
const rootCss = read('app/assets/css/main.css');
const entry = read('app/index.html');
const gameScreen = read('app/assets/js/screens/game-screen-v102.js');
const optimistic = read('app/assets/js/production-v100-optimistic-models.js');

assert.match(gameScreen, /pendingSurfaceDescriptor\(game, type\)/,
  'Live game screen must apply pending-shot descriptors.');
assert.match(gameScreen, /node\.classList\.add\(descriptor\.className\)/,
  'Pending shot must be projected onto the actual tapped cell.');
assert.match(optimistic, /className:'mgw-pending-shot'/,
  'Battleship must retain the dedicated pending-shot marker.');
assert.match(css, /\.battleship-cell\.mgw-pending-shot\s*\{[^}]*background:/s,
  'Pending shot must have a visible background independent of hover or cursor.');
assert.match(css, /\.battleship-cell\.mgw-pending-shot::after\s*\{[^}]*content:'…'/s,
  'Touch users must see a neutral pending acknowledgment, not a fake hit/miss.');
assert.match(css, /prefers-reduced-motion:reduce/,
  'Pending animation must respect reduced-motion preference.');
assert.match(rootCss, /games\/battleship\/game\.css\?v=61&fire=direct-result-v4&pending=touch-ack-v1/,
  'The active Battleship stylesheet must load the new pending feedback.');
assert.match(entry, /assets\/css\/main\.css\?v=94-wallet-15-3/,
  'Root HTML must invalidate cached CSS imports for the touch client.');
console.log('MGW_BATTLESHIP_PENDING_TOUCH_ACK=PASS');
