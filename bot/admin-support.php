<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/support/SupportTicketService.php';

function mgw_admin_support_status(string $reason): int
{
    return match ($reason) {
        'ticket_not_found', 'attachment_not_found', 'user_unavailable' => 404,
        'ticket_closed' => 409,
        default => 422,
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

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $actorRef = 'telegram:' . trim((string)($admin['id'] ?? 'unknown'));

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok' => false, 'error' => 'Поддержка недоступна: DB отключена.'], 503);
    }

    $service = new SupportTicketService(PdoConnectionFactory::create($databaseConfig));
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $ticketRef = (string)($payload['ticket'] ?? $payload['ticket_number'] ?? '');
    $ticket = null;

    try {
        switch ($action) {
            case 'ticket':
                $ticket = $service->adminTicket($ticketRef);
                break;

            case 'set_status':
                $ticket = $service->setStatus($ticketRef, (string)($payload['status'] ?? ''), $actorRef);
                break;

            case 'set_priority':
                $ticket = $service->setPriority($ticketRef, (string)($payload['priority'] ?? ''), $actorRef);
                break;

            case 'assign_self':
                $ticket = $service->assignOwner($ticketRef, $actorRef, $actorRef);
                break;

            case 'unassign':
                $ticket = $service->assignOwner($ticketRef, null, $actorRef);
                break;

            case 'reply':
                $ticket = $service->replyByAdmin(
                    $ticketRef,
                    $actorRef,
                    (string)($payload['message'] ?? ''),
                    is_array($payload['attachments'] ?? null) ? $payload['attachments'] : []
                );
                break;

            case 'update_related':
                $ticket = $service->updateRelated(
                    $ticketRef,
                    is_array($payload['related'] ?? null) ? $payload['related'] : [],
                    $actorRef
                );
                break;

            case 'attachment':
                json_response([
                    'ok' => true,
                    'action' => 'attachment',
                    'attachment' => $service->attachmentForAdmin((string)($payload['attachment_id'] ?? '')),
                ]);

            case 'snapshot':
                break;

            default:
                json_response(['ok' => false, 'error' => 'Некорректное действие поддержки.'], 400);
        }

        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        json_response([
            'ok' => true,
            'action' => $action,
            'generated_at' => gmdate(DATE_ATOM),
            'admin_ref' => $actorRef,
            'ticket' => $ticket,
            'tickets' => $service->adminQueue($filters, 100),
            'metrics' => $service->queueMetrics(),
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
        ], mgw_admin_support_status($error->reason));
    }
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin support] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить поддержку.'], 500);
}
