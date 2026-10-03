import { t } from '@mgw/i18n';

let sheetCopyObserver = null;

export function initUserCopy(){
  const sheet = document.getElementById('sheet');
  if (!sheet || sheetCopyObserver) return;

  cleanCurrentSheet(sheet);
  sheetCopyObserver = new MutationObserver(() => cleanCurrentSheet(sheet));
  sheetCopyObserver.observe(sheet, {
    childList:true,
    subtree:true,
  });
}

function cleanCurrentSheet(sheet){
  const heading = String(sheet.querySelector('.sheet-head h2')?.textContent || '').trim();

  if (sheet.querySelector('.topup-success') && heading.startsWith(t('user_copy.application_prefix'))) {
    sheet.querySelector('.sheet-head p')?.remove();

    const note = sheet.querySelector('.small-note');
    const message = t('user_copy.balance_after_admin');
    if (note && note.textContent.trim() !== message) {
      note.textContent = message;
    }
  }

  if (sheet.querySelector('.store-order-success') && heading.startsWith(t('user_copy.application_prefix'))) {
    sheet.querySelector('.sheet-head p')?.remove();

    const note = sheet.querySelector('.store-order-warning');
    if (note) {
      const repeated = heading.includes(t('user_copy.already_marker'));
      const message = repeated
        ? t('user_copy.order_already_created')
        : t('user_copy.orders_status_hint');

      if (note.textContent.trim() !== message) {
        note.textContent = message;
      }
    }
  }
}
