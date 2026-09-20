<?php
declare(strict_types=1);

require_once __DIR__ . '/../notifications/AdminNotificationEventService.php';

/**
 * Tournament-specific producer bridge into the existing Notification Center.
 *
 * This class never stores notification rows itself. AdminNotificationEventService
 * remains the canonical producer and RuntimeNotificationRepository remains the
 * JSON -> DB mirror owner.
 */
final class TournamentAdminNotificationBridge
{
    public function __construct(
        private ?AdminNotificationEventService $events = null
    ) {
        $this->events ??= new AdminNotificationEventService();
    }

    public function emitRegistrationFull(
        array &$data,
        array $adminIds,
        array $snapshot,
        ?DateTimeImmutable $now = null
    ): ?array {
        $tournament = $snapshot['tournament'] ?? null;
        if (!is_array($tournament)
            || empty($tournament['waiting_for_date'])
            || (string)($tournament['registration_closed_reason'] ?? '') !== 'full') {
            return null;
        }

        $tournamentId = trim((string)($tournament['tournament_id'] ?? ''));
        if ($tournamentId === '' || $adminIds === []) {
            return null;
        }

        $adminLookup = [];
        foreach ($adminIds as $adminId) {
            $adminId = trim((string)$adminId);
            if ($adminId !== '') $adminLookup[$adminId] = true;
        }
        if ($adminLookup === []) return null;

        $recipientMgwIds = [];
        foreach ($data['users'] ?? [] as $userKey=>$user) {
            if (!is_array($user)) continue;
            $legacyId = (string)($user['id'] ?? $userKey);
            if (!isset($adminLookup[$legacyId])) continue;
            $mgwId = trim((string)($user['mgw_id'] ?? ''));
            if ($mgwId !== '') $recipientMgwIds[$mgwId] = $mgwId;
        }
        if ($recipientMgwIds === []) return null;

        $title = trim((string)($tournament['title'] ?? 'Официальный турнир'));
        $count = (int)($tournament['registered_count'] ?? 0);
        $capacity = (int)($tournament['capacity'] ?? 0);
        $closedAt = trim((string)($tournament['registration_closed_at_utc'] ?? ''));
        if ($closedAt === '') {
            throw new RuntimeException('Full tournament is missing registration close time.');
        }

        return $this->events->createEvent(
            $data,
            [
                'source_type'=>'system',
                'audience_type'=>'segment',
                'audience_ref'=>'official-tournament-full:' . $tournamentId,
                'recipient_mgw_ids'=>array_values($recipientMgwIds),
                'title'=>'Состав турнира набран',
                'text'=>"«{$title}»: {$count}/{$capacity}. Регистрация закрыта. Турнир ожидает назначения даты.",
                'scheduled_at'=>$closedAt,
                'request_id'=>'official-tournament.' . $tournamentId . '.registration-full.admin',
            ],
            'system:tournament',
            $now
        );
    }
}
