<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/InviteSignalService.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $tgUser = (new AuthService($config))->getUserFromRequest($payload);
    $userId = trim((string)($tgUser['id'] ?? ''));
    if ($userId === '') throw new RuntimeException('Пользователь не найден.');

    $invite = (new InviteSignalService($config))->latest($userId);
    $pending = is_array($invite) && (string)($invite['status'] ?? '') === 'pending';

    // Pending received invitations are notification-only. The canonical sync
    // response still carries their invite_events and unread count, but they must
    // not become currentInvite and intercept unrelated game launches.
    api_ok([
        'invite' => null,
        'notification_pending' => $pending,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
