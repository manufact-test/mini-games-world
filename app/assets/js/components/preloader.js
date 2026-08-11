const APP_ENTRY_MIN_VISIBLE_MS = 2100;
const APP_ENTRY_FADE_MS = 380;

export function hidePreloader(){
  const el = document.getElementById('preloader');

  if (!el) {
    document.dispatchEvent(new CustomEvent('mgw:app-ready'));
    return;
  }

  // The app may bootstrap faster than the visual intro. Keep readiness ownership
  // unchanged, but do not reveal the app until one complete Shield King assembly
  // has had time to finish. performance.now() is measured from navigation start.
  const elapsed = Number.isFinite(performance?.now?.()) ? performance.now() : APP_ENTRY_MIN_VISIBLE_MS;
  const remaining = Math.max(0, APP_ENTRY_MIN_VISIBLE_MS - elapsed);

  window.setTimeout(() => {
    el.classList.add('hidden');

    // Wait for the opacity/visibility transition to finish before allowing
    // important in-app alerts. Otherwise a toast can be created underneath
    // the preloader and disappear before the player ever sees it.
    window.setTimeout(() => {
      document.dispatchEvent(new CustomEvent('mgw:app-ready'));
    }, APP_ENTRY_FADE_MS);
  }, remaining);
}
