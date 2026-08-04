<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if ($argc !== 1) {
    fwrite(STDERR, "Usage: provide the secret only through STDIN; command-line arguments are forbidden.\n");
    exit(2);
}

$secret = stream_get_contents(STDIN);
if (!is_string($secret)) {
    fwrite(STDERR, "Unable to read secret from STDIN.\n");
    exit(3);
}

$secret = rtrim($secret, "\r\n");
if ($secret === '' || strlen($secret) > 4096 || str_contains($secret, "\n") || str_contains($secret, "\r")) {
    if (function_exists('sodium_memzero')) sodium_memzero($secret);
    fwrite(STDERR, "STDIN must contain exactly one non-empty secret line.\n");
    exit(4);
}

$fingerprint = hash('sha256', $secret);
if (function_exists('sodium_memzero')) sodium_memzero($secret);
unset($secret);

fwrite(STDOUT, 'sha256:' . $fingerprint . PHP_EOL);
