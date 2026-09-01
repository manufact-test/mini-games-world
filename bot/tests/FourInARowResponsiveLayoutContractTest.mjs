import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const css = read('app/assets/css/games/four-in-a-row/game.css');
const main = read('app/assets/css/main.css');
const manifest = read('app/runtime/client/version-manifest.php');

assert(css.includes('.board.four-in-a-row-board{'), 'Four in a Row must own its board sizing with a game-specific selector');
assert(css.includes('width:min(100%,390px);'), 'Four in a Row outer board must be width-owned and capped at 390px');
assert(css.includes('width:min(100%,350px);'), 'Four in a Row frame must be capped at the accepted 350px width');
assert(css.includes('aspect-ratio:var(--four-columns)/var(--four-rows);'), 'Four in a Row frame must derive height from authoritative columns/rows');
assert(css.includes('width:min(100%,42px);'), 'Four in a Row disc slots must cap at 42px');
assert(css.includes('gap:4px;'), 'Four in a Row gameplay grid must keep a 4px gap');
assert(!css.includes('100dvh'), 'Four in a Row game-specific layout must not depend on viewport-height sizing');
assert(main.includes("./games/four-in-a-row/game.css?v=54&ios-fit=width-owned"), 'Main CSS must cache-bust the width-owned Four in a Row layout');
assert(/main\.css\?v=\d+/.test(manifest) && manifest.includes('&four=ios-fit'), 'Runtime manifest must keep a versioned main CSS identity with the Four in a Row layout marker');

console.log('PASS: Four in a Row responsive layout contract');