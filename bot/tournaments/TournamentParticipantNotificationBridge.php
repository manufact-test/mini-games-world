<?php
declare(strict_types=1);

require_once __DIR__ . '/../notifications/AdminNotificationEventService.php';

final class TournamentParticipantNotificationBridge
{
    public function __construct(
        private ?AdminNotificationEventService $events = null
    ) {
        $this->events ??= new AdminNotificationEventService();
    }

    public function ensureScheduleNotifications(
        array &$data,
        array $snapshot,
        array $participantMgwIds,
        ?DateTimeImmutable $now = null
    ): array {
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)
            || (string)($tournament['state'] ?? '') !== TournamentRegistrationService::STATE_SCHEDULED) {
            return [
                'created_or_existing'=>[],
                'skipped_past'=>[],
            ];
        }

        $tournamentId = trim((string)($tournament['tournament_id'] ?? ''));
        $startRaw = trim((string)($tournament['scheduled_start_at_utc'] ?? ''));
        $assignedRaw = trim((string)($tournament['scheduled_at_utc'] ?? ''));
        if ($tournamentId === '' || $startRaw === '' || $assignedRaw === '') {
            throw new RuntimeException('Scheduled tournament is missing immutable schedule identity.');
        }

        $participants = [];
        foreach ($participantMgwIds as $mgwId) {
            $mgwId = trim((string)$mgwId);
            if ($mgwId !== '') $participants[$mgwId] = $mgwId;
        }
        $participants = array_values($participants);
        sort($participants, SORT_STRING);
        if ($participants === []) {
            throw new RuntimeException('Scheduled tournament has no participant notification audience.');
        }

        $utc = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $start = (new DateTimeImmutable($startRaw, $utc))->setTimezone($utc);
        $assignedAt = (new DateTimeImmutable($assignedRaw, $utc))->setTimezone($utc);
        if ($start <= $now) {
            throw new RuntimeException('Scheduled tournament start time is already in the past.');
        }

        $title = trim((string)($tournament['title'] ?? 'Официальный турнир'));
        if ($title === '') $title = 'Официальный турнир';
        $startLabel = $start->format('d.m.Y H:i') . ' UTC';
        $audienceRef = 'official-tournament:' . $tournamentId;
        $base = 'official-tournament.' . $tournamentId . '.schedule.';
        $events = [];

        $events[] = $this->events->createEvent(
            $data,
            [
                'source_type'=>'system',
                'audience_type'=>'tournament',
                'audience_ref'=>$audienceRef,
                'recipient_mgw_ids'=>$participants,
                'title'=>'Дата турнира назначена',
                'text'=>"«{$title}» начнётся {$startLabel}. В разделе турниров уже доступен точный обратный отсчёт.",
                'scheduled_at'=>$assignedAt->format(DATE_ATOM),
                'request_id'=>$base . 'assigned',
            ],
            'system:tournament',
            $now
        );

        $plans = [
            [
                'key'=>'24h',
                'at'=>$start->sub(new DateInterval('P1D')),
                'title'=>'Турнир начнётся через день',
                'text'=>"«{$title}» начнётся через 24 часа. Проверьте дату и обратный отсчёт в разделе турниров.",
            ],
            [
                'key'=>'1h',
                'at'=>$start->sub(new DateInterval('PT1H')),
                'title'=>'Турнир начнётся через час',
                'text'=>"«{$title}» начнётся через 1 час. Подготовьтесь к участию.",
            ],
            [
                'key'=>'15m',
                'at'=>$start->sub(new DateInterval('PT15M')),
                'title'=>'Турнир начнётся через 15 минут',
                'text'=>"«{$title}» начнётся через 15 минут.",
            ],
        ];

        $skipped = [];
        foreach ($plans as $plan) {
            if ($plan['at'] <= $now) {
                $skipped[] = $plan['key'];
                continue;
            }
            $events[] = $this->events->createEvent(
                $data,
                [
                    'source_type'=>'system',
                    'audience_type'=>'tournament',
                    'audience_ref'=>$audienceRef,
                    'recipient_mgw_ids'=>$participants,
                    'title'=>$plan['title'],
                    'text'=>$plan['text'],
                    'scheduled_at'=>$plan['at']->format(DATE_ATOM),
                    'request_id'=>$base . 'reminder-' . $plan['key'],
                ],
                'system:tournament',
                $now
            );
        }

        return [
            'created_or_existing'=>$events,
            'skipped_past'=>$skipped,
            'recipient_count'=>count($participants),
            'start_at_utc'=>$start->format(DATE_ATOM),
        ];
    }
}
