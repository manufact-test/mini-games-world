export function installRuntimeErrorBoundary({ store, router, root }){
  const showError = error => {
    const message = normalizeErrorMessage(error);
    store.setState({ phase:'error', error:{ message } });
    const target = root.querySelector('[data-error-message]');
    if (target) target.textContent = message;
    router.show('error');
  };

  window.addEventListener('error', event => showError(event.error || event.message));
  window.addEventListener('unhandledrejection', event => showError(event.reason));

  return Object.freeze({ showError });
}

function normalizeErrorMessage(value){
  if (value instanceof Error && value.message) return value.message;
  const message = String(value || '').trim();
  return message || 'Не удалось запустить Mini Games World. Повторите запуск из Telegram.';
}
