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
        'abuse' => 'Оскорбления или травля',
        'cheating' => 'Нечестная игра',
        'spam' => 'Спам или навязчивые сообщения',
        'offensive_profile' => 'Недопустимый профиль',
        'other' => 'Другое',
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
    public function queue(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->database->fetchAll(
            'SELECT r.report_id, r.reporter_mgw_id, r.target_mgw_id, r.reason, r.details,
                    r.related_match_id, r.status, r.created_at_utc, r.updated_at_utc,
                    r.reviewed_at_utc, r.resolved_at_utc, r.last_admin_ref,
                    reporter.nickname AS reporter_nickname, target.nickname AS target_nickname
             FROM mgw_player_reports r
             INNER JOIN mgw_users reporter ON reporter.mgw_id = r.reporter_mgw_id
             INNER JOIN mgw_users target ON target.mgw_id = r.target_mgw_id
             ORDER BY CASE r.status WHEN \'open\' THEN 0 WHEN \'reviewing\' THEN 1 ELSE 2 END,
                      r.created_at_utc DESC
             LIMIT ' . $limit
        );

        return array_map(function (array $row): array {
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
                'reason_label' => self::REASONS[$reason] ?? $reason,
                'details' => (string)($row['details'] ?? ''),
                'related_match_id' => (string)($row['related_match_id'] ?? ''),
                'status' => (string)($row['status'] ?? 'open'),
                'created_at' => (string)($row['created_at_utc'] ?? ''),
                'updated_at' => (string)($row['updated_at_utc'] ?? ''),
                'reviewed_at' => (string)($row['reviewed_at_utc'] ?? ''),
                'resolved_at' => (string)($row['resolved_at_utc'] ?? ''),
                'last_admin_ref' => (string)($row['last_admin_ref'] ?? ''),
            ];
        }, $rows);
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

        foreach ($this->queue(200) as $report) {
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
