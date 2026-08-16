const overlay = () => document.getElementById('sheetOverlay');
const sheet = () => document.getElementById('sheet');

let lifecycleObserver = null;
const sheetHistory = [];

export function openSheet(html, options = {}){
  const o = overlay();
  const s = sheet();
  if (!o || !s) return;

  const returnToPrevious = options?.returnToPrevious === true;
  const hasActiveSheet = o.classList.contains('active') && s.childNodes.length > 0;

  if (returnToPrevious && hasActiveSheet) {
    const previous = document.createDocumentFragment();
    while (s.firstChild) previous.appendChild(s.firstChild);
    sheetHistory.push(previous);
  } else {
    sheetHistory.length = 0;
    s.replaceChildren();
  }

  s.innerHTML = html;
  o.classList.add('active');
  bindCloseButtons(s);
}

export function closeSheet(){
  const o = overlay();
  const s = sheet();
  if (!o || !s) return;

  if (sheetHistory.length) {
    const previous = sheetHistory.pop();
    s.replaceChildren(previous);
    o.classList.add('active');
    return;
  }

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
      if (o.classList.contains('active')) return;
      sheetHistory.length = 0;
      if (s.childNodes.length) s.replaceChildren();
    });
    lifecycleObserver.observe(o, { attributes:true, attributeFilter:['class'] });
  }
}

function bindCloseButtons(root){
  root.querySelectorAll('[data-close-sheet]').forEach(btn => btn.addEventListener('click', closeSheet));
}
