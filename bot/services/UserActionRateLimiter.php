<?php
declare(strict_types=1);

final class UserActionRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly int $retryAfterSec
    ) {
        parent::__construct('Rate limit exceeded for ' . $action . '.');
    }
}

final class UserActionRateLimiter
{
    private const DEFAULT_POLICIES = [
        'support_create' => ['max_requests'=>5, 'window_seconds'=>900],
        'support_reply' => ['max_requests'=>12, 'window_seconds'=>600],
        'player_report' => ['max_requests'=>10, 'window_seconds'=>600],
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private array $config = []
    ) {}

    public function assertAllowed(string $action, string $actorRef): void
    {
        $action = strtolower(trim($action));
        $actorRef = trim($actorRef);
        if ($actorRef === '' || !isset(self::DEFAULT_POLICIES[$action])) {
            throw new InvalidArgumentException('Unsupported rate-limit subject or action.');
        }

        $policy = $this->policy($action);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . $policy['window_seconds'] . ' seconds')->format('Y-m-d H:i:s.u');

        [$sql, $parameters] = $this->query($action, $actorRef, $cutoff);
        $rows = $this->database->fetchAll($sql, $parameters);
        $row = is_array($rows[0] ?? null) ? $rows[0] : [];
        $total = max(0, (int)($row['total'] ?? 0));
        if ($total < $policy['max_requests']) return;

        $oldest = trim((string)($row['oldest_created_at'] ?? ''));
        $retryAfter = $policy['window_seconds'];
        if ($oldest !== '') {
            $oldestTs = strtotime($oldest . ' UTC');
            if ($oldestTs !== false) {
                $retryAfter = max(1, ($oldestTs + $policy['window_seconds']) - $now->getTimestamp());
            }
        }

        throw new UserActionRateLimitException($action, $retryAfter);
    }

    /** @return array{max_requests:int,window_seconds:int} */
    private function policy(string $action): array
    {
        $defaults = self::DEFAULT_POLICIES[$action];
        $configured = is_array($this->config['security_rate_limits'][$action] ?? null)
            ? $this->config['security_rate_limits'][$action]
            : [];

        return [
            'max_requests' => max(1, min(500, (int)($configured['max_requests'] ?? $defaults['max_requests']))),
            'window_seconds' => max(30, min(86400, (int)($configured['window_seconds'] ?? $defaults['window_seconds']))),
        ];
    }

    /** @return array{0:string,1:array<string,string>} */
    private function query(string $action, string $actorRef, string $cutoff): array
    {
        $parameters = ['actor_ref'=>$actorRef, 'cutoff'=>$cutoff];

        return match ($action) {
            'support_create' => [
                'SELECT COUNT(*) AS total, MIN(created_at_utc) AS oldest_created_at
                 FROM mgw_support_tickets
                 WHERE requester_mgw_id = :actor_ref
                   AND created_at_utc >= :cutoff',
                $parameters,
            ],
            'support_reply' => [
                "SELECT COUNT(*) AS total, MIN(created_at_utc) AS oldest_created_at
                 FROM mgw_support_ticket_messages
                 WHERE actor_type = 'user'
                   AND actor_ref = :actor_ref
                   AND created_at_utc >= :cutoff",
                $parameters,
            ],
            'player_report' => [
                'SELECT COUNT(*) AS total, MIN(created_at_utc) AS oldest_created_at
                 FROM mgw_player_reports
                 WHERE reporter_mgw_id = :actor_ref
                   AND created_at_utc >= :cutoff',
                $parameters,
            ],
            default => throw new InvalidArgumentException('Unsupported rate-limit action.'),
        };
    }
}
