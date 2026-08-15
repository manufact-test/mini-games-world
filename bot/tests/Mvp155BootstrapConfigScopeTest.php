<?php
declare(strict_types=1);

$apiPath = dirname(__DIR__) . '/api.php';
$bootstrapPath = dirname(__DIR__) . '/core/bootstrap.php';
$api = file_get_contents($apiPath);
$bootstrap = file_get_contents($bootstrapPath);

if (!is_string($api) || !is_string($bootstrap)) {
    throw new RuntimeException('Unable to read MVP-15.5 runtime sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains($bootstrap, '$config = MatchEconomyRuntimeConfig::apply($config);'),
    'bootstrap must publish the canonical Match economy projection into application config'
);
$assert(
    str_contains($api, "'match_economy' => MatchEconomyRuntimeConfig::publicStatus(\$config)"),
    'bootstrap response must expose canonical Match economy status'
);
$assert(
    preg_match('/\$db->transaction\(function \(array &\$data\) use \(([^)]*)\)/s', $api, $matches) === 1,
    'api transaction closure capture list was not found'
);
$captureList = (string)($matches[1] ?? '');
$assert(
    preg_match('/(?:^|,\s*)\$config(?:\s*,|$)/', trim($captureList)) === 1,
    'api transaction closure must capture $config before calling MatchEconomyRuntimeConfig::publicStatus()'
);

fwrite(STDOUT, "Mvp155BootstrapConfigScopeTest passed: 4 assertions.\n");
