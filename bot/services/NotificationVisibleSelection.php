<?php
declare(strict_types=1);

/**
 * Select a bounded Notification Center view after server-side visibility rules
 * have run. The badge is authoritative over all visible unread events, so
 * previously read cards must not displace an older unread item from the sheet.
 */
final class NotificationVisibleSelection
{
    /**
     * @param list<array{item:array<string,mixed>,raw:array<string,mixed>,invite:mixed}> $eligible
     * @return list<array{item:array<string,mixed>,raw:array<string,mixed>,invite:mixed}>
     */
    public static function select(array $eligible, int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) return [];

        $unread = [];
        $read = [];
        foreach ($eligible as $entry) {
            if (!is_array($entry) || !is_array($entry['item'] ?? null)) continue;
            if (empty($entry['item']['read'])) {
                if (count($unread) < $limit) $unread[] = $entry;
            } elseif (count($read) < $limit) {
                $read[] = $entry;
            }
            if (count($unread) >= $limit) break;
        }
        return array_slice(array_merge($unread, $read), 0, $limit);
    }
}
