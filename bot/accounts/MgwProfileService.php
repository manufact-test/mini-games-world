<?php
declare(strict_types=1);

final class MgwProfileService
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function publicProfile(string $mgwId): array
    {
        $mgwId = trim($mgwId);
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new RuntimeException('Authenticated MGW profile id is invalid.');
        }

        $users = $this->database->fetchAll(
            'SELECT mgw_id, status, display_name, username,
                    avatar_provider, avatar_external_ref, avatar_storage_key,
                    avatar_mime_type, avatar_width, avatar_height,
                    created_at_utc, updated_at_utc, last_seen_at_utc
             FROM mgw_users
             WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        );
        if (count($users) !== 1) {
            throw new RuntimeException('Authenticated MGW profile is unavailable.');
        }

        $identities = $this->database->fetchAll(
            'SELECT provider, linked_at_utc
             FROM mgw_identities
             WHERE mgw_id = :mgw_id
             ORDER BY linked_at_utc ASC, provider ASC',
            ['mgw_id' => $mgwId]
        );

        $user = $users[0];
        $publicIdentities = [];
        foreach ($identities as $identity) {
            if (!is_array($identity)) continue;
            $provider = strtolower(trim((string)($identity['provider'] ?? '')));
            if ($provider === '') continue;
            $publicIdentities[] = [
                'provider' => $provider,
                'linked_at' => $this->nullableString($identity['linked_at_utc'] ?? null),
            ];
        }

        return [
            'mgw_id' => (string)$user['mgw_id'],
            'status' => (string)($user['status'] ?? 'active'),
            'display_name' => (string)($user['display_name'] ?? 'Игрок'),
            'username' => $this->nullableString($user['username'] ?? null),
            'avatar' => [
                'provider' => $this->nullableString($user['avatar_provider'] ?? null),
                'external_ref' => $this->nullableString($user['avatar_external_ref'] ?? null),
                'storage_key' => $this->nullableString($user['avatar_storage_key'] ?? null),
                'mime_type' => $this->nullableString($user['avatar_mime_type'] ?? null),
                'width' => $this->nullablePositiveInt($user['avatar_width'] ?? null),
                'height' => $this->nullablePositiveInt($user['avatar_height'] ?? null),
            ],
            'identities' => $publicIdentities,
            'created_at' => $this->nullableString($user['created_at_utc'] ?? null),
            'updated_at' => $this->nullableString($user['updated_at_utc'] ?? null),
            'last_seen_at' => $this->nullableString($user['last_seen_at_utc'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }
}
