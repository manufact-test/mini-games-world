// MVP-19.3 Entry Effects V8 bounded bootstrap wrapper.
// Keep the accepted v2 bootstrap as the single sequencing owner.
// Entry 01 keeps the accepted base-tier strike on the existing path.
// Entry 02 and Entry 03 are presentation-only and must never gate canonical game bootstrap/readiness.
import './entry-effects/mgw-entry-effects-v8-legendary-strike.js?v=2';
await import('./app-bootstrap-v2-core.js?v=2&mvp16=version-manifest&entry_v8=entry01-strike-entry02-royal-ascension-entry03-lord-entrance-v4');
void import('./entry-effects/mgw-entry-effects-v8-royal-ascension.js?v=4').catch(() => {
  // Preserve the accepted v7 Entry 02 presentation if the optional V8 module cannot load.
});
void import('./entry-effects/mgw-entry-effects-v8-lord-entrance.js?v=4').catch(() => {
  // Preserve the accepted v7 Entry 03 presentation if the optional premium scene cannot load.
});
