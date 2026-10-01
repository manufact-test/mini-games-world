import fs from 'node:fs';
import assert from 'node:assert/strict';

const owner = fs.readFileSync('app/assets/js/checkers-cosmetics/renderer-board-themes.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

assert.match(owner, /REAL_MOVE_TRAIL_FOLLOW_MS/);
assert.match(owner, /movingPiece\.getBoundingClientRect\(\)/);
assert.match(owner, /alignMoveTrailPath\(layer, state\.sourceRect, currentRect\)/);
assert.match(owner, /const layerRect = layer\.getBoundingClientRect\(\)/);
assert.match(owner, /sourceRect\.left - layerRect\.left/);
assert.match(owner, /targetRect\.left - layerRect\.left/);
assert.match(owner, /state\?\.trailFrame.*cancelAnimationFrame/s);
assert.match(owner, /real-board-piece-flip-v3-trail-center/);

assert.match(
  manifest,
  /renderer-board-themes\.js\?v=13[^'\n]*trail=real-piece-center-follow-v1/
);

assert.doesNotMatch(owner, /setInterval\(/);

console.log('MVP-19.6 Checkers Move trail alignment contract PASS');
