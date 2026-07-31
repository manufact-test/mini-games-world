const overlay = () => document.getElementById('sheetOverlay');
const sheet = () => document.getElementById('sheet');

let lifecycleObserver = null;

export function openSheet(html){
  const o = overlay();
  const s = sheet();
  if (!o || !s) return;
  s.innerHTML = html;
  o.classList.add('active');
  s.querySelectorAll('[data-close-sheet]').forEach(btn => btn.addEventListener('click', closeSheet));
}

export function closeSheet(){
  const o = overlay();
  const s = sheet();
  if (!o || !s) return;
  const wasActive = o.classList.contains('active');
  o.classList.remove('active');
  s.replaceChildren();
  if (wasActive) document.dispatchEvent(new CustomEvent('mgw:sheet-closed'));
}

export function initSheet(){
  const o = overlay();
  const s = sheet();
  if (!o || !s) return;

  o.addEventListener('click', event => {
    if (event.target === o) closeSheet();
  });

  // Historical modules may still hold the same canonical sheet URL under an
  // older query revision. Whatever removes the active class, a closed sheet
  // must own no hidden HTML, invite token or stale action state.
  if (!lifecycleObserver && typeof MutationObserver === 'function') {
    lifecycleObserver = new MutationObserver(() => {
      if (!o.classList.contains('active') && s.childNodes.length) s.replaceChildren();
    });
    lifecycleObserver.observe(o, { attributes:true, attributeFilter:['class'] });
  }
}
