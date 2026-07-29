<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackSnapshotMaterializer
{
    public const CONTRACT_VERSION = 'v1-normalized-accounts';

    public function __construct(private DatabaseConnectionInterface $database)
    {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Rollback snapshot materialization requires MySQL/MariaDB.');
        }
    }

    public function materialize(
        array $sourceSnapshot,
        int $sourceStateRevision,
        string $sourceStateSha256
    ): array {
        if ($sourceStateRevision < 1) {
            throw new InvalidArgumentException('Rollback materialization revision must be positive.');
        }
        $sourceStateSha256 = $this->exactSha($sourceStateSha256);
        if ($sourceStateSha256 === '') {
            throw new InvalidArgumentException('Rollback materialization source SHA must be exact.');
        }
        if (!isset($sourceSnapshot['users']) || !is_array($sourceSnapshot['users'])) {
            throw new RuntimeException('Rollback materialization source users are unavailable.');
        }
        $actualSourceSha = hash('sha256', $this->canonicalJson($sourceSnapshot));
        if (!hash_equals($sourceStateSha256, $actualSourceSha)) {
            throw new RuntimeException('Rollback materialization source fingerprint mismatch.');
        }

        $snapshot = $sourceSnapshot;
        $changedUsers = 0;
        $changedFields = 0;
        $seenLegacyIds = [];

        foreach ($sourceSnapshot['users'] as $key => $record) {
            if (!is_array($record)) {
                throw new RuntimeException('Rollback materialization user record is not an object.');
            }
            $legacyId = trim((string)($record['id'] ?? (is_string($key) || is_int($key) ? $key : '')));
            if ($legacyId === '' || isset($seenLegacyIds[$legacyId])) {
                throw new RuntimeException('Rollback materialization user ID is missing or duplicated.');
            }
            $seenLegacyIds[$legacyId] = true;

            $provider = !empty($record['is_dev_user']) ? 'development' : 'telegram';
            $providerSubject = trim((string)($record['telegram_id'] ?? $record['id'] ?? $legacyId));
            if ($providerSubject === '') {
                throw new RuntimeException('Rollback materialization provider subject is missing.');
            }

            $ownership = $this->exactOne(
                $this->database->fetchAll(
                    'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
                     FROM mgw_account_ownership
                     WHERE legacy_user_id = :legacy_user_id',
                    ['legacy_user_id' => $legacyId]
                ),
                'account ownership'
            );
            $mgwId = trim((string)($ownership['mgw_id'] ?? ''));
            if ((string)($ownership['account_ref'] ?? '') !== 'legacy:' . $legacyId
                || (string)($ownership['legacy_user_id'] ?? '') !== $legacyId
                || (string)($ownership['ownership_status'] ?? '') !== 'active'
                || !MgwIdGenerator::isValid($mgwId)) {
                throw new RuntimeException('Rollback materialization account ownership is invalid.');
            }

            $user = $this->exactOne(
                $this->database->fetchAll(
                    'SELECT status, display_name, username, avatar_provider, avatar_external_ref,
                            created_at_utc, updated_at_utc, last_seen_at_utc
                     FROM mgw_users
                     WHERE mgw_id = :mgw_id',
                    ['mgw_id' => $mgwId]
                ),
                'normalized user'
            );
            $providerIdentity = $this->exactOne(
                $this->database->fetchAll(
                    'SELECT mgw_id, provider, provider_subject, provider_username,
                            linked_at_utc, last_authenticated_at_utc
                     FROM mgw_identities
                     WHERE provider = :provider AND provider_subject = :provider_subject',
                    ['provider' => $provider, 'provider_subject' => $providerSubject]
                ),
                'provider identity'
            );
            $legacyIdentity = $this->exactOne(
                $this->database->fetchAll(
                    'SELECT mgw_id, provider, provider_subject
                     FROM mgw_identities
                     WHERE provider = :provider AND provider_subject = :provider_subject',
                    ['provider' => 'legacy_import', 'provider_subject' => $legacyId]
                ),
                'legacy identity'
            );
            if ((string)($providerIdentity['mgw_id'] ?? '') !== $mgwId
                || (string)($legacyIdentity['mgw_id'] ?? '') !== $mgwId
                || (string)($providerIdentity['provider'] ?? '') !== $provider
                || (string)($providerIdentity['provider_subject'] ?? '') !== $providerSubject
                || (string)($legacyIdentity['provider'] ?? '') !== 'legacy_import'
                || (string)($legacyIdentity['provider_subject'] ?? '') !== $legacyId) {
                throw new RuntimeException('Rollback materialization identity links are invalid.');
            }
            if ((string)($user['status'] ?? '') !== 'active') {
                throw new RuntimeException('Rollback materialization normalized user is not active.');
            }

            $displayName = trim((string)($user['display_name'] ?? ''));
            if ($displayName === '') {
                throw new RuntimeException('Rollback materialization display name is empty.');
            }
            $username = $this->nullable($user['username'] ?? null);
            $identityUsername = $this->nullable($providerIdentity['provider_username'] ?? null);
            if ($username !== $identityUsername) {
                throw new RuntimeException('Rollback materialization username sources disagree.');
            }

            $avatarProvider = $this->nullable($user['avatar_provider'] ?? null);
            $avatarExternalRef = $this->nullable($user['avatar_external_ref'] ?? null);
            if (($avatarExternalRef === null) !== ($avatarProvider === null)
                || ($avatarExternalRef !== null && $avatarProvider !== $provider)) {
                throw new RuntimeException('Rollback materialization avatar identity is invalid.');
            }

            $createdAt = $this->timestamp($user['created_at_utc'] ?? null, 'created_at_utc');
            $updatedAt = $this->timestamp($user['updated_at_utc'] ?? null, 'updated_at_utc');
            $lastSeenAt = $this->timestamp($user['last_seen_at_utc'] ?? null, 'last_seen_at_utc');
            $authenticatedAt = $this->timestamp(
                $providerIdentity['last_authenticated_at_utc'] ?? null,
                'last_authenticated_at_utc'
            );
            if ($updatedAt !== $lastSeenAt || $lastSeenAt !== $authenticatedAt) {
                throw new RuntimeException('Rollback materialization normalized activity timestamps disagree.');
            }
            if ((int)strtotime($createdAt) > (int)strtotime($lastSeenAt)) {
                throw new RuntimeException('Rollback materialization activity precedes account creation.');
            }

            $materialized = $record;
            $recordChangedFields = 0;
            $this->setField($materialized, 'first_name', $displayName, $recordChangedFields);
            if ($username === null) {
                $this->removeField($materialized, 'username', $recordChangedFields);
            } else {
                $this->setField($materialized, 'username', $username, $recordChangedFields);
            }

            $avatarAliases = ['photo_url', 'avatar_url', 'avatar'];
            if ($avatarExternalRef === null) {
                foreach ($avatarAliases as $alias) {
                    $this->removeField($materialized, $alias, $recordChangedFields);
                }
            } else {
                $targetAlias = 'photo_url';
                foreach ($avatarAliases as $alias) {
                    if (array_key_exists($alias, $materialized)) {
                        $targetAlias = $alias;
                        break;
                    }
                }
                $this->setField($materialized, $targetAlias, $avatarExternalRef, $recordChangedFields);
                foreach ($avatarAliases as $alias) {
                    if ($alias !== $targetAlias) {
                        $this->removeField($materialized, $alias, $recordChangedFields);
                    }
                }
            }

            $createdField = array_key_exists('registered_at', $materialized)
                ? 'registered_at'
                : (array_key_exists('created_at', $materialized) ? 'created_at' : 'registered_at');
            $this->setField($materialized, $createdField, $createdAt, $recordChangedFields);
            $this->setField($materialized, 'last_seen_at', $lastSeenAt, $recordChangedFields);
            if (array_key_exists('updated_at', $materialized)) {
                $this->setField($materialized, 'updated_at', $updatedAt, $recordChangedFields);
            }

            if ($recordChangedFields > 0) {
                $changedUsers++;
                $changedFields += $recordChangedFields;
            }
            $snapshot['users'][$key] = $materialized;
        }

        $materializedStateSha256 = hash('sha256', $this->canonicalJson($snapshot));

        return [
            'ok' => true,
            'contract_version' => self::CONTRACT_VERSION,
            'snapshot' => $snapshot,
            'source_state_revision' => $sourceStateRevision,
            'source_state_sha256' => $sourceStateSha256,
            'materialized_state_sha256' => $materializedStateSha256,
            'applied' => !hash_equals($sourceStateSha256, $materializedStateSha256),
            'changed_user_count' => $changedUsers,
            'changed_field_count' => $changedFields,
            'read_only' => true,
            'database_write_executed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function exactOne(array $rows, string $label): array
    {
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rollback materialization requires exactly one ' . $label . '.');
        }
        return $rows[0];
    }

    private function setField(array &$record, string $field, mixed $value, int &$changed): void
    {
        if (!array_key_exists($field, $record) || $record[$field] !== $value) {
            $record[$field] = $value;
            $changed++;
        }
    }

    private function removeField(array &$record, string $field, int &$changed): void
    {
        if (array_key_exists($field, $record)) {
            unset($record[$field]);
            $changed++;
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function timestamp(mixed $value, string $label): string
    {
        $value = trim((string)$value);
        $parsed = $value !== '' ? strtotime($value) : false;
        if ($parsed === false) {
            throw new RuntimeException('Rollback materialization timestamp is invalid: ' . $label . '.');
        }
        return gmdate(DATE_ATOM, (int)$parsed);
    }

    private function exactSha(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : '';
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
