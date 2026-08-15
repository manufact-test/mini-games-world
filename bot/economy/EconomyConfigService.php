<?php
declare(strict_types=1);

final class EconomyConfigService
{
    public function __construct(private DatabaseConnectionInterface $database)
    {
    }

    public function current(): array
    {
        $version = $this->currentVersion(false);
        $entry = $this->versionEntry($version, false);
        $entry['simulation'] = EconomyConfigSimulator::simulate($entry['config']);
        return $entry;
    }

    public function history(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->database->fetchAll(
            'SELECT version, schema_version, config_json, config_sha256, previous_version, change_type, '
            . 'source_version, actor_ref, reason, created_at_utc '
            . 'FROM mgw_economy_config_versions ORDER BY version DESC LIMIT ' . $limit
        );

        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $entry = $this->rowToEntry($row);
            $previous = $entry['previous_version'];
            $entry['before'] = $previous === null ? null : $this->versionEntry($previous, false)['config'];
            $entry['after'] = $entry['config'];
            $entries[] = $entry;
        }
        return $entries;
    }

    public function update(array $candidate, string $actorRef, string $reason): array
    {
        $config = EconomyConfigDefinition::normalize($candidate);
        $actorRef = $this->actorRef($actorRef);
        $reason = $this->reason($reason);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($config, $actorRef, $reason): array {
            $currentVersion = $this->currentVersion(true);
            $current = $this->versionEntry($currentVersion, false);
            $json = EconomyConfigDefinition::canonicalJson($config);
            $sha = hash('sha256', $json);
            if (hash_equals((string)$current['config_sha256'], $sha)) {
                throw new InvalidArgumentException('Economy config is unchanged.');
            }

            $newVersion = $this->nextVersion($currentVersion);
            $this->insertVersion(
                $newVersion,
                $currentVersion,
                'update',
                null,
                $actorRef,
                $reason,
                $json,
                $sha
            );
            $this->moveCurrentVersion($currentVersion, $newVersion);
            return $this->current();
        });
    }

    public function rollback(int $targetVersion, string $actorRef, string $reason): array
    {
        if ($targetVersion < 1) {
            throw new InvalidArgumentException('Rollback target version is invalid.');
        }
        $actorRef = $this->actorRef($actorRef);
        $reason = $this->reason($reason);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($targetVersion, $actorRef, $reason): array {
            $currentVersion = $this->currentVersion(true);
            if ($targetVersion === $currentVersion) {
                throw new InvalidArgumentException('Rollback target is already current.');
            }
            $target = $this->versionEntry($targetVersion, false);
            $json = EconomyConfigDefinition::canonicalJson($target['config']);
            $sha = hash('sha256', $json);
            $current = $this->versionEntry($currentVersion, false);
            if (hash_equals((string)$current['config_sha256'], $sha)) {
                throw new InvalidArgumentException('Rollback target has the same config as current version.');
            }

            $newVersion = $this->nextVersion($currentVersion);
            $this->insertVersion(
                $newVersion,
                $currentVersion,
                'rollback',
                $targetVersion,
                $actorRef,
                $reason,
                $json,
                $sha
            );
            $this->moveCurrentVersion($currentVersion, $newVersion);
            return $this->current();
        });
    }

    private function currentVersion(bool $lock): int
    {
        $sql = 'SELECT current_version FROM mgw_economy_config_state WHERE singleton_id = 1';
        if ($lock && $this->database->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $rows = $this->database->fetchAll($sql);
        if (count($rows) !== 1 || !is_array($rows[0] ?? null)) {
            throw new RuntimeException('Economy config current-version state is unavailable.');
        }
        $version = (int)($rows[0]['current_version'] ?? 0);
        if ($version < 1) {
            throw new RuntimeException('Economy config current-version state is invalid.');
        }
        return $version;
    }

    private function nextVersion(int $currentVersion): int
    {
        $maxVersion = (int)$this->database->fetchValue('SELECT COALESCE(MAX(version), 0) FROM mgw_economy_config_versions');
        if ($maxVersion !== $currentVersion) {
            throw new RuntimeException('Economy config history and current pointer diverged.');
        }
        return $currentVersion + 1;
    }

    private function versionEntry(int $version, bool $withBefore): array
    {
        $rows = $this->database->fetchAll(
            'SELECT version, schema_version, config_json, config_sha256, previous_version, change_type, '
            . 'source_version, actor_ref, reason, created_at_utc '
            . 'FROM mgw_economy_config_versions WHERE version = :version',
            ['version' => $version]
        );
        if (count($rows) !== 1 || !is_array($rows[0] ?? null)) {
            throw new InvalidArgumentException('Economy config version does not exist.');
        }
        $entry = $this->rowToEntry($rows[0]);
        if ($withBefore) {
            $previous = $entry['previous_version'];
            $entry['before'] = $previous === null ? null : $this->versionEntry($previous, false)['config'];
            $entry['after'] = $entry['config'];
        }
        return $entry;
    }

    private function rowToEntry(array $row): array
    {
        $json = (string)($row['config_json'] ?? '');
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Stored economy config JSON is invalid.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored economy config must be an object.');
        }
        $config = EconomyConfigDefinition::normalize($decoded);
        $sha = hash('sha256', EconomyConfigDefinition::canonicalJson($config));
        $storedSha = strtolower((string)($row['config_sha256'] ?? ''));
        if ($storedSha === '' || !hash_equals($storedSha, $sha)) {
            throw new RuntimeException('Stored economy config checksum mismatch.');
        }

        $previous = $row['previous_version'] ?? null;
        $source = $row['source_version'] ?? null;
        return [
            'version' => (int)($row['version'] ?? 0),
            'schema_version' => (int)($row['schema_version'] ?? 0),
            'config_sha256' => $storedSha,
            'previous_version' => $previous === null ? null : (int)$previous,
            'change_type' => (string)($row['change_type'] ?? ''),
            'source_version' => $source === null ? null : (int)$source,
            'actor_ref' => (string)($row['actor_ref'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
            'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
            'config' => $config,
        ];
    }

    private function insertVersion(
        int $version,
        int $previousVersion,
        string $changeType,
        ?int $sourceVersion,
        string $actorRef,
        string $reason,
        string $json,
        string $sha
    ): void {
        $this->database->execute(
            'INSERT INTO mgw_economy_config_versions '
            . '(version, schema_version, config_json, config_sha256, previous_version, change_type, source_version, actor_ref, reason, created_at_utc) '
            . 'VALUES (:version, :schema_version, :config_json, :config_sha256, :previous_version, :change_type, :source_version, :actor_ref, :reason, :created_at_utc)',
            [
                'version' => $version,
                'schema_version' => EconomyConfigDefinition::SCHEMA_VERSION,
                'config_json' => $json,
                'config_sha256' => $sha,
                'previous_version' => $previousVersion,
                'change_type' => $changeType,
                'source_version' => $sourceVersion,
                'actor_ref' => $actorRef,
                'reason' => $reason,
                'created_at_utc' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    private function moveCurrentVersion(int $previousVersion, int $newVersion): void
    {
        $affected = $this->database->execute(
            'UPDATE mgw_economy_config_state SET current_version = :new_version, updated_at_utc = :updated_at_utc '
            . 'WHERE singleton_id = 1 AND current_version = :previous_version',
            [
                'new_version' => $newVersion,
                'updated_at_utc' => gmdate('Y-m-d H:i:s'),
                'previous_version' => $previousVersion,
            ]
        );
        if ($affected !== 1) {
            throw new RuntimeException('Economy config changed concurrently. Retry from a fresh snapshot.');
        }
    }

    private function actorRef(string $actorRef): string
    {
        $actorRef = trim($actorRef);
        if ($actorRef === '' || strlen($actorRef) > 191 || preg_match('/[\x00-\x1F\x7F]/', $actorRef)) {
            throw new InvalidArgumentException('Economy config actor reference is invalid.');
        }
        return $actorRef;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        $length = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($length < 3 || $length > 500) {
            throw new InvalidArgumentException('Economy config change reason must contain 3 to 500 characters.');
        }
        return $reason;
    }
}
