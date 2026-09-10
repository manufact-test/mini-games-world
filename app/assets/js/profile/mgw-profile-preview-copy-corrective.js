const AVATAR_NAMES = Object.freeze({
  'starter-default-01':'Брам',
  'starter-default-02':'Мира',
  'starter-default-03':'Тобин',
  'store-avatar-01':'Сайлас',
  'store-avatar-02':'Каэл',
  'store-avatar-03':'Рук',
  'store-avatar-04':'Вейр',
  'store-avatar-05':'Аурекс',
  'store-avatar-06':'Эйра',
  'store-avatar-07':'Ноктар',
  'store-avatar-08':'Соларис',
  'store-avatar-09':'Дракс',
});

const ENTRY_NAMES = Object.freeze({
  'entry-01':'Небесные врата',
  'entry-02':'Восхождение короля',
  'entry-03':'Клинок владыки',
});

let initialized = false;
let observer = null;
let scheduled = false;

export function initMgwProfilePreviewCopyCorrective(){
  if (initialized) return;
  initialized = true;
  const start = () => {
    const sheet = document.getElementById('sheet');
    if (!(sheet instanceof HTMLElement)) return;
    observer = new MutationObserver(scheduleNormalize);
    observer.observe(sheet, { childList:true, subtree:true });
    scheduleNormalize();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once:true });
  else start();
}

function scheduleNormalize(){
  if (scheduled) return;
  scheduled = true;
  queueMicrotask(() => {
    scheduled = false;
    normalizeProfilePreviewSheet();
  });
}

function normalizeProfilePreviewSheet(){
  const sheet = document.getElementById('sheet');
  if (!(sheet instanceof HTMLElement)) return;
  const heading = sheet.querySelector(':scope .sheet-head h2');
  if (!(heading instanceof HTMLElement)) return;

  const avatar = sheet.querySelector('.profile-v2-avatar-preview[data-avatar-item-id]');
  if (avatar instanceof HTMLElement) {
    const itemId = String(avatar.dataset.avatarItemId || '').trim();
    const name = AVATAR_NAMES[itemId];
    if (name && heading.textContent !== name) heading.textContent = name;
    return;
  }

  const entry = sheet.querySelector('.mgw-entry-effect-sheet-preview .mgw-entry-effect-preview[data-entry-effect-variant]');
  if (entry instanceof HTMLElement) {
    const variant = String(entry.dataset.entryEffectVariant || '').trim();
    const name = ENTRY_NAMES[variant];
    if (name && heading.textContent !== name) heading.textContent = name;
    const meta = sheet.querySelector('.profile-v2-entry-effect-preview-meta');
    if (meta instanceof HTMLElement) {
      const family = meta.querySelector('strong');
      if (family instanceof HTMLElement && family.textContent !== 'Эффект входа') family.textContent = 'Эффект входа';
      meta.querySelector('small')?.remove();
    }
    return;
  }

  const victory = sheet.querySelector('.mgw-victory-effect-sheet-preview .mgw-victory-effect-preview[data-victory-effect-variant]');
  if (victory instanceof HTMLElement) {
    const meta = sheet.querySelector('.profile-v2-entry-effect-preview-meta');
    if (meta instanceof HTMLElement) {
      const family = meta.querySelector('strong');
      if (family instanceof HTMLElement && family.textContent !== 'Эффект победы') family.textContent = 'Эффект победы';
      meta.querySelector('small')?.remove();
    }
  }
}
