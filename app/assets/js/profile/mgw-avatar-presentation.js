import { state } from '../state.js?v=27';

const DEFAULT_AVATAR = 'starter-default-01';

const AVATAR_VISUAL_REGISTRY = {
  'starter-default-01': { rarity: 'free', asset: null },
  'starter-default-02': { rarity: 'free', asset: null },
  'starter-default-03': { rarity: 'free', asset: null },
  'store-avatar-01': { rarity: 'rare', asset: null },
  'store-avatar-02': { rarity: 'rare', asset: null },
  'store-avatar-03': { rarity: 'rare', asset: null },
  'store-avatar-04': { rarity: 'elite', asset: null },
  'store-avatar-05': { rarity: 'elite', asset: null },
  'store-avatar-06': { rarity: 'elite', asset: null },
  'store-avatar-07': { rarity: 'legendary', asset: null },
  'store-avatar-08': { rarity: 'legendary', asset: null },
  'store-avatar-09': { rarity: 'legendary', asset: null }
};

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
      return;
    }

    const avatar = document.createElement('span');
    avatar.className = 'game-player-avatar';
    avatar.dataset.avatarItemId = itemId;
    avatar.dataset.rarity = AVATAR_VISUAL_REGISTRY[itemId]?.rarity || 'unknown';
    avatar.setAttribute('aria-hidden', 'true');
    avatar.textContent = 'MG';
    card.prepend(avatar);
    card.classList.add('has-mgw-avatar');
  });
}

function visibleAvatarItemId(player){
  const explicit = String(player?.avatar_item_id || player?.avatar?.item_id || '').trim().toLowerCase();
  if (explicit && AVATAR_VISUAL_REGISTRY[explicit]) return explicit;
  const playerId = String(player?.id || '').trim();
  return playerId && !playerId.startsWith('bot_') ? DEFAULT_AVATAR : '';
}
