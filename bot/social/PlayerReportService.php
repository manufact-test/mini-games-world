<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/accounts/MgwIdGenerator.php';

final class PlayerReportException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class PlayerReportService
{
    public const REASONS = [
        'nickname' => 'Недопустимый никнейм',
        'avatar' => 'Недопустимый аватар',
        'spam' => 'Спам',
        'cheating' => 'Нечестная игра',
        'stalling' => 'Затягивание игры',
        'other' => 'Другое',
    ];

    private const LEGACY_REASON_LABELS = [
        'abuse' => 'Оскорбления или травля',
        'offensive_profile' => 'Недопустимый профиль',
    ];

    public const STATUSES = ['open', 'reviewing', 'closed'];

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function submit(
        string $reporterMgwId,
        string $targetMgwId,
        string $reason,
        string $details = '',
        string $relatedMatchId = ''
    ): array {
        $reporterMgwId = $this->requireActiveUser($reporterMgwId);
        $targetMgwId = $this->requireActiveUser($targetMgwId);
        if ($reporterMgwId === $targetMgwId) {
            throw new PlayerReportException('self_report', 'Нельзя отправить жалобу на свой профиль.');
        }

        $reason = strtolower(trim($reason));
        if (!isset(self::REASONS[$reason])) {
            throw new PlayerReportException('invalid_reason', 'Выберите причину жалобы.');
        }
        $details = $this->boundedText($details, 800);
        $relatedMatchId = trim($relatedMatchId);
        if ($relatedMatchId !== '') {
            if (strlen($relatedMatchId) > 96 || !$this->matchContainsBothPlayers($relatedMatchId, $reporterMgwId, $targetMgwId)) {
                throw new PlayerReportException('invalid_match', 'Связанный матч недоступен для этой жалобы.');
            }
        }

        $reportId = 'RPT-' . strtoupper(bin2hex(random_bytes(10)));
        $now = $this->timestamp();
        $this->database->execute(
            'INSERT INTO mgw_player_reports (
                report_id, reporter_mgw_id, target_mgw_id, reason, details, related_match_id,
                status, created_at_utc, updated_at_utc, reviewed_at_utc, resolved_at_utc, last_admin_ref
             ) VALUES (
                :report_id, :reporter_mgw_id, :target_mgw_id, :reason, :details, :related_match_id,
                :status, :created_at, :updated_at, NULL, NULL, NULL
             )',
            [
                'report_id' => $reportId,
                'reporter_mgw_id' => $reporterMgwId,
                'target_mgw_id' => $targetMgwId,
                'reason' => $reason,
                'details' => $details !== '' ? $details : null,
                'related_match_id' => $relatedMatchId !== '' ? $relatedMatchId : null,
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return [
            'report_id' => $reportId,
            'status' => 'open',
            'reason' => $reason,
            'reason_label' => self::REASONS[$reason],
            'created_at' => $now,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function reporterHistory(string $reporterMgwId, int $limit = 12): array
    {
        $reporterMgwId = $this->requireActiveUser($reporterMgwId);
        $limit = max(1, min(30, $limit));
        $rows = $this->database->fetchAll(
            'SELECT r.report_id, r.target_mgw_id, r.reason, r.details, r.status,
                    r.created_at_utc, r.reviewed_at_utc, r.resolved_at_utc,
                    target.nickname AS target_nickname
             FROM mgw_player_reports r
             INNER JOIN mgw_users target ON target.mgw_id = r.target_mgw_id
             WHERE r.reporter_mgw_id = :reporter_mgw_id
             ORDER BY r.created_at_utc DESC
             LIMIT ' . $limit,
            ['reporter_mgw_id' => $reporterMgwId]
        );

        return array_map(function (array $row): array {
            $reason = (string)($row['reason'] ?? 'other');
            return [
                'report_id' => (string)($row['report_id'] ?? ''),
                'target_public_mgw_id' => MgwIdGenerator::toPublic((string)($row['target_mgw_id'] ?? '')),
                'target_nickname' => (string)($row['target_nickname'] ?? 'Игрок'),
                'reason' => $reason,
                'reason_label' => self::REASONS[$reason] ?? self::LEGACY_REASON_LABELS[$reason] ?? $reason,
                'details' => (string)($row['details'] ?? ''),
                'status' => (string)($row['status'] ?? 'open'),
                'created_at' => (string)($row['created_at_utc'] ?? ''),
                'reviewed_at' => (string)($row['reviewed_at_utc'] ?? ''),
                'resolved_at' => (string)($row['resolved_at_utc'] ?? ''),
            ];
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    public function queue(int $limit = 100, array $filters = []): array
    {
        $limit = max(1, min(200, $limit));
        [$whereSql, $parameters] = $this->queueFilterSql($filters);

        $rows = $this->database->fetchAll(
            $this->queueSelectSql()
            . $whereSql
            . " ORDER BY CASE r.status WHEN 'open' THEN 0 WHEN 'reviewing' THEN 1 ELSE 2 END,
                      r.created_at_utc DESC
             LIMIT " . $limit,
            $parameters
        );

        return array_map(fn(array $row): array => $this->queueRow($row), $rows);
    }

    public function queuePage(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $perPage = max(5, min(50, $perPage));
        $page = max(1, min(100000, $page));
        [$whereSql, $parameters] = $this->queueFilterSql($filters);

        $total = max(0, (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_player_reports r
             INNER JOIN mgw_users reporter ON reporter.mgw_id = r.reporter_mgw_id
             INNER JOIN mgw_users target ON target.mgw_id = r.target_mgw_id'
            . $whereSql,
            $parameters
        ));
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            $this->queueSelectSql()
            . $whereSql
            . " ORDER BY CASE r.status WHEN 'open' THEN 0 WHEN 'reviewing' THEN 1 ELSE 2 END,
                      r.created_at_utc DESC
             LIMIT " . $perPage . ' OFFSET ' . $offset,
            $parameters
        );

        return [
            'reports' => array_map(fn(array $row): array => $this->queueRow($row), $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : min($total, $offset + $perPage),
            ],
        ];
    }

    private function queueSelectSql(): string
    {
        return 'SELECT r.report_id, r.reporter_mgw_id, r.target_mgw_id, r.reason, r.details,
                       r.related_match_id, r.status, r.created_at_utc, r.updated_at_utc,
                       r.reviewed_at_utc, r.resolved_at_utc, r.last_admin_ref,
                       reporter.nickname AS reporter_nickname, target.nickname AS target_nickname
                FROM mgw_player_reports r
                INNER JOIN mgw_users reporter ON reporter.mgw_id = r.reporter_mgw_id
                INNER JOIN mgw_users target ON target.mgw_id = r.target_mgw_id';
    }

    private function queueFilterSql(array $filters): array
    {
        $mode = strtolower(trim((string)($filters['mode'] ?? 'active')));
        $query = trim((string)($filters['query'] ?? ''));
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));

        $where = [];
        $parameters = [];
        if ($mode === 'closed') {
            $where[] = 'r.status = :queue_status';
            $parameters['queue_status'] = 'closed';
        } elseif ($mode !== 'all') {
            $where[] = "r.status IN ('open','reviewing')";
        }
        if ($query !== '') {
            $where[] = '(LOWER(r.report_id) LIKE :queue_query
                OR LOWER(reporter.nickname) LIKE :queue_query
                OR LOWER(target.nickname) LIKE :queue_query
                OR LOWER(r.reason) LIKE :queue_query
                OR LOWER(COALESCE(r.details, \'\')) LIKE :queue_query)';
            $parameters['queue_query'] = '%' . strtolower($this->boundedText($query, 120)) . '%';
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateFrom) === 1) {
            $where[] = 'r.created_at_utc >= :queue_date_from';
            $parameters['queue_date_from'] = $dateFrom . ' 00:00:00';
        }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateTo) === 1) {
            $where[] = 'r.created_at_utc < :queue_date_to';
            $parameters['queue_date_to'] = (new DateTimeImmutable($dateTo, new DateTimeZone('UTC')))
                ->modify('+1 day')->format('Y-m-d 00:00:00');
        }

        return [$where !== [] ? ' WHERE ' . implode(' AND ', $where) : '', $parameters];
    }

    private function queueRow(array $row): array
    {
        $reason = (string)($row['reason'] ?? 'other');
        return [
            'report_id' => (string)($row['report_id'] ?? ''),
            'reporter_mgw_id' => (string)($row['reporter_mgw_id'] ?? ''),
            'reporter_public_mgw_id' => MgwIdGenerator::toPublic((string)$row['reporter_mgw_id']),
            'reporter_nickname' => (string)($row['reporter_nickname'] ?? 'Игрок'),
            'target_mgw_id' => (string)($row['target_mgw_id'] ?? ''),
            'target_public_mgw_id' => MgwIdGenerator::toPublic((string)$row['target_mgw_id']),
            'target_nickname' => (string)($row['target_nickname'] ?? 'Игрок'),
            'reason' => $reason,
            'reason_label' => self::REASONS[$reason] ?? self::LEGACY_REASON_LABELS[$reason] ?? $reason,
            'details' => (string)($row['details'] ?? ''),
            'related_match_id' => (string)($row['related_match_id'] ?? ''),
            'status' => (string)($row['status'] ?? 'open'),
            'created_at' => (string)($row['created_at_utc'] ?? ''),
            'updated_at' => (string)($row['updated_at_utc'] ?? ''),
            'reviewed_at' => (string)($row['reviewed_at_utc'] ?? ''),
            'resolved_at' => (string)($row['resolved_at_utc'] ?? ''),
            'last_admin_ref' => (string)($row['last_admin_ref'] ?? ''),
        ];
    }
    public function setStatus(string $reportId, string $status, string $adminRef): array
    {
        $reportId = trim($reportId);
        $status = strtolower(trim($status));
        $adminRef = $this->boundedText($adminRef, 191);
        if ($reportId === '' || strlen($reportId) > 40 || !in_array($status, self::STATUSES, true)) {
            throw new PlayerReportException('invalid_status', 'Некорректный статус жалобы.');
        }

        $rows = $this->database->fetchAll(
            'SELECT status FROM mgw_player_reports WHERE report_id = :report_id',
            ['report_id' => $reportId]
        );
        if ($rows === []) throw new PlayerReportException('report_not_found', 'Жалоба не найдена.');

        $now = $this->timestamp();
        $parameters = [
            'status' => $status,
            'updated_at' => $now,
            'last_admin_ref' => $adminRef !== '' ? $adminRef : null,
            'report_id' => $reportId,
        ];

        if ($status === 'closed') {
            $this->database->execute(
                'UPDATE mgw_player_reports
                 SET status = :status,
                     updated_at_utc = :updated_at,
                     reviewed_at_utc = COALESCE(reviewed_at_utc, :reviewed_at),
                     resolved_at_utc = :resolved_at,
                     last_admin_ref = :last_admin_ref
                 WHERE report_id = :report_id',
                $parameters + ['reviewed_at' => $now, 'resolved_at' => $now]
            );
        } elseif ($status === 'reviewing') {
            $this->database->execute(
                'UPDATE mgw_player_reports
                 SET status = :status,
                     updated_at_utc = :updated_at,
                     reviewed_at_utc = COALESCE(reviewed_at_utc, :reviewed_at),
                     resolved_at_utc = NULL,
                     last_admin_ref = :last_admin_ref
                 WHERE report_id = :report_id',
                $parameters + ['reviewed_at' => $now]
            );
        } else {
            $this->database->execute(
                'UPDATE mgw_player_reports
                 SET status = :status,
                     updated_at_utc = :updated_at,
                     resolved_at_utc = NULL,
                     last_admin_ref = :last_admin_ref
                 WHERE report_id = :report_id',
                $parameters
            );
        }

        foreach ($this->queue(200, ['mode' => 'all']) as $report) {
            if ((string)$report['report_id'] === $reportId) return $report;
        }
        throw new PlayerReportException('report_not_found', 'Жалоба не найдена.');
    }

    private function requireActiveUser(string $mgwId): string
    {
        $mgwId = strtoupper(trim($mgwId));
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new PlayerReportException('user_unavailable', 'Игрок MGW не найден.');
        }
        $rows = $this->database->fetchAll(
            'SELECT mgw_id FROM mgw_users WHERE mgw_id = :mgw_id AND status = :status',
            ['mgw_id' => $mgwId, 'status' => 'active']
        );
        if ($rows === []) throw new PlayerReportException('user_unavailable', 'Игрок MGW не найден.');
        return $mgwId;
    }

    private function matchContainsBothPlayers(string $matchId, string $reporterMgwId, string $targetMgwId): bool
    {
        $count = (int)$this->database->fetchValue(
            'SELECT COUNT(DISTINCT mgw_id)
             FROM mgw_match_players
             WHERE match_id = :match_id AND mgw_id IN (:reporter_mgw_id, :target_mgw_id)',
            [
                'match_id' => $matchId,
                'reporter_mgw_id' => $reporterMgwId,
                'target_mgw_id' => $targetMgwId,
            ]
        );
        return $count === 2;
    }

    private function boundedText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
