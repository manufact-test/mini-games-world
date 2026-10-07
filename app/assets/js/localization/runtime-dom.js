import { t } from '@mgw/i18n';

function setText(selector, key){
  const node = document.querySelector(selector);
  if (node) node.textContent = t(key);
}

function setAttr(selector, attribute, key){
  document.querySelectorAll(selector).forEach(node => node.setAttribute(attribute, t(key)));
}

export function localizeRuntimeDom(){
  setText('#screen-home .hero-title', 'shell.home_hero_title');
  setText('#screen-home .hero-sub', 'shell.home_hero_subtitle');
  setText('#screen-home .balance-note', 'profile.balance_note');
  setText('#activityTitle h2', 'shell.home_activity_title');
  setText('#screen-home .section-title:not(#activityTitle) h2', 'shell.home_games_title');

  document.querySelectorAll('#screen-home .game-card .btn.primary').forEach(button => {
    button.textContent = t('shell.play');
  });

  setAttr('#profileOpen', 'aria-label', 'nav.profile');
  setAttr('#notificationsOpen', 'aria-label', 'topbar.notifications');
  setAttr('#moreMenuOpen', 'aria-label', 'home.menu.title');
  setAttr('#screen-home [data-game-rules]', 'aria-label', 'rules.open');

  setText('#screen-search .page-title', 'shell.search_title');
  const searchInfo = document.getElementById('searchInfo');
  if (searchInfo && !String(searchInfo.dataset.mgwSearchContext || '')) {
    searchInfo.textContent = t('shell.search_waiting');
  }
  setText('#searchMeName', 'shell.search_you');
  setText('#screen-search .vs-cards .player-card:last-child strong', 'shell.search_opponent');
  setText('#changeSearch', 'shell.search_leave');

  setText('#matchMeta', 'home.history.match');
  const turn = document.getElementById('turnText');
  if (turn && (!window.__MGW_V100_GAME_RUNTIME__ || !document.getElementById('screen-game')?.classList.contains('active'))) {
    turn.textContent = t('games.router.status.your_turn');
  }
  setAttr('#screen-game [data-game-rules-current]', 'aria-label', 'rules.open');
  setText('#leaveGame', 'game_screen.result.home');

  setText('#screen-profile .page-head .page-title', 'profile.title');
  setText('#screen-profile .page-head .page-sub', 'profile.subtitle');
  const profileName = document.getElementById('profileName');
  if (profileName && profileName.textContent?.trim() === '') profileName.textContent = t('profile.player');
  const profileDate = document.getElementById('profileDate');
  if (profileDate && !String(profileDate.dataset.mgwProfileDateValue || '')) {
    profileDate.textContent = t('profile.member_since_unknown');
  }
}

export function initRuntimeDomLocalization(){
  localizeRuntimeDom();
  document.addEventListener('mgw:locale-changed', localizeRuntimeDom);
}
