import { initProfileScreen as initAcceptedProfileScreen } from './mgw-profile-chess-layout-v2.js?v=19&mvp16=profile-pass-a&mvp17=result-history-economy&mvp19=avatar-collection&previsual=instant-equip&mvp19_3_1=avatar-sync&mvp19_3_2=game-cosmetics&mvp19_3_3=game-tabs-fresh&mvp19_3_4=name-colors&perf=stable-render-cache&active_refresh=deferred-remount-v1&entry=paint-cached-first-v1&mobile_lifecycle=yield-refresh-and-remount-v1&mvp19_5=chess-profile-store-parity-v1&layout=card-sheet-fit-v3&secondary=clean-v1&game_tabs=panel-only-v1&mobile_perf=hidden-animations-paused-v1&mvp19_6=checkers-profile-manual-repair-v3&profile_game_tabs=scroll-normalized-v2&profile_inventory=store-sync-v1&profile_card_visual=checkers-board-effect-store-exact-v2&profile_card_runtime=checkers-hard-square-v1&profile_perf=observer-cycle-v2&chess_prewarm=v1&mvp19_7=reversi-profile-parity-v1&profile_tabs=delayed-capture-v2&reversi_tab_mark=separated-v1&reversi_cards=direct-exact-v1&reversi_card_runtime=hard-square-v1&mvp19_8=go-profile-corrective-v2&go_card_runtime=hard-square-v1&game_tab_icons=normalized-v3';
import { initProfileDominoParity } from './mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1';
import { initProfileDominoHardRatio } from './mgw-profile-domino-hard-ratio-v1.js?v=1&mvp19_9=hard-8x5-v1';

export function initProfileScreen(){
  ensureDominoScaleStyles();
  initAcceptedProfileScreen();
  initProfileDominoParity();
  initProfileDominoHardRatio();
}

function ensureDominoScaleStyles(){
  const href = new URL('../../css/games/domino/store-cosmetics-scale-v2.css?v=1&mvp19_9=container-relative-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-scale]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoScale = 'container-relative-v2';
  link.href = href;
  document.head.appendChild(link);
}
