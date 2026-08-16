<?php
declare(strict_types=1);

require_once __DIR__ . '/MgwIdentityPolicy.php';

final class MgwProfileService
{
    private const CLAIM_ATTEMPTS = 10;

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function publicProfile(string $mgwId): array
    {
        $mgwId = trim($mgwId);
        if (!MgwIdGenerator::isValid($mgwId)) throw new RuntimeException('Authenticated MGW profile id is invalid.');
        $this->ensureCanonicalIdentity($mgwId);
        $users = $this->database->fetchAll(
            'SELECT mgw_id, status, nickname, equipped_avatar_item_id, preferred_locale,
                    created_at_utc, updated_at_utc, last_seen_at_utc
             FROM mgw_users WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        );
        if (count($users) !== 1) throw new RuntimeException('Authenticated MGW profile is unavailable.');
        $identities = $this->database->fetchAll(
            'SELECT provider, linked_at_utc FROM mgw_identities
             WHERE mgw_id = :mgw_id ORDER BY linked_at_utc ASC, provider ASC',
            ['mgw_id' => $mgwId]
        );
        $publicIdentities = [];
        foreach ($identities as $identity) {
            if (!is_array($identity)) continue;
            $provider = strtolower(trim((string)($identity['provider'] ?? '')));
            if (!MgwIdentityPolicy::isPublicIdentityProvider($provider)) continue;
            $publicIdentities[] = [
                'provider' => $provider,
                'linked_at' => $this->nullableString($identity['linked_at_utc'] ?? null),
            ];
        }
        $user = $users[0];
        $nickname = (string)$user['nickname'];
        $avatarItemId = MgwIdentityPolicy::normalizeAvatarItemId($user['equipped_avatar_item_id'] ?? MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID);
        return [
            'mgw_id' => (string)$user['mgw_id'],
            'status' => (string)($user['status'] ?? 'active'),
            'nickname' => $nickname,
            'display_name' => $nickname,
            'username' => null,
            'avatar' => ['item_id' => $avatarItemId],
            'preferred_locale' => $this->nullableString($user['preferred_locale'] ?? null),
            'identities' => $publicIdentities,
            'created_at' => $this->nullableString($user['created_at_utc'] ?? null),
            'updated_at' => $this->nullableString($user['updated_at_utc'] ?? null),
            'last_seen_at' => $this->nullableString($user['last_seen_at_utc'] ?? null),
        ];
    }

    public function updateProfile(string $mgwId, array $changes): array
    {
        $mgwId = trim($mgwId);
        if (!MgwIdGenerator::isValid($mgwId)) throw new InvalidArgumentException('MGW profile update invalid: id');
        $sets = [];
        $parameters = ['mgw_id' => $mgwId, 'updated_at' => $this->timestamp()];
        if (array_key_exists('nickname', $changes)) {
            $nickname = MgwIdentityPolicy::normalizeNickname($changes['nickname']);
            $sets[] = 'nickname = :nickname';
            $sets[] = 'display_name = :display_name';
            $parameters['nickname'] = $nickname;
            $parameters['display_name'] = $nickname;
        }
        if (array_key_exists('avatar_item_id', $changes)) {
            $sets[] = 'equipped_avatar_item_id = :avatar_item_id';
            $parameters['avatar_item_id'] = MgwIdentityPolicy::normalizeAvatarItemId($changes['avatar_item_id']);
        }
        if (array_key_exists('preferred_locale', $changes)) {
            $sets[] = 'preferred_locale = :preferred_locale';
            $parameters['preferred_locale'] = MgwIdentityPolicy::normalizeLocale($changes['preferred_locale']);
        }
        if ($sets === []) throw new InvalidArgumentException('MGW profile update invalid: empty');
        $sets[] = 'updated_at_utc = :updated_at';
        try {
            $affected = $this->database->execute(
                'UPDATE mgw_users SET ' . implode(', ', $sets) . ' WHERE mgw_id = :mgw_id',
                $parameters
            );
            if ($affected !== 1) throw new RuntimeException('Authenticated MGW profile is unavailable.');
        } catch (PDOException $error) {
            if (MgwIdentityPolicy::isUniqueViolation($error)) throw new RuntimeException(MgwIdentityPolicy::NICKNAME_TAKEN_ERROR, 0, $error);
            throw $error;
        }
        return $this->publicProfile($mgwId);
    }

    private function ensureCanonicalIdentity(string $mgwId): void
    {
        for ($attempt = 1; $attempt <= self::CLAIM_ATTEMPTS; $attempt++) {
            $rows = $this->database->fetchAll(
                'SELECT nickname, equipped_avatar_item_id FROM mgw_users WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if (count($rows) !== 1) throw new RuntimeException('Authenticated MGW profile is unavailable.');
            $nickname = trim((string)($rows[0]['nickname'] ?? ''));
            $avatarItemId = trim((string)($rows[0]['equipped_avatar_item_id'] ?? ''));
            if ($nickname !== '' && in_array($avatarItemId, MgwIdentityPolicy::STARTER_AVATAR_ITEM_IDS, true)) return;
            $candidate = $nickname !== '' ? $nickname : MgwIdentityPolicy::generateNickname();
            try {
                $this->database->execute(
                    'UPDATE mgw_users
                     SET nickname = CASE WHEN nickname IS NULL OR nickname = \'\' THEN :nickname ELSE nickname END,
                         display_name = :display_name,
                         username = NULL,
                         avatar_provider = NULL,
                         avatar_external_ref = NULL,
                         equipped_avatar_item_id = CASE
                            WHEN equipped_avatar_item_id IS NULL OR equipped_avatar_item_id = \'\' THEN :avatar_item_id
                            ELSE equipped_avatar_item_id
                         END,
                         updated_at_utc = :updated_at
                     WHERE mgw_id = :mgw_id',
                    [
                        'nickname' => $candidate,
                        'display_name' => $candidate,
                        'avatar_item_id' => MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID,
                        'updated_at' => $this->timestamp(),
                        'mgw_id' => $mgwId,
                    ]
                );
                return;
            } catch (PDOException $error) {
                if (!MgwIdentityPolicy::isUniqueViolation($error) || $attempt === self::CLAIM_ATTEMPTS) throw $error;
            }
        }
        throw new RuntimeException('Unable to claim canonical MGW identity.');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
