// MVP-19.3 Entry Effects V8 bounded bootstrap wrapper.
// Keep the accepted v2 bootstrap as the single sequencing owner; this wrapper
// only primes the Entry 03 presentation observer before handing off unchanged.
import './entry-effects/mgw-entry-effects-v8-legendary-strike.js?v=1';
await import('./app-bootstrap-v2-core.js?v=2&mvp16=version-manifest&entry_v8=legendary-strike-v1');
