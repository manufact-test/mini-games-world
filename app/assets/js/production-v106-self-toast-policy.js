const runtime = window.__MGW_V106_INVITE_ACTIONS__;

export function initV106SelfToastPolicy(){
  if (!runtime || runtime.selfToastPolicyInitialized) return;
  runtime.selfToastPolicyInitialized = true;
  window.addEventListener('click', rememberUserAction, true);
}

function rememberUserAction(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const control = origin.closest('button, [role="button"]');
  if (!(control instanceof Element)) return;

  const inviteAction = String(control.getAttribute('data-invite-action') || '');
  let messages = [];

  if (inviteAction === 'cancel') {
    messages = ['Приглашение отменено.', 'Участие отменено.'];
  } else if (inviteAction === 'decline') {
    messages = ['Приглашение отклонено.'];
  } else if (control.id === 'cancelSearch' || control.id === 'changeSearch') {
    messages = ['Поиск отменён.', 'Поиск отменен.'];
  } else if (control.matches('[data-discard-draft]')) {
    messages = ['Приглашение отменено.'];
  } else if (control.id === 'confirmLeaveGame') {
    messages = ['Матч отменён.', 'Матч отменен.', 'Игра отменена.'];
  }

  if (!messages.length) return;
  /* The actual owner may update its own suppression set later in the same
   * capture event. Narrow it back to this exact user intention afterwards. */
  queueMicrotask(() => {
    runtime.selfToastUntil = Date.now() + 5000;
    runtime.selfToastMessages = new Set(messages);
  });
}
