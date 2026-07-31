let toastTimer = null;
let policyObserver = null;

const NON_ACTIONABLE_CONFIRMATIONS = new Set([
  'Приглашение отменено.',
]);

export function toast(message, duration = 2600){
  const el = document.getElementById('toast');
  if (!el) return;

  const text = String(message || '').trim();
  if (!text || NON_ACTIONABLE_CONFIRMATIONS.has(text)) {
    el.classList.remove('show');
    return;
  }

  installToastPolicy(el);
  const safeDuration = Math.max(1200, Math.min(10000, Number(duration || 2600)));
  el.textContent = text;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), safeDuration);
}

function installToastPolicy(element = document.getElementById('toast')){
  if (!element || policyObserver || typeof MutationObserver !== 'function') return;
  policyObserver = new MutationObserver(() => {
    const text = String(element.textContent || '').trim();
    if (NON_ACTIONABLE_CONFIRMATIONS.has(text)) element.classList.remove('show');
  });
  policyObserver.observe(element, {
    attributes:true,
    attributeFilter:['class'],
    childList:true,
    characterData:true,
    subtree:true,
  });
}

queueMicrotask(() => installToastPolicy());
