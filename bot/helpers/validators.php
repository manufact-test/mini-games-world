<?php
declare(strict_types=1);

function clean_string(?string $value, int $max = 500): string {
    $value = trim((string)$value);
    $value = strip_tags($value);
    if (strlen($value) > $max) {
        $value = substr($value, 0, $max);
    }
    return $value;
}

function now_iso(): string {
    return gmdate('c');
}

function make_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}
