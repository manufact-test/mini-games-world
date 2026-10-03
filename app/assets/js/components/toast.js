import { t } from '@mgw/i18n';

let timer = null;

let silentAcknowledgements = null;

function silentAcknowledgementSet(){
  return silentAcknowledgements ||= new Set([
    t('store.actions.selected_toast'),
    t('store.actions.removed_toast'),
    t('profile.backgrounds.toast.selected'),
    t('profile.backgrounds.toast.removed'),
    t('profile.badges.toast.selected'),
    t('profile.badges.toast.removed'),
    t('profile.frames.toast.selected'),
    t('profile.frames.toast.removed'),
    t('profile.entry_effects.toast.selected'),
    t('profile.entry_effects.toast.removed'),
  ]);
}

export function toast(message, duration = 2600){
  const normalized = String(message ?? '');
  if (silentAcknowledgementSet().has(normalized)) return;
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = normalized;
  el.classList.add('show');
  clearTimeout(timer);
  timer = setTimeout(() => el.classList.remove('show'), duration);
}