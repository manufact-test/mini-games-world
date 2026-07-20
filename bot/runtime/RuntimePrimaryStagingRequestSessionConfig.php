<?php
declare(strict_types=1);

final class RuntimePrimaryStagingRequestSessionConfig
{
    public const CONTRACT_VERSION = 'v1-api-only-bounded-request-session';
    private const MAX_SESSION_SECONDS = 1800;

    private function __construct(
        private bool $enabled,
        private string $contractVersion,
        private int $baselineRevision,
        private int $maxRevisionDelta,
        private int $maxWorkerTicks,
        private int $leaseSeconds,
        private string $expiresAtUtc
    ) {}

    public static function fromApplicationConfig(array $config): self
    {
        if (array_key_exists('staging_db_primary_request_session', $config)
            && !is_array($config['staging_db_primary_request_session'])) {
            throw new RuntimeException('staging_db_primary_request_session must be a configuration array.');
        }
        $settings = is_array($config['staging_db_primary_request_session'] ?? null)
            ? $config['staging_db_primary_request_session']
            : [];
        $enabled = self::strictBool(
            $settings['enabled'] ?? false,
            'staging_db_primary_request_session.enabled'
        );
        $contractVersion = trim((string)($settings['contract_version'] ?? ''));
        $allowedEntrypoints = $settings['allowed_entrypoints'] ?? [];
        if (!is_array($allowedEntrypoints) || !array_is_list($allowedEntrypoints)) {
            throw new RuntimeException('staging_db_primary_request_session.allowed_entrypoints must be a list.');
        }
        $allowedEntrypoints = array_values(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            $allowedEntrypoints
        ));
        if (count($allowedEntrypoints) !== count(array_unique($allowedEntrypoints))) {
            throw new RuntimeException('staging_db_primary_request_session.allowed_entrypoints contains duplicates.');
        }
        foreach ($allowedEntrypoints as $entrypoint) {
            if ($entrypoint !== 'api') {
                throw new RuntimeException('The first staging DB-primary request session supports only API.');
            }
        }

        $baselineRevision = (int)($settings['baseline_revision'] ?? 0);
        $maxRevisionDelta = (int)($settings['max_revision_delta'] ?? 0);
        $maxWorkerTicks = (int)($settings['max_worker_ticks'] ?? 0);
        $leaseSeconds = (int)($settings['lease_seconds'] ?? 0);
        $expiresAtUtc = trim((string)($settings['expires_at_utc'] ?? ''));

        if ($enabled) {
            if ($contractVersion !== self::CONTRACT_VERSION) {
                throw new RuntimeException('Staging DB-primary request session contract version is invalid.');
            }
            if ($allowedEntrypoints !== ['api']) {
                throw new RuntimeException('Enabled staging DB-primary request session must allow exactly API.');
            }
            if ($baselineRevision < 1) {
                throw new RuntimeException('Staging DB-primary request session baseline revision must be positive.');
            }
            if ($maxRevisionDelta < 1 || $maxRevisionDelta > 20) {
                throw new RuntimeException('Staging DB-primary request session max revision delta must be between 1 and 20.');
            }
            if ($maxWorkerTicks < 1 || $maxWorkerTicks > 20 || $maxWorkerTicks < $maxRevisionDelta) {
                throw new RuntimeException('Staging DB-primary request session max worker ticks must cover the revision delta and be at most 20.');
            }
            if ($leaseSeconds < 30 || $leaseSeconds > 300) {
                throw new RuntimeException('Staging DB-primary request session lease must be between 30 and 300 seconds.');
            }
            self::assertTimestamp($expiresAtUtc, 'staging_db_primary_request_session.expires_at_utc');
        }

        return new self(
            $enabled,
            $contractVersion,
            $baselineRevision,
            $maxRevisionDelta,
            $maxWorkerTicks,
            $leaseSeconds,
            $expiresAtUtc
        );
    }

    public function assertEnabledForApi(
        int $evidencedBaselineRevision,
        int $currentRevision,
        int $now
    ): void {
        if (!$this->enabled) {
            throw new RuntimeException('Staging DB-primary request session is disabled.');
        }
        if ($this->contractVersion !== self::CONTRACT_VERSION) {
            throw new RuntimeException('Staging DB-primary request session contract version is invalid.');
        }
        if ($evidencedBaselineRevision < 1
            || $this->baselineRevision !== $evidencedBaselineRevision) {
            throw new RuntimeException('Staging DB-primary request session baseline does not match evidence.');
        }
        if ($currentRevision < $this->baselineRevision) {
            throw new RuntimeException('Current DB-primary revision is behind the request session baseline.');
        }
        if ($currentRevision > $this->maximumRevision()) {
            throw new RuntimeException('Current DB-primary revision exceeds the bounded request session.');
        }

        $expiresAt = strtotime($this->expiresAtUtc);
        if ($expiresAt === false || $expiresAt <= $now) {
            throw new RuntimeException('Staging DB-primary request session has expired.');
        }
        if ($expiresAt - $now > self::MAX_SESSION_SECONDS) {
            throw new RuntimeException('Staging DB-primary request session expiry is more than 30 minutes away.');
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function baselineRevision(): int
    {
        return $this->baselineRevision;
    }

    public function maximumRevision(): int
    {
        return $this->baselineRevision + $this->maxRevisionDelta;
    }

    public function remainingRevisions(int $currentRevision): int
    {
        return max(0, $this->maximumRevision() - $currentRevision);
    }

    public function maxWorkerTicks(): int
    {
        return $this->maxWorkerTicks;
    }

    public function leaseSeconds(): int
    {
        return $this->leaseSeconds;
    }

    public function expiresAtUtc(): string
    {
        return $this->expiresAtUtc;
    }

    public function safeSummary(int $currentRevision = 0): array
    {
        return [
            'enabled' => $this->enabled,
            'contract_version' => $this->contractVersion,
            'allowed_entrypoints' => $this->enabled ? ['api'] : [],
            'baseline_revision' => $this->baselineRevision,
            'maximum_revision' => $this->enabled ? $this->maximumRevision() : 0,
            'current_revision' => max(0, $currentRevision),
            'remaining_revisions' => $this->enabled
                ? $this->remainingRevisions(max(0, $currentRevision))
                : 0,
            'max_worker_ticks' => $this->maxWorkerTicks,
            'lease_seconds' => $this->leaseSeconds,
            'expires_at_utc' => $this->expiresAtUtc,
            'api_only' => true,
            'webhook_allowed' => false,
            'production_allowed' => false,
        ];
    }

    private static function strictBool(mixed $value, string $label): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) {
            if ($value === 0) return false;
            if ($value === 1) return true;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on', 'enabled' => true,
                '0', 'false', 'no', 'off', 'disabled' => false,
                default => throw new RuntimeException($label . ' must be a strict boolean value.'),
            };
        }
        throw new RuntimeException($label . ' must be a strict boolean value.');
    }

    private static function assertTimestamp(string $value, string $label): void
    {
        if ($value === ''
            || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1
            || strtotime($value) === false) {
            throw new RuntimeException($label . ' must be an ISO-8601 timestamp with an explicit UTC offset.');
        }
    }
}
