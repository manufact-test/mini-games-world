import { state } from '../state.js?v=27';

export function initStoreTestModeNotice(){
  const sheet = document.getElementById('sheet');
  if (!sheet) return;

  const applyNotice = () => {
    if (!state.user?.shop_test_mode) return;
    const note = sheet.querySelector('.store-note');
    if (!note) return;

    note.textContent = 'Тестовый режим администратора: весь текущий Gold доступен для проверки магазина. Для обычных игроков действует правило отыгрыша Gold в завершённых матчах.';
  };

  const observer = new MutationObserver(applyNotice);
  observer.observe(sheet, { childList: true, subtree: true });
  applyNotice();
}
