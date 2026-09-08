// MVP-16.1: one top-level owner for the accepted Telegram /start client graph.
// Query-version targets are resolved exclusively by the v110 import map built
// from runtime/client/version-manifest.php, so this bootstrap owns sequencing
// without becoming a second version manifest.

const runtime = window.__MGW_APP_BOOTSTRAP_V2__ ||= {
  version:'v2-single-owner',
  started:false,
  ready:false,
};

if (!runtime.started) {
  runtime.started = true;

  await import('@mgw/clean-entry');
  await import('@mgw/main');

  runtime.ready = true;
  document.dispatchEvent(new CustomEvent('mgw:client-bootstrap-ready', {
    detail:{ version:runtime.version },
  }));
}
