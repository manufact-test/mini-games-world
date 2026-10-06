<?php
declare(strict_types=1);

trait GameInviteActionTrait
{
    public function accept(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['invitee_id'] ?? '') !== $userId) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.other_player', 'This invitation belongs to another player.'));
        }

        $status = (string)($invite['status'] ?? '');
        if ($status === 'active') {
            return $this->resultWithGame($db, $invite, $userId);
        }
        UnifiedGameZonePolicy::assertInviteWritable($invite);
        if ($status === 'awaiting_start') {
            return ['invite' => $this->publicInvite($invite, $userId), 'game' => null];
        }
        if ($status !== 'pending') {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.invite_unavailable', 'This invitation is no longer available.'));
        }

        $inviterId = (string)($invite['inviter_id'] ?? '');
        if ($inviterId === '' || !isset($db['users'][$inviterId]) || !is_array($db['users'][$inviterId])) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.inviter_unavailable', 'The inviting player is no longer available.'));
        }

        $inviter =& $db['users'][$inviterId];
        $invitee =& $db['users'][$userId];
        $this->assertAvailableForStart($db, $invitee, $token, $this->inviteCopy('server.invite_chain.errors.finish_search_or_game', 'Finish your current search or game first.'));
        $this->assertAvailableForStart($db, $inviter, $token, $this->inviteCopy('server.invite_chain.errors.inviter_busy_other_game', 'The inviting player is currently busy in another game.'));
        $this->assertBalances($inviter, $invitee, $invite);

        $now = now_iso();
        $invite['status'] = 'awaiting_start';
        $invite['accepted_at'] = $now;
        $invite['ready_deadline_at'] = gmdate('c', time() + self::READY_TTL_SEC);
        $invite['start_deadline_at'] = $invite['ready_deadline_at'];
        $invite['updated_at'] = $now;

        $this->addNotification(
            $db,
            $inviterId,
            'invite:' . (string)($invite['id'] ?? $token) . ':accepted',
            'invite_accepted',
            $this->inviteCopy('server.invite_chain.notifications.accepted_title', 'Opponent accepted'),
            $this->inviteCopy('server.invite_chain.notifications.accepted_message', '{name} is ready to play “{game}”.', [
                'name'=>(string)($invite['invitee_name'] ?? $this->inviteCopy('server.invites.player_fallback', 'Player')),
                'game'=>(string)($invite['game_title'] ?? $this->inviteCopy('server.invites.game_lower_fallback', 'game')),
            ]),
            'success',
            (string)($invite['token'] ?? '')
        );

        return ['invite' => $this->publicInvite($invite, $userId), 'game' => null];
    }

    public function start(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['inviter_id'] ?? '') !== $userId) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.start_owner_only', 'Only the inviting player can start the match.'));
        }
        return $this->startInternal($db, $invite, $userId);
    }

    public function decline(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['invitee_id'] ?? '') !== $userId) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.other_player', 'This invitation belongs to another player.'));
        }
        if ((string)($invite['status'] ?? '') !== 'pending') {
            return $this->publicInvite($invite, $userId);
        }

        $now = now_iso();
        $invite['status'] = 'declined';
        $invite['declined_at'] = $now;
        $invite['updated_at'] = $now;
        $this->addNotification(
            $db,
            (string)($invite['inviter_id'] ?? ''),
            'invite:' . (string)($invite['id'] ?? $token) . ':declined',
            'invite_declined',
            $this->inviteCopy('server.notifications.invite_declined_title', 'Invitation declined'),
            $this->inviteCopy('server.invite_chain.notifications.declined_message', '{name} declined the match “{game}”.', [
                'name'=>(string)($invite['invitee_name'] ?? $this->inviteCopy('server.invites.player_fallback', 'Player')),
                'game'=>(string)($invite['game_title'] ?? $this->inviteCopy('server.invites.game_fallback', 'Game')),
            ]),
            'warning',
            (string)($invite['token'] ?? '')
        );
        return $this->publicInvite($invite, $userId);
    }

    public function cancel(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
        $isInvitee = (string)($invite['invitee_id'] ?? '') === $userId;
        if (!$isOwner && !$isInvitee) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.not_participant_invite', 'You are not a participant in this invitation.'));
        }

        $status = (string)($invite['status'] ?? '');
        if (!in_array($status, ['draft', 'pending', 'awaiting_start'], true)) {
            return $this->publicInvite($invite, $userId);
        }
        if ($status === 'pending' && $isInvitee) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.use_decline_button', 'Use the Decline button.'));
        }

        $now = now_iso();
        $invite['status'] = 'cancelled';
        $invite['cancelled_at'] = $now;
        $invite['cancelled_by'] = $userId;
        $invite['updated_at'] = $now;

        $otherId = $isOwner ? (string)($invite['invitee_id'] ?? '') : (string)($invite['inviter_id'] ?? '');
        if ($otherId !== '') {
            $title = $isOwner
                ? $this->inviteCopy('server.notifications.invite_cancelled_title', 'Invitation cancelled')
                : $this->inviteCopy('server.notifications.opponent_cancelled_title', 'Opponent cancelled');
            $message = $isOwner
                ? $this->inviteCopy('server.invite_chain.notifications.owner_cancelled_message', 'The match “{game}” did not start.', [
                    'game'=>(string)($invite['game_title'] ?? $this->inviteCopy('server.invites.game_fallback', 'Game')),
                ])
                : $this->inviteCopy('server.invite_chain.notifications.invitee_cancelled_message', '{name} cancelled participation in the match.', [
                    'name'=>(string)($invite['invitee_name'] ?? $this->inviteCopy('server.invites.player_fallback', 'Player')),
                ]);
            $this->addNotification(
                $db,
                $otherId,
                'invite:' . (string)($invite['id'] ?? $token) . ':cancelled:' . $userId,
                'invite_cancelled',
                $title,
                $message,
                'warning',
                (string)($invite['token'] ?? '')
            );
        }

        return $this->publicInvite($invite, $userId);
    }

    public function createRematch(array &$db, array &$user, string $gameId): array
    {
        $this->cleanup($db);
        $userId = $this->requireUserId($user);
        $game = $db['games'][$gameId] ?? null;
        if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.rematch_finished_only', 'A rematch is available only after a finished game.'));
        }
        if (!empty($game['is_bot_game'])) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.rematch_human_only', 'A rematch is available only with a human opponent.'));
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) !== 2 || !in_array($userId, $playerIds, true)) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.not_participant_match', 'You are not a participant in this match.'));
        }
        $opponentId = $playerIds[0] === $userId ? $playerIds[1] : $playerIds[0];
        if ($opponentId === '' || !isset($db['users'][$opponentId]) || !is_array($db['users'][$opponentId])) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.rematch_opponent_unavailable', 'The rematch opponent is unavailable.'));
        }

        $existingIndex = $this->findOpenRematchIndex($db, $gameId, $playerIds);
        if ($existingIndex !== null) {
            $existing =& $db['invites'][$existingIndex];
            $status = (string)($existing['status'] ?? '');
            if ($status === 'active') {
                return $this->resultWithGame($db, $existing, $userId) + ['reused' => true];
            }
            if ($status === 'awaiting_start') {
                return ['invite' => $this->publicInvite($existing, $userId), 'game' => null, 'reused' => true];
            }
            if ($status === 'pending') {
                if ((string)($existing['inviter_id'] ?? '') === $userId) {
                    return ['invite' => $this->publicInvite($existing, $userId), 'game' => null, 'reused' => true];
                }
                if ((string)($existing['invitee_id'] ?? '') === $userId) {
                    $existingToken = (string)($existing['token'] ?? '');
                    unset($existing);
                    $acceptResult = $this->accept($db, $user, $existingToken);
                    return $acceptResult + ['reused' => true];
                }
            }
        }

        $this->assertAvailableForInvite($db, $user, $this->inviteCopy('server.invite_chain.errors.finish_search_match_invite', 'Finish your current search, match, or invitation first.'));
        $opponent =& $db['users'][$opponentId];
        $this->assertAvailableForInvite($db, $opponent, $this->inviteCopy('server.invite_chain.errors.opponent_busy_search_match_invite', 'The opponent is busy with a search, match, or another invitation.'));

        $gameType = $this->catalog->normalizeGameType((string)($game['game_type'] ?? 'tictactoe'));
        $room = UnifiedGameZonePolicy::storageRoom();
        $bet = UnifiedGameZonePolicy::entryCost($this->config);
        $boardSize = (int)($game['board_size'] ?? 3);

        $invite = $this->newInvite($db, $user, $gameType, $room, $bet, $boardSize, 'rematch', 'pending');
        $invite['invitee_id'] = $opponentId;
        $invite['invitee_name'] = $this->userName($opponent);
        $invite['source_game_id'] = $gameId;
        $invite['shared_at'] = $invite['created_at'];
        $db['invites'][] = $invite;
        $this->addReceivedNotification($db, $invite);

        return [
            'invite' => $this->publicInvite($invite, $userId),
            'game' => null,
            'opponent_id' => $opponentId,
            'opponent_name' => $this->userName($opponent),
            'reused' => false,
        ];
    }

    public function markSeen(array &$db, string $userId, string $token): void
    {
        if ($userId === '' || $token === '') return;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) return;
        $now = now_iso();
        foreach ($db['notifications'] as &$notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if ((string)($notification['invite_token'] ?? '') !== $token) continue;
            if (empty($notification['read_at'])) $notification['read_at'] = $now;
        }
        unset($notification);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['invites']) || !is_array($db['invites'])) $db['invites'] = [];
        $now = time();
        foreach ($db['invites'] as &$invite) {
            if (!is_array($invite)) continue;
            $this->normalizeLegacy($invite);
            $this->expireIfDue($db, $invite, $now);
        }
        unset($invite);

        $db['invites'] = array_values(array_filter($db['invites'], static function ($invite) use ($now): bool {
            if (!is_array($invite)) return false;
            $activity = strtotime((string)($invite['updated_at'] ?? $invite['created_at'] ?? '')) ?: $now;
            return $now - $activity <= self::RETENTION_SEC;
        }));
    }

    private function startInternal(array &$db, array &$invite, string $viewerId): array
    {
        $status = (string)($invite['status'] ?? '');
        if ($status === 'active') return $this->resultWithGame($db, $invite, $viewerId);
        UnifiedGameZonePolicy::assertInviteWritable($invite);
        if ($status !== 'awaiting_start') {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.opponent_not_confirmed', 'The opponent has not confirmed the invitation yet.'));
        }

        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        if ($inviterId === '' || $inviteeId === ''
            || !isset($db['users'][$inviterId]) || !is_array($db['users'][$inviterId])
            || !isset($db['users'][$inviteeId]) || !is_array($db['users'][$inviteeId])) {
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.participant_unavailable', 'One of the players is no longer available.'));
        }

        $inviter =& $db['users'][$inviterId];
        $invitee =& $db['users'][$inviteeId];
        $token = (string)($invite['token'] ?? '');
        $this->assertAvailableForStart($db, $inviter, $token, $this->inviteCopy('server.invite_chain.errors.inviter_busy_other_game', 'The inviting player is currently busy in another game.'));
        $this->assertAvailableForStart($db, $invitee, $token, $this->inviteCopy('server.invite_chain.errors.invitee_busy_other_game', 'The invited player is currently busy in another game.'));
        $this->assertBalances($inviter, $invitee, $invite);

        $invite['status'] = 'starting';
        $invite['updated_at'] = now_iso();
        try {
            $game = $this->createIsolatedGame($db, $inviter, $invitee, $invite);
        } catch (Throwable $e) {
            $invite['status'] = 'awaiting_start';
            $invite['updated_at'] = now_iso();
            throw $e;
        }

        $gameId = (string)($game['id'] ?? '');
        if ($gameId === '') {
            $invite['status'] = 'awaiting_start';
            $invite['updated_at'] = now_iso();
            throw new RuntimeException($this->inviteCopy('server.invite_chain.errors.private_match_failed', 'The private match could not be created.'));
        }

        $now = now_iso();
        $invite['status'] = 'active';
        $invite['game_id'] = $gameId;
        $invite['started_at'] = $now;
        $invite['updated_at'] = $now;
        $this->markSeen($db, $inviterId, $token);
        $this->markSeen($db, $inviteeId, $token);

        return [
            'invite' => $this->publicInvite($invite, $viewerId),
            'game' => $this->games->publicGame($db['games'][$gameId], $viewerId),
        ];
    }

    private function resultWithGame(array $db, array $invite, string $viewerId): array
    {
        $gameId = (string)($invite['game_id'] ?? '');
        $game = $gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])
            ? $db['games'][$gameId]
            : null;
        return [
            'invite' => $this->publicInvite($invite, $viewerId),
            'game' => is_array($game) && (string)($game['status'] ?? '') === 'active'
                ? $this->games->publicGame($game, $viewerId)
                : null,
        ];
    }

    private function activeForUser(array $db, string $userId): ?array
    {
        $candidates = [];
        foreach ($db['invites'] ?? [] as $invite) {
            if (!is_array($invite) || !$this->isParticipant($invite, $userId)) continue;
            $status = (string)($invite['status'] ?? '');
            if (!in_array($status, ['pending', 'awaiting_start'], true)) continue;
            $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
            $priority = $status === 'awaiting_start' ? 300 : ($isOwner ? 100 : 200);
            $candidates[] = [
                'priority' => $priority,
                'updated' => strtotime((string)($invite['updated_at'] ?? $invite['created_at'] ?? '')) ?: 0,
                'invite' => $invite,
            ];
        }
        if (!$candidates) return null;
        usort($candidates, static function (array $left, array $right): int {
            $priority = $right['priority'] <=> $left['priority'];
            return $priority !== 0 ? $priority : ($right['updated'] <=> $left['updated']);
        });
        return $this->publicInvite($candidates[0]['invite'], $userId);
    }

    private function inviteEventsForUser(array $db, string $userId): array
    {
        $events = [];
        $invites = $this->invitesByToken($db);
        foreach ($db['notifications'] ?? [] as $notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if (!empty($notification['hidden_at'])) continue;
            if (!str_starts_with((string)($notification['type'] ?? ''), 'invite_')) continue;
            if (!$this->inviteNotificationVisible($notification, $invites)) continue;
            $events[] = [
                'id' => (string)($notification['id'] ?? ''),
                'type' => (string)($notification['type'] ?? ''),
                'title' => (string)($notification['title'] ?? ''),
                'message' => (string)($notification['message'] ?? ''),
                'tone' => (string)($notification['tone'] ?? 'info'),
                'invite_token' => (string)($notification['invite_token'] ?? ''),
                'created_at' => (string)($notification['created_at'] ?? ''),
                'read' => !empty($notification['read_at']),
            ];
        }
        usort($events, static function (array $left, array $right): int {
            $leftTime = strtotime((string)($left['created_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string)($right['created_at'] ?? '')) ?: 0;
            if ($leftTime !== $rightTime) return $rightTime <=> $leftTime;
            return strcmp((string)($right['id'] ?? ''), (string)($left['id'] ?? ''));
        });
        return array_slice($events, 0, 20);
    }
}
