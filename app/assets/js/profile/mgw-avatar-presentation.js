import { api } from '../api/client.js?v=47';
import { state } from '../state.js?v=27';
import { toast } from '../components/toast.js?v=27';
import { renderUser } from '../ui.js?v=89';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { getAvatarVisualMeta } from './mgw-avatar-registry.js?v=4';

const DEFAULT_AVATAR = 'starter-default-01';
const NAME_COLOR_ITEM_IDS = new Set([
  'profile-name-color-sky',
  'profile-name-color-gold',
  'profile-name-color-aurora',
]);

let initialized = false;
let observer = null;
let scheduled = false;
let storeObserver = null;
let storeScheduled = false;
let storeAvatarSaving = false;

export function initMgwAvatarPresentation(){
  if (initialized) return;
  initialized = true;

  const start = () => {
    startStoreAvatarActions();

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

function startStoreAvatarActions(){
  storeObserver?.disconnect();
  storeObserver = new MutationObserver(scheduleStoreDecorate);
  [document.getElementById('storeTabSurface'), document.getElementById('sheet')].forEach(root => {
    if (root) storeObserver.observe(root, { childList:true, subtree:true });
  });
  document.addEventListener('click', handleStoreAvatarAction, true);
  document.addEventListener('mgw:screen-changed', scheduleStoreDecorate);
  decorateStoreAvatarActions();
}

function scheduleStoreDecorate(){
  if (storeScheduled) return;
  storeScheduled = true;
  queueMicrotask(() => {
    storeScheduled = false;
    decorateStoreAvatarActions();
  });
}

function decorateStoreAvatarActions(){
  document.querySelectorAll('.store-v2-product.owned .store-v2-avatar-preview[data-avatar-item-id]').forEach(preview => {
    if (!(preview instanceof HTMLElement)) return;
    const card = preview.closest('.store-v2-product');
    const foot = card?.querySelector(':scope > .store-v2-product-foot');
    const itemId = String(preview.dataset.avatarItemId || '').trim();
    if (!(card instanceof HTMLElement) || !(foot instanceof HTMLElement) || !itemId) return;

    const selectedItemId = String(state.selectedAvatarId || '').trim();
    const active = selectedItemId ? selectedItemId === itemId : card.classList.contains('equipped');
    card.classList.toggle('equipped', active);

    const legacyOwnedLabel = foot.querySelector(':scope > b');
    if (legacyOwnedLabel instanceof HTMLElement) legacyOwnedLabel.hidden = true;

    let action = foot.querySelector(':scope > [data-mgw-store-avatar-select]');
    if (!(action instanceof HTMLButtonElement)) {
      action = document.createElement('button');
      action.type = 'button';
      action.dataset.mgwStoreAvatarSelect = itemId;
      foot.append(action);
    }
    action.dataset.mgwStoreAvatarSelect = itemId;
    action.className = `store-v2-equip store-v2-avatar-select${active ? ' active' : ''}`;
    action.textContent = active ? 'Выбрана' : 'Выбрать';
    action.disabled = active;
    action.setAttribute('aria-pressed', active ? 'true' : 'false');

    let check = preview.querySelector(':scope > .store-v2-selected-check');
    if (active && !(check instanceof HTMLElement)) {
      check = document.createElement('i');
      check.className = 'store-v2-selected-check';
      check.setAttribute('aria-label', 'Выбрана');
      check.textContent = '✓';
      preview.append(check);
    } else if (!active && check instanceof HTMLElement) {
      check.remove();
    }
  });
}

function handleStoreAvatarAction(event){
  const action = event.target instanceof Element ? event.target.closest('[data-mgw-store-avatar-select]') : null;
  if (!(action instanceof HTMLButtonElement)) return;
  event.preventDefault();
  const itemId = String(action.dataset.mgwStoreAvatarSelect || '').trim();
  if (!itemId || action.disabled || storeAvatarSaving || itemId === String(state.selectedAvatarId || '')) return;
  const card = action.closest('.store-v2-product.owned');
  if (!(card instanceof HTMLElement)) return;
  void selectStoreAvatar(itemId);
}

async function selectStoreAvatar(itemId){
  if (storeAvatarSaving) return;
  const previousSelectedAvatarId = state.selectedAvatarId;
  storeAvatarSaving = true;
  state.selectedAvatarId = itemId;
  decorateStoreAvatarActions();
  if (state.user) renderUser(state.user);
  haptic('light');

  try {
    const result = await api.profileV2({ avatar_item_id:itemId });
    const confirmedItemId = String(result?.profile?.avatar?.item_id || result?.user?.avatar_item_id || itemId).trim();
    if (confirmedItemId !== itemId) throw new Error('Профиль не подтвердил выбранную аватарку.');
    state.selectedAvatarId = confirmedItemId;
    decorateStoreAvatarActions();
    if (state.user) renderUser(state.user);
    haptic('success');
    toast('Аватарка выбрана.');
  } catch (error) {
    state.selectedAvatarId = previousSelectedAvatarId;
    decorateStoreAvatarActions();
    if (state.user) renderUser(state.user);
    haptic('error');
    toast(error?.message || 'Не удалось выбрать аватарку.');
  } finally {
    storeAvatarSaving = false;
  }
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

    decoratePlayerName(card, player);

    const itemId = visibleAvatarItemId(player);
    const existing = card.querySelector(':scope > .game-player-avatar');

    if (!itemId) {
      existing?.remove();
      card.classList.remove('has-mgw-avatar');
      return;
    }

    const meta = getAvatarVisualMeta(itemId);
    const avatar = existing instanceof HTMLElement ? existing : createAvatarNode();
    avatar.dataset.avatarItemId = itemId;
    avatar.dataset.avatarKnown = meta ? 'true' : 'false';

    if (meta) {
      avatar.dataset.rarity = meta.rarity;
      avatar.dataset.avatarName = meta.name;
    } else {
      delete avatar.dataset.rarity;
      delete avatar.dataset.avatarName;
    }

    avatar.textContent = '';
    if (!existing) card.prepend(avatar);
    card.classList.add('has-mgw-avatar');
  });
}

function decoratePlayerName(card, player){
  const name = card.querySelector(':scope > .name');
  if (!(name instanceof HTMLElement)) return;
  const itemId = String(player?.name_color_item_id || '').trim().toLowerCase();
  if (NAME_COLOR_ITEM_IDS.has(itemId)) name.dataset.nameColorItemId = itemId;
  else delete name.dataset.nameColorItemId;
}

function createAvatarNode(){
  const avatar = document.createElement('span');
  avatar.className = 'game-player-avatar';
  avatar.setAttribute('aria-hidden', 'true');
  return avatar;
}

function visibleAvatarItemId(player){
  const explicit = String(player?.avatar_item_id || player?.avatar?.item_id || '').trim().toLowerCase();
  if (explicit) return explicit;
  const playerId = String(player?.id || '').trim();
  return playerId && !playerId.startsWith('bot_') ? DEFAULT_AVATAR : '';
}