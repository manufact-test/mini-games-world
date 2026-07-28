<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $tgUser = (new AuthService($config))->getUserFromRequest($payload);
    $userId = trim((string)($tgUser['id'] ?? ''));
    if ($userId === '') throw new RuntimeException('Пользователь не найден.');

    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $result = $db->readOnly(static function (array $data) use ($userId): array {
        $candidate = null;
        $candidateAt = 0;
        $now = time();

        foreach ($data['invites'] ?? [] as $invite) {
            if (!is_array($invite)) continue;
            if ((string)($invite['invitee_id'] ?? '') !== $userId) continue;
            if ((string)($invite['status'] ?? '') !== 'pending') continue;

            $expiresAt = strtotime((string)($invite['expires_at'] ?? '')) ?: 0;
            if ($expiresAt > 0 && $expiresAt <= $now) continue;

            $updatedAt = strtotime((string)($invite['updated_at'] ?? $invite['created_at'] ?? '')) ?: 0;
            if ($candidate !== null && $updatedAt < $candidateAt) continue;
            $candidate = $invite;
            $candidateAt = $updatedAt;
        }

        if (!is_array($candidate)) return ['invite' => null];
        $room = (string)($candidate['room'] ?? 'match') === 'gold' ? 'gold' : 'match';

        return [
            'invite' => [
                'token' => (string)($candidate['token'] ?? ''),
                'status' => 'pending',
                'source' => (string)($candidate['source'] ?? 'direct'),
                'inviter_id' => (string)($candidate['inviter_id'] ?? ''),
                'inviter_name' => (string)($candidate['inviter_name'] ?? 'Игрок'),
                'invitee_id' => (string)($candidate['invitee_id'] ?? ''),
                'invitee_name' => (string)($candidate['invitee_name'] ?? 'Игрок'),
                'game_type' => (string)($candidate['game_type'] ?? 'tictactoe'),
                'game_title' => (string)($candidate['game_title'] ?? 'Игра'),
                'room' => $room,
                'room_label' => $room === 'gold' ? 'Gold-комната' : 'Матч-комната',
                'bet' => (int)($candidate['bet'] ?? 0),
                'board_size' => (int)($candidate['board_size'] ?? 3),
                'board_columns' => (int)($candidate['board_columns'] ?? $candidate['board_size'] ?? 3),
                'board_rows' => (int)($candidate['board_rows'] ?? $candidate['board_size'] ?? 3),
                'expires_at' => (string)($candidate['expires_at'] ?? ''),
                'updated_at' => (string)($candidate['updated_at'] ?? ''),
                'is_owner' => false,
                'is_invitee' => true,
            ],
        ];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
