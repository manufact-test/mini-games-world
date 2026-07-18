<?php
declare(strict_types=1);

final class NotificationRuntimeAuditSource
{
    /** @return list<string> */
    public function userIds(array $snapshot): array
    {
        $users = is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [];
        $seen = [];
        $userIds = [];

        foreach ($users as $sourceKey => $record) {
            if (!is_array($record)) {
                throw new RuntimeException('JSON user record is not an object.');
            }

            $fallbackId = is_int($sourceKey) || is_string($sourceKey) ? (string)$sourceKey : '';
            $legacyUserId = trim((string)($record['id'] ?? $fallbackId));
            if ($legacyUserId === '') {
                throw new RuntimeException('JSON user record has no stable ID.');
            }

            $seenKey = 'legacy:' . $legacyUserId;
            if (isset($seen[$seenKey])) {
                throw new RuntimeException('JSON users contain a duplicate stable ID.');
            }

            $seen[$seenKey] = true;
            $userIds[] = $legacyUserId;
        }

        return $userIds;
    }
}
