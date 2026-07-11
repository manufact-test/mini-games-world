let toastTimer = null;

export function toast(message, duration = 2600){
  const el = document.getElementById('toast');
  if (!el) return;

  const safeDuration = Math.max(1200, Math.min(10000, Number(duration || 2600)));
  el.textContent = message;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), safeDuration);
}
