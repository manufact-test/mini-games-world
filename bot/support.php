<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/support/SupportTicketService.php';
require_once __DIR__ . '/support/SupportTelegramNotifier.php';

function mgw_support_status(string $reason): int
{
    return match ($reason) {
        'ticket_not_found', 'attachment_not_found', 'user_unavailable' => 404,
        'ticket_closed' => 409,
        'message_required', 'invalid_platform', 'invalid_category', 'invalid_priority',
        'invalid_attachment', 'attachment_too_large', 'too_many_attachments', 'ticket_required' => 422,
        default => 422,
    };
}

function mgw_support_platform(array $user): string
{
    $provider = strtolower(trim((string)($user['mgw_identity_provider'] ?? '')));
    return in_array($provider, ['google', 'google_play'], true) ? 'google_play' : 'telegram';
}

function mgw_support_category(array $payload): string
{
    $category = strtolower(trim((string)($payload['category'] ?? '')));
    if ($category !== '') return $category;
    $legacy = strtolower(trim((string)($payload['type'] ?? '')));
    return match ($legacy) {
        'feedback' => 'feedback',
        'idea' => 'idea',
        'complaint' => 'complaint',
        default => 'other',
    };
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $authenticatedUser = (new AuthService($config))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok' => false, 'error' => 'Поддержка MGW временно недоступна.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new SupportTicketService($database);
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));

    try {
        if ($action === 'create') {
            $ticket = $service->createTicket(
                $mgwId,
                mgw_support_platform($authenticatedUser),
                mgw_support_category($payload),
                strtolower(trim((string)($payload['priority'] ?? 'normal'))),
                (string)($payload['subject'] ?? ''),
                (string)($payload['message'] ?? ''),
                is_array($payload['related'] ?? null) ? $payload['related'] : [],
                is_array($payload['attachments'] ?? null) ? $payload['attachments'] : []
            );

            $notifier = new SupportTelegramNotifier($config, new TelegramService($config));
            $kind = (string)($ticket['priority'] ?? '') === 'critical' ? 'critical' : 'summary';
            if ($notifier->notifyCreated($ticket, $service->queueMetrics())) {
                $service->markTelegramAlerted((string)$ticket['ticket_number'], $kind);
                $ticket = $service->ticketForUser((string)$ticket['ticket_number'], $mgwId);
            }

            json_response([
                'ok' => true,
                'action' => 'create',
                'ticket' => $ticket,
            ]);
        }

        if ($action === 'reply') {
            $ticket = $service->replyByUser(
                (string)($payload['ticket'] ?? $payload['ticket_number'] ?? ''),
                $mgwId,
                (string)($payload['message'] ?? ''),
                is_array($payload['attachments'] ?? null) ? $payload['attachments'] : []
            );

            $adminAlertSent = (new SupportTelegramNotifier($config, new TelegramService($config)))
                ->notifyUserReply($ticket);

            json_response([
                'ok' => true,
                'action' => 'reply',
                'ticket' => $ticket,
                'admin_alert_sent' => $adminAlertSent,
            ]);
        }

        if ($action === 'ticket') {
            $ticket = $service->ticketForUser(
                (string)($payload['ticket'] ?? $payload['ticket_number'] ?? ''),
                $mgwId
            );
            json_response(['ok' => true, 'action' => 'ticket', 'ticket' => $ticket]);
        }

        if ($action === 'attachment') {
            $attachment = $service->attachmentForUser(
                (string)($payload['attachment_id'] ?? ''),
                $mgwId
            );
            json_response(['ok' => true, 'action' => 'attachment', 'attachment' => $attachment]);
        }

        if ($action !== 'snapshot') {
            json_response(['ok' => false, 'error' => 'Некорректное действие поддержки.'], 400);
        }

        json_response([
            'ok' => true,
            'action' => 'snapshot',
            'tickets' => $service->userSnapshot($mgwId, 50),
            'categories' => SupportTicketService::CATEGORY_LABELS,
            'priorities' => SupportTicketService::PRIORITY_LABELS,
            'statuses' => SupportTicketService::STATUS_LABELS,
            'platforms' => SupportTicketService::PLATFORM_LABELS,
        ]);
    } catch (SupportTicketException $error) {
        json_response([
            'ok' => false,
            'code' => $error->reason,
            'error' => $error->getMessage(),
        ], mgw_support_status($error->reason));
    }
} catch (Throwable $error) {
    error_log('[MiniGamesWorld support] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось обработать обращение в поддержку.'], 500);
}
