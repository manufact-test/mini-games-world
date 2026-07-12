const overlay = () => document.getElementById('sheetOverlay');
const sheet = () => document.getElementById('sheet');
const RESULT_GUARD_KEY = '__MGW_RESULT_SHEET_GUARD__';

export function openSheet(html){
  const o = overlay();
  const s = sheet();
  if (!o || !s) return;
  s.innerHTML = html;
  o.classList.add('active');
  s.querySelectorAll('[data-close-sheet]').forEach(btn => btn.addEventListener('click', closeSheet));
}

export function closeSheet(){
  overlay()?.classList.remove('active');
}

export function initSheet(){
  overlay()?.addEventListener('click', event => {
    if (event.target === overlay()) closeSheet();
  });

  const s = sheet();
  if (!s || window[RESULT_GUARD_KEY]?.observer) return;

  const guard = window[RESULT_GUARD_KEY] || {
    signature: '',
    shownAt: 0,
    observer: null,
  };

  guard.observer = new MutationObserver(() => {
    const o = overlay();
    if (!o?.classList.contains('active')) return;
    if (!s.querySelector('#newOpponent') || !s.querySelector('#goHome')) return;

    const title = String(s.querySelector('.sheet-head h2')?.textContent || '').trim();
    const text = String(s.querySelector('.sheet-head p')?.textContent || '').trim();
    if (!['Победа!', 'Поражение', 'Ничья'].includes(title)) return;

    const signature = `${title}|${text}`;
    const now = Date.now();
    if (signature === guard.signature && now - guard.shownAt < 15000) {
      o.classList.remove('active');
      return;
    }

    guard.signature = signature;
    guard.shownAt = now;
  });

  guard.observer.observe(s, { childList:true, subtree:true });
  window[RESULT_GUARD_KEY] = guard;
}
