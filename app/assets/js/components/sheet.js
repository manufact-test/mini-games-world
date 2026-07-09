const overlay = () => document.getElementById('sheetOverlay');
const sheet = () => document.getElementById('sheet');

export function openSheet(html){
  const o = overlay();
  const s = sheet();
  if (!o || !s) return;
  s.innerHTML = html;
  o.classList.add('active');
  s.querySelectorAll('[data-close-sheet]').forEach(btn => btn.addEventListener('click', closeSheet));
}
export function closeSheet(){ overlay()?.classList.remove('active'); }
export function initSheet(){
  overlay()?.addEventListener('click', event => {
    if (event.target === overlay()) closeSheet();
  });
}
