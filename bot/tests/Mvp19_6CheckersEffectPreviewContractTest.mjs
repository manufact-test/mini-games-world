import fs from 'node:fs';

const css = fs.readFileSync('app/assets/css/games/checkers/store-effects-live-board-v1.css', 'utf8');
const wrapper = fs.readFileSync('app/assets/js/screens/store-screen-checkers-wrapper.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const gameCss = fs.readFileSync('app/assets/css/games/checkers/game.css', 'utf8');

function ok(value, label){
  if (!value) throw new Error(label);
  console.log(`PASS ${label}`);
}

ok(manifest.includes('store-screen-checkers-wrapper.js?v=4') && manifest.includes('mvp19_6=visual-corrective-v3') && manifest.includes('effects_live_board=v1'), 'active Store route keeps accepted Checkers wrapper and cache-busts effect corrective');
ok(wrapper.includes('store-effects-live-board-v1.css?v=2&mvp19_6=effects-live-board-only'), 'accepted Checkers wrapper loads cache-busted effects stylesheet');
ok(wrapper.includes('runBoundedEffectPreview'), 'existing bounded finite replay owner preserved');
ok(wrapper.includes("preview.classList.add('is-previewing')"), 'existing effect replay trigger preserved');
ok(wrapper.includes("from './store-screen-intent-wrapper.js?v=19&mvp19_6=accepted-base-preserved';"), 'accepted Store owner chain remains intact');

ok(css.includes('linear-gradient(145deg,#d9c8a8,#bea884)'), 'effect preview uses live light-square material');
ok(css.includes('linear-gradient(145deg,#5d4b58,#3c3343)'), 'effect preview uses live dark-square material');
ok(gameCss.includes('linear-gradient(145deg,#d9c8a8,#bea884)'), 'live Checkers light-square source matches');
ok(gameCss.includes('linear-gradient(145deg,#5d4b58,#3c3343)'), 'live Checkers dark-square source matches');
ok(css.includes('width:9%!important'), 'effect checker uses live 72%-of-cell geometry');
ok(css.includes('linear-gradient(145deg,#f7f5ee,#c8c5bd)'), 'effect white checker uses live material');
ok(css.includes('linear-gradient(145deg,#313a51,#111827)'), 'capture target uses live black checker material');

ok(css.includes('@keyframes mgw-live-board-move'), 'move animation exists');
ok(css.includes('@keyframes mgw-live-board-capture'), 'capture animation exists');
ok(css.includes('@keyframes mgw-live-board-captured'), 'captured checker removal exists');
ok(css.includes('@keyframes mgw-live-board-promotion'), 'promotion move exists');
ok(css.includes('@keyframes mgw-live-board-crown'), 'promotion crown exists');
ok(css.includes('@media (prefers-reduced-motion:reduce)'), 'reduced motion fallback exists');
ok(!css.includes('animation:infinite'), 'effect corrective has no infinite animation declaration');

console.log('MVP-19.6 Checkers effect preview contract passed.');
