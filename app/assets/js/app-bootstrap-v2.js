// MVP-19.3 Entry Effects V8 bounded bootstrap wrapper.
// Keep the accepted v2 bootstrap as the single sequencing owner.
// Entry 01 keeps the accepted base-tier strike; Entry 02 adds Royal Ascension only.
import './entry-effects/mgw-entry-effects-v8-legendary-strike.js?v=2';
import './entry-effects/mgw-entry-effects-v8-royal-ascension.js?v=4';
await import('./app-bootstrap-v2-core.js?v=2&mvp16=version-manifest&entry_v8=entry01-strike-entry02-royal-ascension-v4');