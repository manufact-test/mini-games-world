window.__MGW_BUILD__ = 'v110-mvp18-2-friends-ui-on-reconnect-route-v3';

// MVP-17.4 remains the active Telegram/Test composition owner. Keep reconnect
// and the accepted v110 shell in their established order; MVP-18.2 only adds
// the Friends screen after those owners without forking their lifecycle.
import './production-v110-reconnect-v174.js?v=1';
import './main-v110.js?v=1139&ux=1&sk=3&icons=c1efd5af&render=5&mvp15=unified-balance';
import './screens/friends-screen-v110.js?v=1&mvp18=friends-ui';
