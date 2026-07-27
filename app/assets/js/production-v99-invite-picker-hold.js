let initialized = false;

export function initV99InvitePickerHold(){
  if (initialized) return;
  initialized = true;

  const sheet = document.getElementById('sheet');
  const overlay = document.getElementById('sheetOverlay');
  if (!sheet || !overlay) return;

  let hold = null;
  let timeout = null;
  let trigger = null;
  let triggerText = '';

  const finish = () => {
    document.body.classList.remove('mgw-player-picker-transition');
    hold?.remove();
    hold = null;
    window.clearTimeout(timeout);
    timeout = null;
    if (trigger && document.body.contains(trigger)) {
      trigger.removeAttribute('aria-busy');
      trigger.disabled = false;
      if (triggerText) trigger.textContent = triggerText;
    }
    trigger = null;
    triggerText = '';
  };

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('[data-open-player-picker]');
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    finish();
    trigger = button;
    triggerText = String(button.textContent || 'Пригласить игрока');
    button.setAttribute('aria-busy', 'true');
    document.body.classList.add('mgw-player-picker-transition');

    hold = document.createElement('div');
    hold.className = 'sheet mgw-player-picker-hold';
    hold.setAttribute('aria-hidden', 'true');
    hold.setAttribute('inert', '');
    hold.innerHTML = sheet.innerHTML;
    hold.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
    hold.querySelectorAll('button,input,textarea,select,a').forEach(node => {
      node.setAttribute('tabindex', '-1');
      node.setAttribute('aria-hidden', 'true');
      if ('disabled' in node) node.disabled = true;
    });
    overlay.append(hold);
    timeout = window.setTimeout(finish, 5000);
  }, true);

  const observer = new MutationObserver(() => {
    if (!document.body.classList.contains('mgw-player-picker-transition')) return;
    const ready = Boolean(
      sheet.querySelector('.invite-player-list')
      || sheet.querySelector('.invite-empty-state')
      || sheet.querySelector('[data-back-to-invite-setup]')
    );
    if (ready) finish();
  });
  observer.observe(sheet, { childList:true, subtree:true });
}
