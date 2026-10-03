<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$response = file_get_contents($root . '/bot/helpers/response.php');
$invites = file_get_contents($root . '/bot/invites.php');
if (!is_string($response) || !is_string($invites)) {
    throw new RuntimeException('Invite post-response projection sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($response, 'function mgw_register_api_after_response_hook(callable $hook): void')
        && str_contains($response, 'function mgw_finish_response_and_run_after_response_hooks(): void'),
    'Response helper must expose one explicit post-response hook owner.'
);
$assert(
    str_contains($response, "function json_response(array $data, int $status = 200, bool $runAfterResponseHooks = false): void")
        && str_contains($response, 'if ($runAfterResponseHooks) {')
        && str_contains($response, 'mgw_finish_response_and_run_after_response_hooks();'),
    'JSON response must opt into post-response work explicitly.'
);
$assert(
    str_contains($response, "json_response(['ok' => true] + mgw_normalize_api_data($data), 200, true);")
        && str_contains($response, "json_response(['ok' => false, 'error' => mgw_public_api_error($message)], $status);"),
    'Only successful API responses may run deferred hooks.'
);
$fastcgi = strpos($response, "function_exists('fastcgi_finish_request')");
$litespeed = strpos($response, "function_exists('litespeed_finish_request')");
$hookLoop = strpos($response, 'foreach ($hooks as $hook)');
$assert(
    $fastcgi !== false && $litespeed !== false && $hookLoop !== false
        && $fastcgi < $hookLoop && $litespeed < $hookLoop
        && str_contains($response, 'if (!$finished) {')
        && str_contains($response, 'background hook skipped'),
    'Deferred work must never run before a supported SAPI has finished the HTTP response.'
);
$assert(
    str_contains($response, "error_log('[MiniGamesWorld deferred response] hook failed: ' . get_class($error));"),
    'Deferred projection failures must be server-only and must not replace an already-delivered success response.'
);

$signalPublish = strpos($invites, '$inviteSignals->publish((string)($result[\'recipient_id\'] ?? \'\'), $result[\'invite\']);');
$deferRegister = strpos($invites, 'mgw_register_api_after_response_hook(');
$apiOk = strrpos($invites, 'api_ok($result);');
$assert(
    $signalPublish !== false && $deferRegister !== false && $apiOk !== false
        && $signalPublish < $deferRegister && $deferRegister < $apiOk,
    'Committed peer signal must publish before projection is deferred and before the success response exits.'
);
$assert(
    str_contains($invites, "$action !== 'sync'")
        && str_contains($invites, "$action !== 'create_link_draft'")
        && str_contains($invites, '$projectionTokens = $bridgeInviteTokens;')
        && str_contains($invites, 'static function () use ($db, $runtimeInviteProjector, $projectionTokens): void'),
    'Invite mutations must defer only exact changed-token projection while keeping sync/share-draft exclusions.'
);
$assert(
    str_contains($invites, "static fn(array $data): array => $runtimeInviteProjector->synchronizeTokens($data, $projectionTokens)"),
    'Deferred projection must preserve exact changed-token delta ownership.'
);
$assert(
    !str_contains($invites, "static fn(array $data): array => $runtimeInviteProjector->synchronizeTokens($data, $bridgeInviteTokens)"),
    'No synchronous bridge-token projection callback may remain on the invite response critical path.'
);

fwrite(STDOUT, "InvitePostResponseProjectionContractTest: {$assertions} assertions passed\n");
