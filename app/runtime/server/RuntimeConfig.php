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
        public bool $allowBrowserStagingIdentity = true,
        public int $matchBet = 10,
        public int $initialMatchBalance = 100,
        public int $queueTimeoutSec = 120,
        public int $moveTimeoutSec = 60,
        public float $commissionRate = 0.10,
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
        $configured = trim((string)(getenv('MGW_CLEAN_RUNTIME_DATA_DIR') ?: ''));
        $dataDirectory = $configured !== ''
            ? $configured
            : dirname(__DIR__, 4) . '/_private_mgw/runtime_staging';
        $botToken = trim((string)(getenv('MGW_CLEAN_RUNTIME_BOT_TOKEN') ?: getenv('MGW_BOT_TOKEN') ?: ''));

        return new self(
            environment: 'staging',
            dataDirectory: rtrim($dataDirectory, '/\\'),
            build: 'mgw-clean-server-v4-action-priority',
            botToken: $botToken,
            telegramInitDataMaxAgeSec: max(60, (int)(getenv('MGW_CLEAN_TELEGRAM_MAX_AGE_SEC') ?: 86400)),
            telegramInitDataClockSkewSec: max(0, (int)(getenv('MGW_CLEAN_TELEGRAM_CLOCK_SKEW_SEC') ?: 300)),
            sessionTimeoutSec: max(30, (int)(getenv('MGW_CLEAN_SESSION_TIMEOUT_SEC') ?: 180)),
            presenceTtlSec: max(30, (int)(getenv('MGW_CLEAN_PRESENCE_TTL_SEC') ?: 75)),
            allowBrowserStagingIdentity: (string)(getenv('MGW_CLEAN_ALLOW_BROWSER_IDENTITY') ?: '1') !== '0',
            matchBet: max(1, (int)(getenv('MGW_CLEAN_MATCH_BET') ?: 10)),
            initialMatchBalance: max(10, (int)(getenv('MGW_CLEAN_INITIAL_MATCH_BALANCE') ?: 100)),
            queueTimeoutSec: max(30, (int)(getenv('MGW_CLEAN_QUEUE_TIMEOUT_SEC') ?: 120)),
            moveTimeoutSec: max(15, (int)(getenv('MGW_CLEAN_MOVE_TIMEOUT_SEC') ?: 60)),
            commissionRate: min(0.50, max(0.0, (float)(getenv('MGW_CLEAN_COMMISSION_RATE') ?: 0.10))),
        );
    }
}
