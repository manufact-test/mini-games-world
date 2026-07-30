const ALLOWED_SCREENS = new Set(['boot', 'home', 'error']);

export function createRuntimeRouter(root){
  if (!(root instanceof HTMLElement)) throw new TypeError('Runtime root element is required.');

  function show(screen){
    if (!ALLOWED_SCREENS.has(screen)) throw new Error(`Unknown runtime screen: ${screen}`);
    for (const element of root.querySelectorAll('[data-screen]')) {
      const active = element.getAttribute('data-screen') === screen;
      element.hidden = !active;
      element.classList.toggle('is-active', active);
    }
    root.setAttribute('data-active-screen', screen);
  }

  return Object.freeze({ show });
}
