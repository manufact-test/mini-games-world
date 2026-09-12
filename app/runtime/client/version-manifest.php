<?php
declare(strict_types=1);

$manifest = require __DIR__ . '/version-manifest-base-pr1353.php';
$manifest['imports']['./assets/js/screens/store-screen.js?v=34'] = './assets/js/screens/store-screen-checkers-effects-wrapper.js?v=1&mvp19_6=effects-live-board-v1&base_rev=4';
return $manifest;
