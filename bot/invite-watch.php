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

    // This endpoint is only a low-latency wake-up signal. The client must still
    // reconcile through canonical /bot/invites.php sync before mutating invite
    // UI/state, so the runtime-file signal never becomes a second state owner.
    $invite = (new InviteSignalService($config))->latest($userId);

    api_ok([
        'invite' => is_array($invite) ? $invite : null,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
