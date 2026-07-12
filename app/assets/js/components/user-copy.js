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

  if (sheet.querySelector('.topup-success') && heading.startsWith('Заявка')) {
    sheet.querySelector('.sheet-head p')?.remove();

    const note = sheet.querySelector('.small-note');
    if (note) {
      note.textContent = 'Баланс изменится после подтверждения администратором.';
    }
  }

  if (sheet.querySelector('.store-order-success') && heading.startsWith('Заявка')) {
    sheet.querySelector('.sheet-head p')?.remove();

    const note = sheet.querySelector('.store-order-warning');
    if (note) {
      const repeated = note.textContent.includes('Повторный запрос');
      note.textContent = repeated
        ? 'Эта заявка уже была создана.'
        : 'Следите за статусом в разделе «Мои заявки».';
    }
  }
}
