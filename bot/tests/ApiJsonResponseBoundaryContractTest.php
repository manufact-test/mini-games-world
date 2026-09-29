<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$response = file_get_contents($root . '/bot/helpers/response.php');
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
$assertContains('Не удалось сформировать ответ API.', $response, 'Encoding failure must still return a valid public JSON error');

fwrite(STDOUT, "ApiJsonResponseBoundaryContractTest: {$assertions} assertions passed\n");
