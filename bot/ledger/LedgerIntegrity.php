<?php
declare(strict_types=1);

final class LedgerIntegrity
{
    public static function requestHash(array $request): string
    {
        return hash('sha256', self::canonicalJson($request));
    }

    public static function entryHash(array $entry): string
    {
        return hash('sha256', self::canonicalJson([
            'entry_id' => (string)($entry['entry_id'] ?? ''),
            'idempotency_key' => (string)($entry['idempotency_key'] ?? ''),
            'account_ref' => (string)($entry['account_ref'] ?? ''),
            'mgw_id' => self::nullableText($entry['mgw_id'] ?? null),
            'legacy_user_id' => self::nullableText($entry['legacy_user_id'] ?? null),
            'asset_code' => (string)($entry['asset_code'] ?? ''),
            'available_delta' => (int)($entry['available_delta'] ?? 0),
            'reserved_delta' => (int)($entry['reserved_delta'] ?? 0),
            'available_before' => (int)($entry['available_before'] ?? 0),
            'available_after' => (int)($entry['available_after'] ?? 0),
            'reserved_before' => (int)($entry['reserved_before'] ?? 0),
            'reserved_after' => (int)($entry['reserved_after'] ?? 0),
            'category' => (string)($entry['category'] ?? ''),
            'source_type' => (string)($entry['source_type'] ?? ''),
            'source_ref' => self::nullableText($entry['source_ref'] ?? null),
            'reservation_id' => self::nullableText($entry['reservation_id'] ?? null),
            'metadata' => self::jsonValue($entry['metadata_json'] ?? $entry['metadata'] ?? null),
            'previous_entry_sha256' => self::nullableText($entry['previous_entry_sha256'] ?? null),
            'created_at_utc' => (string)($entry['created_at_utc'] ?? ''),
        ]));
    }

    public static function reservationEventHash(array $event): string
    {
        return hash('sha256', self::canonicalJson([
            'event_id' => (string)($event['event_id'] ?? ''),
            'reservation_id' => (string)($event['reservation_id'] ?? ''),
            'event_key' => (string)($event['event_key'] ?? ''),
            'event_type' => (string)($event['event_type'] ?? ''),
            'available_delta' => (int)($event['available_delta'] ?? 0),
            'reserved_delta' => (int)($event['reserved_delta'] ?? 0),
            'metadata' => self::jsonValue($event['metadata_json'] ?? $event['metadata'] ?? null),
            'created_at_utc' => (string)($event['created_at_utc'] ?? ''),
        ]));
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    public static function decodeJson(mixed $value): mixed
    {
        return self::jsonValue($value);
    }

    private static function jsonValue(mixed $value): mixed
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) return self::canonicalize($value);

        try {
            return self::canonicalize(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return $value;
        }
    }

    private static function nullableText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);
        return $value;
    }
}
