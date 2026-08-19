import { state } from '../state.js?v=27';

const DEFAULT_AVATAR = 'starter-default-01';
let initialized = false;
let observer = null;
let scheduled = false;

export function initMgwAvatarPresentation(){
  if (initialized) return;
  initialized = true;

  const start = () => {
    const row = document.getElementById('playersRow');
    if (!row) return;
    observer?.disconnect();
    observer = new MutationObserver(scheduleDecorate);
    observer.observe(row, { childList:true, subtree:false });
    const timer = document.getElementById('timerText');
    if (timer) observer.observe(timer, { childList:true, characterData:true, subtree:true });
    decoratePlayersRow();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once:true });
  else start();
}

function scheduleDecorate(){
  if (scheduled) return;
  scheduled = true;
  queueMicrotask(() => {
    scheduled = false;
    decoratePlayersRow();
  });
}

function decoratePlayersRow(){
  const row = document.getElementById('playersRow');
  const game = state.activeGame;
  const players = Array.isArray(game?.players) ? game.players : [];
  if (!row || !players.length) return;

  [...row.children].forEach((card, index) => {
    if (!(card instanceof HTMLElement)) return;
    const player = players[index];
    if (!player || typeof player !== 'object') return;

    const itemId = visibleAvatarItemId(player);
    const existing = card.querySelector(':scope > .game-player-avatar');
    if (!itemId) {
      existing?.remove();
      card.classList.remove('has-mgw-avatar');
      return;
    }

    if (existing instanceof HTMLElement) {
      if (existing.dataset.avatarItemId !== itemId) existing.dataset.avatarItemId = itemId;
      card.classList.add('has-mgw-avatar');
      return;
    }

    const avatar = document.createElement('span');
    avatar.className = 'game-player-avatar';
    avatar.dataset.avatarItemId = itemId;
    avatar.setAttribute('aria-hidden', 'true');
    avatar.textContent = 'MG';
    card.prepend(avatar);
    card.classList.add('has-mgw-avatar');
  });
}

function visibleAvatarItemId(player){
  const explicit = String(player?.avatar_item_id || player?.avatar?.item_id || '').trim().toLowerCase();
  if (explicit) return explicit;
  const playerId = String(player?.id || '').trim();
  return playerId && !playerId.startsWith('bot_') ? DEFAULT_AVATAR : '';
}
