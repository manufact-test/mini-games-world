import fs from 'node:fs';

function read(path){
  return fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
}
function expect(condition, message){
  if (!condition) throw new Error(message);
}

const shell = read('app/index.html');
const parity = read('app/assets/css/games/domino/store-effects-visual-parity-v16.css');
const choreography = read('app/assets/css/games/domino/store-effects-scene-v9.css');
const launch = read('bot/helpers/WebAppLaunchUrl.php');

expect(shell.includes('store-effects-visual-parity-v16.css?v=1&mvp19_9=domino-effect-visual-parity-v16'), 'active shell must publish Domino visual parity v16');
expect(shell.includes('data-mgw-domino-effects-visual-parity="v16"'), 'active shell must expose Domino parity marker');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1171, 'Telegram entry must keep v16 cache-bust or a newer successor');

expect(parity.includes('[data-cosmetic-layer="effect"]'), 'parity skin must be scoped to Domino effects only');
expect(parity.includes('width:27%!important'), 'horizontal effect pieces must use compact 27% width');
expect(parity.includes('height:21.6%!important'), 'horizontal effect pieces must keep a 2:1 body on the 8:5 stage');
expect(parity.includes('border-radius:3px!important'), 'effect pieces must match accepted 3px Domino tile corners');
expect(parity.includes('repeating-linear-gradient(135deg,#29364b 0 4px,#111a2b 4px 8px)'), 'stock backs must match accepted striped Domino backs');
expect(parity.includes('width:12%!important'), 'finale pieces must be reduced to compact standing width');
expect(parity.includes('height:38.4%!important'), 'finale pieces must keep true standing 1:2 geometry');
expect(!/\banimation\s*:/.test(parity), 'v16 visual parity skin itself must not own animation choreography');

expect(choreography.includes('mgw-domino-v15-precision-flight'), 'v15 precision baseline choreography must remain available for successor comparison');
expect(choreography.includes('mgw-domino-v15-stock-flight'), 'v15 stock baseline choreography must remain available for successor comparison');
expect(choreography.includes('mgw-domino-v15-cascade-tile'), 'v15 cascade choreography must remain available');

console.log('MVP-19.9 Domino visual parity v16 contract: OK');
