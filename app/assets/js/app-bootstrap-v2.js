// MVP-16.1A: one top-level owner for the accepted Telegram /start client graph.
// The two existing graphs are intentionally preserved internally for this first
// cutover; their order is explicit here so later cleanup can happen behind one
// stable application entry instead of two independent module script tags.

const runtime = window.__MGW_APP_BOOTSTRAP_V2__ ||= {
  version:'v2-single-owner',
  started:false,
  ready:false,
};

if (!runtime.started) {
  runtime.started = true;

  await import('./production-clean-entry-v110.js?v=1124&clock=single-writer&release=battleship-action-quarantine');
  await import('./main-v110.js?v=1139&ux=1&sk=3&icons=c1efd5af&render=5&mvp15=unified-balance');

  runtime.ready = true;
  document.dispatchEvent(new CustomEvent('mgw:client-bootstrap-ready', {
    detail:{ version:runtime.version },
  }));
}
