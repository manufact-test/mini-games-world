<?php
declare(strict_types=1);

// MVP-16.1: one authoritative query-version owner for the accepted Telegram
// /start client graph. Historical import specifiers remain compatibility keys;
// every active resolved target version is owned here instead of app/v110.php.
return [
    'version' => 'v2-route-scoped-polling',
    'imports' => [
        '@mgw/clean-entry' => './assets/js/production-clean-entry-v110.js?v=1124&clock=single-writer&release=battleship-action-quarantine',
        '@mgw/main' => './assets/js/main-v110.js?v=1139&ux=1&sk=3&icons=c1efd5af&render=5&mvp15=unified-balance',
        '@mgw/i18n' => './assets/js/localization/i18n.js?v=1&mvp16=locale-keys-v1',
        './assets/js/api/client.js?v=34' => './assets/js/api/client.js?v=1132&mvp15=unified-profile',
        './assets/js/api/client.js?v=38' => './assets/js/api/client.js?v=1132&mvp15=unified-profile',
        './assets/js/api/client.js?v=46' => './assets/js/api/client.js?v=1132&mvp15=unified-profile',
        './assets/js/api/client.js?v=47' => './assets/js/api/client.js?v=1132&mvp15=unified-profile',
        './assets/js/config.js?v=38' => './assets/js/config.js?v=39&mvp15=match-economy',
        './assets/js/state.js?v=27' => './assets/js/state.js?v=30&mvp16=router-lifecycle',
        './assets/js/router.js?v=27' => './assets/js/router.js?v=29&b=871cb833d99d&mvp16=route-registry',
        './assets/js/ui.js?v=89' => './assets/js/ui.js?v=92&mvp15=unified-zone',
        './assets/js/screens/home-screen.js?v=74' => './assets/js/screens/home-screen.js?v=78&mvp15=weekly-bonus-wallet',
        './assets/js/screens/store-screen.js?v=34' => './assets/js/screens/store-screen.js?v=36&mvp16=primary-tab',
        './assets/js/screens/profile-screen-v110.js?v=1108' => './assets/js/screens/profile-screen-v110.js?v=1114&mvp16=primary-tab',
        './assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5' => './assets/js/main-v110-handoff-shell.js?v=1151&mvp16=unified-game-setup',
        './assets/js/games/unified-game-launcher.js?v=1&mvp16=unified-game-setup' => './assets/js/games/unified-game-launcher.js?v=2&mvp16=unified-game-setup',
        './assets/js/session.js?v=21' => './assets/js/session.js?v=1131',
        './assets/js/session.js?v=27' => './assets/js/session.js?v=1131',
        './assets/js/screens/search-screen-v102.js?v=103' => './assets/js/screens/search-screen-v102.js?v=107&search=route-scoped-lifecycle',
        './assets/js/screens/game-screen-v102-safe.js?v=102' => './assets/js/screens/game-screen-v102-safe.js?v=104&polling=route-cleanup',
        './assets/js/screens/game-screen-v102.js?v=102' => './assets/js/screens/game-screen-v102.js?v=104&clock=phase-b-single-writer&battleship=leave-guard',
        './assets/js/production-v100-optimistic-models.js?v=102' => './assets/js/production-v100-optimistic-models.js?v=104&clock=ttt-fresh60&battleship=registered-owner',
        './assets/js/production-v102-battleship-models.js?v=102' => './assets/js/production-v102-battleship-models.js?v=103&ready=authoritative-reset',
        './assets/js/production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a' => './assets/js/production-v110-readonly-game-sync.js?v=1112&terminal=nonblocking-watch',
        './assets/js/production-v110-targeted-interactions.js?v=1102' => './assets/js/production-v110-targeted-interactions.js?v=1105&zone=unified',
        './assets/js/production-v110-presence.js?v=1121&b=f5a28b030c69' => './assets/js/production-v110-presence.js?v=1123&zone=unified',
        './assets/js/games/game-invites-v110.js?v=1137&ux=1' => './assets/js/games/game-invites-v110.js?v=1140&zone=unified',
        './assets/js/games/tictactoe/renderer.js?v=53' => './assets/js/games/tictactoe/renderer.js?v=54&mark=full-size-nought',
        './assets/js/games/battleship/renderer.js?v=56' => './assets/js/games/battleship/renderer.js?v=60&shot=miss-no-impact',
        './assets/js/production-v110-acceptance-runtime.js?v=110' => './assets/js/production-v110-acceptance-runtime.js?v=130&clock=battleship-setup-single-owner',
        './assets/js/components/shield-king-visuals.js?v=125&sk=2' => './assets/js/components/shield-king-visuals.js?v=126&sk=3&icons=c1efd5af',
        './assets/js/components/preloader.js?v=42' => './assets/js/components/preloader.js?v=44&intro=v1141',
        './assets/js/games/game-card-copy.js?v=81&sk=2' => './assets/js/games/game-card-copy.js?v=83&sk=5&icons=c1efd5af&delivery=static',
    ],
    'assets' => [
        'main_css' => './assets/css/main.css?v=158&sk=3&icons=c1efd5af&render=30&mvp16=unified-primary-tabs',
        'consistency_css' => './assets/css/production-v95-consistency.css?v=96&battleship=pending-lock-only',
        'bootstrap' => './assets/js/app-bootstrap-v2.js?v=2&mvp16=version-manifest',
    ],
    'localization' => [
        'version' => 'keys-v1',
        'default_locale' => 'ru',
        'manifest' => './locales/manifest.json',
    ],
];