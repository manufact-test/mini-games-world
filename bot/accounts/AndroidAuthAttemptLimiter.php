<?php
declare(strict_types=1);

final class AndroidAuthRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSec)
    {
        parent::__construct('Android authentication rate limit exceeded.');
    }
}

final class AndroidAuthAttemptLimiter
{
    private const WINDOW_SECONDS = 900;
    private const NETWORK_MAX = 240;
    private const SUBJECT_MAX = 60;
    private const RETENTION_SECONDS = 172800;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private array $config
    ) {}

    public function assertAllowed(string $remoteAddress, string $providerSubject): void
    {
        $secret = trim((string)($this->config['bot_token'] ?? ''));
        if ($secret === '') {
            throw new RuntimeException('Android auth rate-limit secret is unavailable.');
        }

        $remoteAddress = trim($remoteAddress);
        if ($remoteAddress === '') $remoteAddress = 'unknown';

        $networkActor = hash_hmac('sha256', 'android-auth-network|' . $remoteAddress, $secret);
        $subjectActor = hash_hmac('sha256', 'android-auth-subject|' . $providerSubject, $secret);
        $window = $this->boundedInt('window_seconds', self::WINDOW_SECONDS, 60, 86400);
        $networkMax = $this->boundedInt('network_max_requests', self::NETWORK_MAX, 1, 2000);
        $subjectMax = $this->boundedInt('subject_max_requests', self::SUBJECT_MAX, 1, 1000);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowText = $now->format('Y-m-d H:i:s.u');
        $cutoff = $now->modify('-' . $window . ' seconds')->format('Y-m-d H:i:s.u');
        $retention = $now->modify('-' . self::RETENTION_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');

        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $networkActor,
            $subjectActor,
            $window,
            $networkMax,
            $subjectMax,
            $now,
            $nowText,
            $cutoff,
            $retention
        ): void {
            $database->execute(
                'DELETE FROM mgw_android_auth_attempts WHERE created_at_utc < :retention',
                ['retention'=>$retention]
            );

            foreach ([
                ['scope'=>'network', 'actor'=>$networkActor, 'max'=>$networkMax],
                ['scope'=>'subject', 'actor'=>$subjectActor, 'max'=>$subjectMax],
            ] as $policy) {
                $rows = $database->fetchAll(
                    'SELECT COUNT(*) AS total, MIN(created_at_utc) AS oldest_created_at
                     FROM mgw_android_auth_attempts
                     WHERE scope_code=:scope_code
                       AND actor_hash=:actor_hash
                       AND created_at_utc>=:cutoff',
                    [
                        'scope_code'=>$policy['scope'],
                        'actor_hash'=>$policy['actor'],
                        'cutoff'=>$cutoff,
                    ]
                );
                $row = is_array($rows[0] ?? null) ? $rows[0] : [];
                $total = max(0, (int)($row['total'] ?? 0));
                if ($total >= (int)$policy['max']) {
                    $retryAfter = $window;
                    $oldest = trim((string)($row['oldest_created_at'] ?? ''));
                    if ($oldest !== '') {
                        $oldestTs = strtotime($oldest . ' UTC');
                        if ($oldestTs !== false) {
                            $retryAfter = max(1, ($oldestTs + $window) - $now->getTimestamp());
                        }
                    }
                    throw new AndroidAuthRateLimitException($retryAfter);
                }
            }

            foreach ([
                ['scope'=>'network', 'actor'=>$networkActor],
                ['scope'=>'subject', 'actor'=>$subjectActor],
            ] as $entry) {
                $database->execute(
                    'INSERT INTO mgw_android_auth_attempts (scope_code, actor_hash, created_at_utc)
                     VALUES (:scope_code, :actor_hash, :created_at_utc)',
                    [
                        'scope_code'=>$entry['scope'],
                        'actor_hash'=>$entry['actor'],
                        'created_at_utc'=>$nowText,
                    ]
                );
            }
        });
    }

    private function boundedInt(string $key, int $default, int $minimum, int $maximum): int
    {
        $settings = is_array($this->config['android_auth_rate_limits'] ?? null)
            ? $this->config['android_auth_rate_limits']
            : [];
        return max($minimum, min($maximum, (int)($settings[$key] ?? $default)));
    }
}
