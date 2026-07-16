<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (
    int $severity,
    string $message,
    string $file,
    int $line
): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
