<?php
declare(strict_types=1);

trait JsonInviteMatchmakingInviteTrait
{
    private function createDirect(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now,
        array $config
    ): array {
        $inviteeId = trim((string)($step['invitee_id'] ?? ''));
        if ($inviteeId === '' || $inviteeId === $actorId || !isset($state['users'][$inviteeId])) {
            throw new RuntimeException('Direct invite recipient is unavailable.');
        }
        $this->assertInviteAvailable($state, $actorId);
        $this->assertInviteAvailable($state, $inviteeId);
        $invite = $this->newInvite($fixture, $state, $actorId, $step, 'direct', 'pending', $now, $config);
        $invite['invitee_id'] = $inviteeId;
        $invite['invitee_name'] = $this->userName($state['users'][$inviteeId]);
        $invite['shared_at'] = $invite['created_at'];
        $state['invites'][] = $invite;
        $notification = $this->addNotification(
            $fixture,
            $state,
            $inviteeId,
            'invite:' . $invite['id'] . ':received:' . $inviteeId,
            'invite_received',
            'Вас пригласили сыграть',
            $invite['inviter_name'] . ' приглашает вас в «' . $invite['game_title'] . '».',
            'info',
            $invite['token'],
            $now
        );
        $context['last_token'] = $invite['token'];
        return [[
            'invite' => $this->publicInvite($invite, $actorId, $now),
            'recipient_id' => $inviteeId,
        ], $this->effects($notification ? [$notification] : [], [['type' => 'invite_created', 'token' => $invite['token']]], [])];
    }

    private function createLinkDraft(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now,
        array $config
    ): array {
        $this->assertInviteAvailable($state, $actorId);
        foreach ($state['invites'] ?? [] as &$stored) {
            if (is_array($stored)
                && (string)($stored['inviter_id'] ?? '') === $actorId
                && (string)($stored['status'] ?? '') === 'draft') {
                $stored['status'] = 'cancelled';
                $stored['cancelled_at'] = $now->format('c');
                $stored['cancelled_by'] = $actorId;
                $stored['updated_at'] = $now->format('c');
            }
        }
        unset($stored);
        $invite = $this->newInvite($fixture, $state, $actorId, $step, 'link', 'draft', $now, $config);
        $state['invites'][] = $invite;
        $context['last_token'] = $invite['token'];
        return [[
            'invite' => $this->publicInvite($invite, $actorId, $now),
        ], $this->effects([], [['type' => 'invite_draft_created', 'token' => $invite['token']]], [])];
    }

    private function confirmShared(array &$state, string $actorId, array $step, array &$context, DateTimeImmutable $now): array
    {
        $index = $this->inviteIndex($state, $this->token($step, $context));
        $invite =& $state['invites'][$index];
        if ((string)$invite['inviter_id'] !== $actorId) throw new RuntimeException('Only invite owner may confirm sharing.');
        if ((string)$invite['status'] === 'draft') {
            $invite['status'] = 'pending';
            $invite['shared_at'] = $now->format('c');
            $invite['updated_at'] = $now->format('c');
        }
        return [['invite' => $this->publicInvite($invite, $actorId, $now)], $this->effects([], [['type' => 'invite_shared', 'token' => $invite['token']]], [])];
    }

    private function openLink(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now
    ): array {
        $index = $this->inviteIndex($state, $this->token($step, $context));
        $invite =& $state['invites'][$index];
        if ((string)$invite['inviter_id'] === $actorId) throw new RuntimeException('Invite owner cannot open own link as opponent.');
        $bound = trim((string)($invite['invitee_id'] ?? ''));
        if ($bound !== '' && $bound !== $actorId) throw new RuntimeException('Link invite is already bound.');
        if ($bound === '') $this->assertInviteAvailable($state, $actorId, (string)$invite['token']);
        if ((string)$invite['status'] === 'draft') {
            $invite['status'] = 'pending';
            $invite['shared_at'] = (string)($invite['shared_at'] ?: $now->format('c'));
        }
        $invite['invitee_id'] = $actorId;
        $invite['invitee_name'] = $this->userName($state['users'][$actorId]);
        $invite['opened_at'] = (string)($invite['opened_at'] ?: $now->format('c'));
        $invite['open_requested_at'] = $now->format('c');
        $invite['updated_at'] = $now->format('c');
        $notification = $this->addNotification(
            $fixture,
            $state,
            $actorId,
            'invite:' . $invite['id'] . ':received:' . $actorId,
            'invite_received',
            'Вас пригласили сыграть',
            $invite['inviter_name'] . ' приглашает вас в «' . $invite['game_title'] . '».',
            'info',
            $invite['token'],
            $now
        );
        if ($notification !== null) {
            $last = array_key_last($state['notifications']);
            $state['notifications'][$last]['hidden_at'] = $now->format('c');
            $state['notifications'][$last]['read_at'] = $now->format('c');
            $notification = $state['notifications'][$last];
        }
        return [['invite' => $this->publicInvite($invite, $actorId, $now)], $this->effects($notification ? [$notification] : [], [['type' => 'invite_link_opened', 'token' => $invite['token']]], [])];
    }

    private function acceptInvite(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now,
        array $config
    ): array {
        $index = $this->inviteIndex($state, $this->token($step, $context));
        $invite =& $state['invites'][$index];
        if ((string)($invite['invitee_id'] ?? '') !== $actorId) throw new RuntimeException('Invite is for another player.');
        if ((string)$invite['status'] === 'active') {
            return [['invite' => $this->publicInvite($invite, $actorId, $now), 'game' => $this->publicGame($state['games'][(string)$invite['game_id']] ?? [])], $this->effects([], [], [])];
        }
        if ((string)$invite['status'] !== 'pending') throw new RuntimeException('Invite is unavailable.');
        $this->assertGameBalances($state, $invite);
        $invite['status'] = 'awaiting_start';
        $invite['accepted_at'] = $now->format('c');
        $invite['ready_deadline_at'] = $now->modify('+' . self::READY_TTL_SEC . ' seconds')->format('c');
        $invite['start_deadline_at'] = $invite['ready_deadline_at'];
        $invite['updated_at'] = $now->format('c');
        $notification = $this->addNotification(
            $fixture,
            $state,
            (string)$invite['inviter_id'],
            'invite:' . $invite['id'] . ':accepted',
            'invite_accepted',
            'Соперник согласен',
            (string)$invite['invitee_name'] . ' готов сыграть в «' . $invite['game_title'] . '».',
            'success',
            $invite['token'],
            $now
        );
        if ((string)$invite['source'] === 'rematch') {
            [$started, $effects] = $this->startInviteInternal($fixture, $state, $invite, $actorId, $now, $config);
            if ($notification !== null) array_unshift($effects['notifications'], $notification);
            return [$started, $effects];
        }
        return [[
            'invite' => $this->publicInvite($invite, $actorId, $now),
            'game' => null,
        ], $this->effects($notification ? [$notification] : [], [['type' => 'invite_accepted', 'token' => $invite['token']]], [])];
    }

    private function startInvite(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now,
        array $config
    ): array {
        $index = $this->inviteIndex($state, $this->token($step, $context));
        $invite =& $state['invites'][$index];
        if ((string)$invite['inviter_id'] !== $actorId) throw new RuntimeException('Only invite owner may start match.');
        return $this->startInviteInternal($fixture, $state, $invite, $actorId, $now, $config);
    }

    private function startInviteInternal(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$invite,
        string $viewerId,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)$invite['status'] === 'active') {
            return [[
                'invite' => $this->publicInvite($invite, $viewerId, $now),
                'game' => $this->publicGame($state['games'][(string)$invite['game_id']] ?? []),
            ], $this->effects([], [], [])];
        }
        if ((string)$invite['status'] !== 'awaiting_start') throw new RuntimeException('Opponent has not confirmed invite.');
        $inviterId = (string)$invite['inviter_id'];
        $inviteeId = (string)$invite['invitee_id'];
        $this->assertGameBalances($state, $invite);
        [$game, $ledger] = $this->createHumanGame(
            $fixture,
            $state,
            $inviterId,
            $inviteeId,
            (string)$invite['room'],
            (int)$invite['bet'],
            (int)$invite['board_size'],
            (string)$invite['game_type'],
            $now,
            (string)$invite['source'] === 'rematch' ? 'rematch' : 'invite',
            $invite
        );
        $invite['status'] = 'active';
        $invite['game_id'] = $game['id'];
        $invite['started_at'] = $now->format('c');
        $invite['updated_at'] = $now->format('c');
        $this->markInviteSeen($state, $inviterId, (string)$invite['token'], $now);
        $this->markInviteSeen($state, $inviteeId, (string)$invite['token'], $now);
        return [[
            'invite' => $this->publicInvite($invite, $viewerId, $now),
            'game' => $this->publicGame($game),
        ], $this->effects([], [['type' => 'invite_started', 'token' => $invite['token'], 'game_id' => $game['id']]], $ledger)];
    }

    private function cancelInvite(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now
    ): array {
        $index = $this->inviteIndex($state, $this->token($step, $context));
        $invite =& $state['invites'][$index];
        $isOwner = (string)$invite['inviter_id'] === $actorId;
        $isInvitee = (string)($invite['invitee_id'] ?? '') === $actorId;
        if (!$isOwner && !$isInvitee) throw new RuntimeException('Actor is not invite participant.');
        if (!in_array((string)$invite['status'], ['draft', 'pending', 'awaiting_start'], true)) {
            return [['invite' => $this->publicInvite($invite, $actorId, $now)], $this->effects([], [], [])];
        }
        if ((string)$invite['status'] === 'pending' && $isInvitee) throw new RuntimeException('Invitee must decline pending invite.');
        $invite['status'] = 'cancelled';
        $invite['cancelled_at'] = $now->format('c');
        $invite['cancelled_by'] = $actorId;
        $invite['updated_at'] = $now->format('c');
        $otherId = $isOwner ? (string)($invite['invitee_id'] ?? '') : (string)$invite['inviter_id'];
        $notification = null;
        if ($otherId !== '') {
            $notification = $this->addNotification(
                $fixture,
                $state,
                $otherId,
                'invite:' . $invite['id'] . ':cancelled:' . $actorId,
                'invite_cancelled',
                $isOwner ? 'Приглашение отменено' : 'Соперник отменил участие',
                $isOwner ? 'Матч «' . $invite['game_title'] . '» не начался.' : (string)$invite['invitee_name'] . ' отменил участие в матче.',
                'warning',
                (string)$invite['token'],
                $now
            );
        }
        return [['invite' => $this->publicInvite($invite, $actorId, $now)], $this->effects($notification ? [$notification] : [], [['type' => 'invite_cancelled', 'token' => $invite['token']]], [])];
    }

    private function rematch(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now,
        array $config
    ): array {
        $gameId = trim((string)($step['game_id'] ?? $context['last_game_id'] ?? ''));
        $game = $state['games'][$gameId] ?? null;
        if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished' || !empty($game['is_bot_game'])) {
            throw new RuntimeException('Rematch requires a finished human game.');
        }
        $players = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($players) !== 2 || !in_array($actorId, $players, true)) throw new RuntimeException('Actor is not game participant.');
        $opponentId = $players[0] === $actorId ? $players[1] : $players[0];
        foreach ($state['invites'] ?? [] as $index => $candidate) {
            if (!is_array($candidate) || (string)($candidate['source'] ?? '') !== 'rematch') continue;
            if ((string)($candidate['source_game_id'] ?? '') !== $gameId) continue;
            if (!in_array((string)($candidate['status'] ?? ''), ['pending', 'awaiting_start', 'active'], true)) continue;
            $participants = [(string)$candidate['inviter_id'], (string)$candidate['invitee_id']];
            sort($participants, SORT_STRING);
            $expected = $players;
            sort($expected, SORT_STRING);
            if ($participants !== $expected) continue;
            $context['last_token'] = (string)$candidate['token'];
            if ((string)$candidate['status'] === 'active') {
                return [[
                    'invite' => $this->publicInvite($candidate, $actorId, $now),
                    'game' => $this->publicGame($state['games'][(string)$candidate['game_id']] ?? []),
                    'reused' => true,
                ], $this->effects([], [], [])];
            }
            if ((string)$candidate['status'] === 'pending' && (string)$candidate['invitee_id'] === $actorId) {
                [$accepted, $effects] = $this->acceptInvite($fixture, $state, $actorId, ['token' => $candidate['token']], $context, $now, $config);
                $accepted['reused'] = true;
                return [$accepted, $effects];
            }
            return [[
                'invite' => $this->publicInvite($candidate, $actorId, $now),
                'game' => null,
                'reused' => true,
            ], $this->effects([], [], [])];
        }
        $this->assertInviteAvailable($state, $actorId);
        $this->assertInviteAvailable($state, $opponentId);
        $invite = $this->newInvite($fixture, $state, $actorId, [
            'game_type' => $game['game_type'] ?? 'tictactoe',
            'room' => $game['room'] ?? 'match',
            'bet' => $game['bet'] ?? 10,
            'board_size' => $game['board_size'] ?? 3,
        ], 'rematch', 'pending', $now, $config);
        $invite['invitee_id'] = $opponentId;
        $invite['invitee_name'] = $this->userName($state['users'][$opponentId]);
        $invite['source_game_id'] = $gameId;
        $invite['shared_at'] = $invite['created_at'];
        $state['invites'][] = $invite;
        $notification = $this->addNotification(
            $fixture,
            $state,
            $opponentId,
            'invite:' . $invite['id'] . ':received:' . $opponentId,
            'invite_rematch_received',
            'Вам предлагают реванш',
            $invite['inviter_name'] . ' предлагает реванш в «' . $invite['game_title'] . '».',
            'info',
            $invite['token'],
            $now
        );
        $context['last_token'] = $invite['token'];
        return [[
            'invite' => $this->publicInvite($invite, $actorId, $now),
            'game' => null,
            'opponent_id' => $opponentId,
            'reused' => false,
        ], $this->effects($notification ? [$notification] : [], [['type' => 'rematch_created', 'token' => $invite['token']]], [])];
    }

}
