const overlay = () => document.getElementById('sheetOverlay');
const sheet = () => document.getElementById('sheet');

let lifecycleObserver = null;
let viewportListenersBound = false;
let viewportRaf = 0;
const sheetHistory = [];

function syncSheetViewport(){
  const o = overlay();
  if (!o) return;
  const vv = window.visualViewport;
  const height = Math.max(1, Math.round(vv?.height || window.innerHeight || document.documentElement.clientHeight || 1));
  const width = Math.max(1, Math.round(vv?.width || window.innerWidth || document.documentElement.clientWidth || 1));
  const offsetTop = Math.max(0, Math.round(vv?.offsetTop || 0));
  document.documentElement.style.setProperty('--mgw-sheet-viewport-height', height + 'px');
  document.documentElement.style.setProperty('--mgw-sheet-viewport-top', offsetTop + 'px');
  o.classList.toggle('mgw-short-visual-viewport', width > height && height <= 520);
}

function scheduleSheetViewportSync(){
  if (viewportRaf) cancelAnimationFrame(viewportRaf);
  viewportRaf = requestAnimationFrame(() => {
    viewportRaf = 0;
    syncSheetViewport();
    const s = sheet();
    const active = document.activeElement;
    if (s && active instanceof HTMLElement && s.contains(active) && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) {
      active.scrollIntoView({ block:'center', inline:'nearest' });
    }
  });
}

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
  syncSheetViewport();
  requestAnimationFrame(syncSheetViewport);
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

  if (!viewportListenersBound) {
    viewportListenersBound = true;
    syncSheetViewport();
    window.addEventListener('resize', scheduleSheetViewportSync, { passive:true });
    window.addEventListener('orientationchange', scheduleSheetViewportSync, { passive:true });
    window.visualViewport?.addEventListener('resize', scheduleSheetViewportSync, { passive:true });
    window.visualViewport?.addEventListener('scroll', scheduleSheetViewportSync, { passive:true });
  }

  s.addEventListener('focusin', event => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || !/^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) return;
    scheduleSheetViewportSync();
    window.setTimeout(scheduleSheetViewportSync, 80);
    window.setTimeout(scheduleSheetViewportSync, 180);
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
