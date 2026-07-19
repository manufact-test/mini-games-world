<?php
declare(strict_types=1);

final class RuntimeAccountOwnershipService
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function ensure(string $provider, string $legacyUserId, string $mgwId): array
    {
        $provider = strtolower(trim($provider));
        $legacyUserId = trim($legacyUserId);
        $mgwId = trim($mgwId);
        if (!in_array($provider, ['telegram', 'development'], true)) {
            throw new RuntimeException('Runtime account ownership provider is invalid.');
        }
        if ($legacyUserId === '' || strlen($legacyUserId) > 191) {
            throw new RuntimeException('Runtime account ownership legacy identity is invalid.');
        }
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new RuntimeException('Runtime account ownership MGW ID is invalid.');
        }

        $accountRef = 'legacy:' . $legacyUserId;
        if (strlen($accountRef) > 255) {
            throw new RuntimeException('Runtime account reference is too long.');
        }

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $provider,
            $legacyUserId,
            $mgwId,
            $accountRef
        ): array {
            $lock = $database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
            $rows = $database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE account_ref = :account_ref OR mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id' . $lock,
                [
                    'account_ref' => $accountRef,
                    'mgw_id' => $mgwId,
                    'legacy_user_id' => $legacyUserId,
                ]
            );
            if (count($rows) > 1) {
                throw new RuntimeException('Runtime account ownership collides with multiple rows.');
            }

            $created = false;
            if ($rows === []) {
                $timestamp = $this->timestamp();
                $source = [
                    'provider' => $provider,
                    'provider_subject' => $legacyUserId,
                    'mgw_id' => $mgwId,
                    'account_ref' => $accountRef,
                ];
                $database->execute(
                    'INSERT INTO mgw_account_ownership (
                        account_ref, mgw_id, legacy_user_id, ownership_status,
                        source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
                     ) VALUES (
                        :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
                        :source_type, :source_ref, :source_sha256, :created_at_utc, :verified_at_utc
                     )',
                    [
                        'account_ref' => $accountRef,
                        'mgw_id' => $mgwId,
                        'legacy_user_id' => $legacyUserId,
                        'ownership_status' => 'active',
                        'source_type' => 'runtime_identity',
                        'source_ref' => $provider . ':' . $legacyUserId,
                        'source_sha256' => hash('sha256', LedgerIntegrity::canonicalJson($source)),
                        'created_at_utc' => $timestamp,
                        'verified_at_utc' => $timestamp,
                    ]
                );
                $created = true;
                $rows = $database->fetchAll(
                    'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
                     FROM mgw_account_ownership WHERE account_ref = :account_ref',
                    ['account_ref' => $accountRef]
                );
            }

            if (count($rows) !== 1) {
                throw new RuntimeException('Runtime account ownership could not be established.');
            }
            $row = $rows[0];
            if ((string)($row['account_ref'] ?? '') !== $accountRef
                || (string)($row['mgw_id'] ?? '') !== $mgwId
                || (string)($row['legacy_user_id'] ?? '') !== $legacyUserId
                || (string)($row['ownership_status'] ?? '') !== 'active') {
                throw new RuntimeException('Runtime account ownership conflicts with the authenticated identity.');
            }

            return [
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'legacy_user_id' => $legacyUserId,
                'created' => $created,
            ];
        });
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
