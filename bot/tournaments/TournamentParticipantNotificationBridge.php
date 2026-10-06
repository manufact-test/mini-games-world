<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

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

        $title = trim((string)($tournament['title'] ?? ServerLocalization::copy('arena.official_title', 'Official tournament')));
        if ($title === '') $title = ServerLocalization::copy('arena.official_title', 'Official tournament');
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
                'title'=>ServerLocalization::copy('server.tournament_runtime.notifications.scheduled_title', 'Tournament date scheduled'),
                'text'=>ServerLocalization::copy('server.tournament_runtime.notifications.scheduled_text', '{title}: tournament date and time are scheduled.', ['title'=>$title]),
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
                'title'=>ServerLocalization::copy('server.tournament_runtime.notifications.day_title', 'Tournament starts in one day'),
                'text'=>ServerLocalization::copy('server.tournament_runtime.notifications.day_text', '{title} starts in 24 hours.', ['title'=>$title]),
            ],
            [
                'key'=>'1h',
                'at'=>$start->sub(new DateInterval('PT1H')),
                'title'=>ServerLocalization::copy('server.tournament_runtime.notifications.hour_title', 'Tournament starts in one hour'),
                'text'=>ServerLocalization::copy('server.tournament_runtime.notifications.hour_text', '{title} starts in one hour.', ['title'=>$title]),
            ],
            [
                'key'=>'15m',
                'at'=>$start->sub(new DateInterval('PT15M')),
                'title'=>ServerLocalization::copy('server.tournament_runtime.notifications.minutes15_title', 'Tournament starts in 15 minutes'),
                'text'=>ServerLocalization::copy('server.tournament_runtime.notifications.minutes15_text', '{title} starts in 15 minutes.', ['title'=>$title]),
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
