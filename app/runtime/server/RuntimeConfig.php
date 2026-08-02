<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

final readonly class RuntimeConfig
{
    public function __construct(
        public string $environment,
        public string $dataDirectory,
        public string $build,
        public string $botToken = '',
        public int $telegramInitDataMaxAgeSec = 86400,
        public int $telegramInitDataClockSkewSec = 300,
        public int $sessionTimeoutSec = 180,
        public int $presenceTtlSec = 75,
        public bool $allowBrowserStagingIdentity = false,
        public int $matchBet = 10,
        public int $initialMatchBalance = 100,
        public int $queueTimeoutSec = 120,
        public int $moveTimeoutSec = 60,
        public float $commissionRate = 0.10,
        public array $allowedHosts = ['localhost', '127.0.0.1', '::1'],
    ) {
        if ($this->environment !== 'staging') {
            throw new \InvalidArgumentException('The clean runtime server is staging-only.');
        }
        if (trim($this->dataDirectory) === '') {
            throw new \InvalidArgumentException('A staging data directory is required.');
        }
        if (trim($this->build) === '') {
            throw new \InvalidArgumentException('A clean runtime build identifier is required.');
        }
        if (!$this->allowedHosts) {
            throw new \InvalidArgumentException('Clean runtime allowed hosts are required.');
        }
        foreach ($this->allowedHosts as $host) {
            if (!is_string($host) || trim($host) === '' || str_contains($host, '*') || str_contains($host, '/')) {
                throw new \InvalidArgumentException('Clean runtime allowed host is invalid.');
            }
        }
        if ($this->telegramInitDataMaxAgeSec < 60) {
            throw new \InvalidArgumentException('Telegram initData max age must be at least 60 seconds.');
        }
        if ($this->telegramInitDataClockSkewSec < 0) {
            throw new \InvalidArgumentException('Telegram initData clock skew cannot be negative.');
        }
        if ($this->sessionTimeoutSec < 30) {
            throw new \InvalidArgumentException('Clean session timeout must be at least 30 seconds.');
        }
        if ($this->presenceTtlSec < 30 || $this->presenceTtlSec >= $this->sessionTimeoutSec) {
            throw new \InvalidArgumentException('Clean presence TTL must be shorter than the session timeout.');
        }
        if ($this->matchBet <= 0 || $this->initialMatchBalance < $this->matchBet) {
            throw new \InvalidArgumentException('Clean match economy configuration is invalid.');
        }
        if ($this->queueTimeoutSec < 30 || $this->moveTimeoutSec < 15) {
            throw new \InvalidArgumentException('Clean match timeouts are invalid.');
        }
        if ($this->commissionRate < 0 || $this->commissionRate >= 1) {
            throw new \InvalidArgumentException('Clean match commission rate is invalid.');
        }
    }

    public static function fromEnvironment(): self
    {
        $environment = strtolower(trim((string)(getenv('MGW_CLEAN_RUNTIME_ENV') ?: getenv('MGW_ENV') ?: '')));
        if ($environment !== 'staging') {
            throw new \RuntimeException('Clean runtime requires explicit staging environment configuration.');
        }

        $allowedHosts = self::allowedHostsFromEnvironment();
        if (!$allowedHosts) {
            throw new \RuntimeException('Clean runtime requires an explicit host allowlist.');
        }

        $configured = trim((string)(getenv('MGW_CLEAN_RUNTIME_DATA_DIR') ?: ''));
        $dataDirectory = $configured !== ''
            ? $configured
            : dirname(__DIR__, 4) . '/_private_mgw/runtime_staging';
        $botToken = trim((string)(getenv('MGW_CLEAN_RUNTIME_BOT_TOKEN') ?: getenv('MGW_BOT_TOKEN') ?: ''));

        // Historical accepted clean build marker retained for rollback evidence:
        // build: 'mgw-clean-server-v4-action-priority'
        return new self(
            environment: 'staging',
            dataDirectory: rtrim($dataDirectory, '/\\'),
            build: 'mgw-clean-server-v5-fail-closed',
            botToken: $botToken,
            telegramInitDataMaxAgeSec: max(60, (int)(getenv('MGW_CLEAN_TELEGRAM_MAX_AGE_SEC') ?: 86400)),
            telegramInitDataClockSkewSec: max(0, (int)(getenv('MGW_CLEAN_TELEGRAM_CLOCK_SKEW_SEC') ?: 300)),
            sessionTimeoutSec: max(30, (int)(getenv('MGW_CLEAN_SESSION_TIMEOUT_SEC') ?: 180)),
            presenceTtlSec: max(30, (int)(getenv('MGW_CLEAN_PRESENCE_TTL_SEC') ?: 75)),
            allowBrowserStagingIdentity: self::boolEnvironment('MGW_CLEAN_ALLOW_BROWSER_IDENTITY', false),
            matchBet: max(1, (int)(getenv('MGW_CLEAN_MATCH_BET') ?: 10)),
            initialMatchBalance: max(10, (int)(getenv('MGW_CLEAN_INITIAL_MATCH_BALANCE') ?: 100)),
            queueTimeoutSec: max(30, (int)(getenv('MGW_CLEAN_QUEUE_TIMEOUT_SEC') ?: 120)),
            moveTimeoutSec: max(15, (int)(getenv('MGW_CLEAN_MOVE_TIMEOUT_SEC') ?: 60)),
            commissionRate: min(0.50, max(0.0, (float)(getenv('MGW_CLEAN_COMMISSION_RATE') ?: 0.10))),
            allowedHosts:$allowedHosts,
        );
    }

    private static function allowedHostsFromEnvironment(): array
    {
        $raw = trim((string)(getenv('MGW_CLEAN_ALLOWED_HOSTS') ?: ''));
        if ($raw === '') return [];

        $hosts = [];
        foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $host) {
            $normalized = self::normalizeHost((string)$host);
            if ($normalized === '' || str_contains($normalized, '*') || str_contains($normalized, '/')) {
                throw new \RuntimeException('Clean runtime host allowlist contains an invalid value.');
            }
            $hosts[$normalized] = true;
        }

        return array_keys($hosts);
    }

    private static function boolEnvironment(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim((string)$value) === '') return $default;
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') return '';
        if (str_contains($host, '://')) {
            $host = (string)(parse_url($host, PHP_URL_HOST) ?: '');
        } elseif ($host[0] === '[') {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }
        return rtrim(strtolower(trim($host)), '.');
    }
}
