<?php
declare(strict_types=1);

/**
 * Explicit, auditable conversion contract for MVP-15.3.
 *
 * This class deliberately has no default mapping. A caller must provide an
 * approved, versioned rule before any Match/Gold amount can be converted.
 */
final class UnifiedBalanceMigrationRule
{
    public const TARGET_ASSET = 'mgw_coin';
    public const MATCH_ASSET = 'match_coin';
    public const GOLD_ASSET = 'gold_coin';

    private function __construct(
        private string $version,
        private string $approvedBy,
        private string $approvedAtUtc,
        private array $rates
    ) {}

    public static function fromApprovedConfig(array $config): self
    {
        if (($config['approved'] ?? null) !== true) {
            throw new RuntimeException('Unified balance mapping is not explicitly approved.');
        }

        $target = trim((string)($config['target_asset'] ?? ''));
        if ($target !== self::TARGET_ASSET) {
            throw new RuntimeException('Unified balance mapping target must be ' . self::TARGET_ASSET . '.');
        }

        $version = trim((string)($config['version'] ?? ''));
        if ($version === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,63}$/', $version) !== 1) {
            throw new RuntimeException('Unified balance mapping version is missing or invalid.');
        }

        $approvedBy = trim((string)($config['approved_by'] ?? ''));
        if ($approvedBy === '' || strlen($approvedBy) > 128) {
            throw new RuntimeException('Unified balance mapping approval owner is missing or invalid.');
        }

        $approvedAtUtc = trim((string)($config['approved_at_utc'] ?? ''));
        if ($approvedAtUtc === '') {
            throw new RuntimeException('Unified balance mapping approval timestamp is missing.');
        }
        try {
            $approvedAt = new DateTimeImmutable($approvedAtUtc);
        } catch (Exception $error) {
            throw new RuntimeException('Unified balance mapping approval timestamp is invalid.', 0, $error);
        }
        if ($approvedAt->getOffset() !== 0) {
            throw new RuntimeException('Unified balance mapping approval timestamp must be UTC.');
        }
        $approvedAtUtc = $approvedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $rates = [];
        foreach ([self::MATCH_ASSET, self::GOLD_ASSET] as $asset) {
            $rate = $config['rates'][$asset] ?? null;
            if (!is_array($rate)) {
                throw new RuntimeException('Unified balance mapping rate is missing for ' . $asset . '.');
            }
            $numerator = self::positiveInt($rate['numerator'] ?? null, $asset . ' numerator');
            $denominator = self::positiveInt($rate['denominator'] ?? null, $asset . ' denominator');
            $rates[$asset] = [
                'numerator' => $numerator,
                'denominator' => $denominator,
            ];
        }

        return new self($version, $approvedBy, $approvedAtUtc, $rates);
    }

    public function convert(string $sourceAsset, int $amount): int
    {
        if ($amount < 0) {
            throw new RuntimeException('Unified balance migration does not accept negative source amounts.');
        }
        if (!isset($this->rates[$sourceAsset])) {
            throw new RuntimeException('Unsupported unified balance source asset: ' . $sourceAsset . '.');
        }

        $numerator = (int)$this->rates[$sourceAsset]['numerator'];
        $denominator = (int)$this->rates[$sourceAsset]['denominator'];
        if ($amount !== 0 && $amount > intdiv(PHP_INT_MAX, $numerator)) {
            throw new RuntimeException('Unified balance conversion would overflow integer range.');
        }

        $scaled = $amount * $numerator;
        if ($scaled % $denominator !== 0) {
            throw new RuntimeException(
                'Unified balance conversion would require rounding for ' . $sourceAsset . '; migration is fail-closed.'
            );
        }
        return intdiv($scaled, $denominator);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function targetAsset(): string
    {
        return self::TARGET_ASSET;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->auditDescriptor(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function auditDescriptor(): array
    {
        return [
            'version' => $this->version,
            'target_asset' => self::TARGET_ASSET,
            'rates' => $this->rates,
            'approved_by' => $this->approvedBy,
            'approved_at_utc' => $this->approvedAtUtc,
            'approved' => true,
        ];
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            if (strlen($value) > strlen((string)PHP_INT_MAX)
                || (strlen($value) === strlen((string)PHP_INT_MAX) && strcmp($value, (string)PHP_INT_MAX) > 0)) {
                throw new RuntimeException('Unified balance mapping ' . $label . ' exceeds integer range.');
            }
            $normalized = (int)$value;
        } else {
            throw new RuntimeException('Unified balance mapping ' . $label . ' must be a positive integer.');
        }

        if ($normalized < 1) {
            throw new RuntimeException('Unified balance mapping ' . $label . ' must be a positive integer.');
        }
        return $normalized;
    }
}
