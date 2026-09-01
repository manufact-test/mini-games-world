import fs from 'node:fs';

const renderer = fs.readFileSync('app/assets/js/games/tictactoe/renderer.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/tictactoe/cosmetics.css', 'utf8');
const migration = fs.readFileSync('bot/database/migrations/20260901_0017_upgrade_tictactoe_effects_v2.php', 'utf8');
const mainCss = fs.readFileSync('app/assets/css/main.css', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(renderer.includes("hasEquipped(owner, 'game_tictactoe_effect_sign')"), 'sign effect must remain event-owned by the moved mark');
expect(renderer.includes("hasEquipped(winner, 'game_tictactoe_effect_winning_line')"), 'winning effect must remain winner-owned');
expect(renderer.includes("hasEquipped(owner, 'game_tictactoe_effect_strike_through')"), 'legacy stable slot must drive Move Pulse for existing purchases');
expect(renderer.includes("'ttt-move-pulse'"), 'renderer must emit Move Pulse class');
expect(!renderer.includes("'ttt-struck-cell'"), 'strike-through rendering must be removed');

for (const token of [
  'tttEffectImpact',
  'tttEffectWinningLine',
  'tttEffectMovePulse',
  'storeTttImpact',
  'storeTttWinningLine',
  'storeTttMovePulse',
  'data-cosmetic-variant="move-pulse"',
]) {
  expect(css.includes(token), `missing effects v2 CSS token: ${token}`);
}

expect(migration.includes("'display_name' => 'Импульс знака'"), 'sign effect must have v2 name');
expect(migration.includes("'display_name' => 'Победный импульс'"), 'winning effect must have v2 name');
expect(migration.includes("'display_name' => 'Импульс хода'"), 'legacy strike item must become Move Pulse');
expect(migration.includes("'variant' => 'move-pulse'"), 'Move Pulse metadata variant must reach Store preview');
expect(migration.includes("'game-ttt-effect-strike'"), 'stable purchased item id must be preserved');
expect(mainCss.includes('c2=effects-v2'), 'active CSS graph must load effects v2');
expect(manifest.includes('c2=effects-v2'), 'active runtime manifest must cache-bust effects v2');

console.log('MVP-19.4 effects v2 contract: OK');
