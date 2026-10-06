<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$response = file_get_contents($root . '/bot/helpers/response.php');
$catalog = json_decode((string)file_get_contents($root . '/app/locales/ru.json'), true, flags: JSON_THROW_ON_ERROR);
if (!is_string($response)) throw new RuntimeException('Unable to read response helper.');

$assertions = 0;
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) throw new RuntimeException($message . ': missing ' . $needle);
};

$assertContains("if (PHP_SAPI !== 'cli')", $response, 'Browser JSON must have a dedicated non-CLI boundary');
$assertContains("ini_set('display_errors', '0')", $response, 'PHP warnings/notices must not leak into browser JSON');
$assertContains("ini_set('html_errors', '0')", $response, 'HTML-formatted PHP diagnostics must not leak into JSON');
$assertContains('JSON_INVALID_UTF8_SUBSTITUTE', $response, 'Malformed legacy/database UTF-8 must not produce an empty HTTP 200 body');
$assertContains('if ($json === false)', $response, 'JSON encoding failure must fail closed');
$assertContains('http_response_code(500)', $response, 'Encoding failure must not remain HTTP 200');
$assertContains("ServerLocalization::copy('server.response.generic_failed'", $response, 'Encoding failure must resolve the generic public JSON error through canonical locale ownership');
$assertions++;
if (($catalog['server']['response']['generic_failed'] ?? null) !== 'Не удалось выполнить действие. Попробуйте ещё раз.') {
    throw new RuntimeException('Canonical generic public JSON error copy changed.');
}

fwrite(STDOUT, "ApiJsonResponseBoundaryContractTest: {$assertions} assertions passed\n");
