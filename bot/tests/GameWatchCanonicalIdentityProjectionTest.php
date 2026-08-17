<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$watch = file_get_contents($root . '/bot/game-watch.php');
$response = file_get_contents($root . '/bot/helpers/response.php');
if (!is_string($watch) || $watch === '' || !is_string($response) || $response === '') {
    throw new RuntimeException('Unable to read game-watch/response owners.');
}

if (!str_contains($watch, "getUserFromRequest(\$payload, false)")) {
    throw new RuntimeException('Game watch must keep lightweight provider authorization.');
}

if (!str_contains($watch, "api_ok([\n        'game' => \$game,")) {
    throw new RuntimeException('Game watch must use the shared successful API projection pipeline.');
}

if (preg_match('/json_response\s*\(\s*\[\s*[\'\"]ok[\'\"]\s*=>\s*true/s', $watch) === 1) {
    throw new RuntimeException('Game watch must not bypass API normalization with a direct success json_response.');
}

if (!str_contains($response, '$data = mgw_project_canonical_game_identity($data);')) {
    throw new RuntimeException('Shared API normalization must retain canonical game identity projection.');
}

if (!str_contains($response, "json_response(['ok' => true] + mgw_normalize_api_data(\$data));")) {
    throw new RuntimeException('api_ok must retain the canonical normalization owner.');
}

fwrite(STDOUT, "Game watch canonical identity projection contract: OK\n");
