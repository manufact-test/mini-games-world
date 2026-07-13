export function initInviteNotificationDedupe(){
  const sheet = document.getElementById('sheet');
  if (!sheet) return;

  const observer = new MutationObserver(() => queueMicrotask(cleanDuplicateInviteCards));
  observer.observe(sheet, { childList:true, subtree:true });
}

function cleanDuplicateInviteCards(){
  const actionCard = document.querySelector('[data-invite-notification-card]');
  if (!actionCard) return;

  const action = actionCard.querySelector('[data-invite-notification-action]')?.dataset.inviteNotificationAction || '';
  const duplicateTitle = action === 'start'
    ? 'Соперник согласен'
    : action === 'accept'
      ? 'Вас пригласили сыграть'
      : action === 'wait'
        ? 'Приглашение принято'
        : '';

  if (!duplicateTitle) return;
  document.querySelectorAll('.notification-card:not([data-invite-notification-card])').forEach(card => {
    const title = String(card.querySelector('.notification-head strong')?.textContent || '').trim();
    if (title === duplicateTitle) card.remove();
  });
}
