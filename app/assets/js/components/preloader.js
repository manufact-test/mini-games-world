export function hidePreloader(){
  const el = document.getElementById('preloader');

  if (!el) {
    document.dispatchEvent(new CustomEvent('mgw:app-ready'));
    return;
  }

  setTimeout(() => {
    el.classList.add('hidden');

    // Wait for the opacity/visibility transition to finish before allowing
    // important in-app alerts. Otherwise a toast can be created underneath
    // the preloader and disappear before the player ever sees it.
    setTimeout(() => {
      document.dispatchEvent(new CustomEvent('mgw:app-ready'));
    }, 380);
  }, 450);
}
