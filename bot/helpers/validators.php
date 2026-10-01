<?php
declare(strict_types=1);

function clean_string(?string $value, int $max = 500): string {
    $value = trim((string)$value);
    $value = strip_tags($value);

    // Runtime strings are persisted as UTF-8 JSON. Normalize malformed legacy
    // bytes before truncation, then preserve the historical byte cap without
    // ever cutting through a multi-byte code point.
    if ($value !== '' && preg_match('//u', $value) !== 1) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $decoded = is_string($encoded) ? json_decode($encoded, true) : '';
        $value = is_string($decoded) ? $decoded : '';
    }

    if ($max >= 0 && strlen($value) > $max) {
        $value = substr($value, 0, $max);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
    }
    return $value;
}

function now_iso(): string {
    return gmdate('c');
}

function make_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}
