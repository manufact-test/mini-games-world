<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/social/PlayerReportService.php';
require_once __DIR__ . '/moderation/ModerationService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok' => false, 'error' => 'Очередь жалоб недоступна: DB отключена.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $reports = new PlayerReportService($database);
    $moderation = new ModerationService($database);
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $adminRef = 'telegram:' . trim((string)($admin['id'] ?? 'unknown'));
    $reportId = trim((string)($payload['report_id'] ?? ''));

    if ($action === 'set_status') {
        $reports->setStatus(
            $reportId,
            trim((string)($payload['status'] ?? '')),
            $adminRef
        );
    } elseif ($action === 'warning') {
        $moderation->warning(
            $reportId,
            (string)($payload['note'] ?? ''),
            $adminRef
        );
        $reports->setStatus($reportId, 'reviewing', $adminRef);
    } elseif ($action === 'restrict') {
        $moderation->restrict(
            $reportId,
            (string)($payload['scope'] ?? ''),
            $payload['duration_seconds'] ?? 0,
            (string)($payload['note'] ?? ''),
            $adminRef
        );
        $reports->setStatus($reportId, 'reviewing', $adminRef);
    } elseif ($action === 'recommend_ban') {
        $moderation->recommendPermanentBan(
            $reportId,
            (string)($payload['note'] ?? ''),
            $adminRef
        );
        $reports->setStatus($reportId, 'reviewing', $adminRef);
    } elseif ($action === 'review_ban') {
        $moderation->reviewPermanentBan(
            (string)($payload['moderation_action_id'] ?? ''),
            (string)($payload['decision'] ?? ''),
            (string)($payload['note'] ?? ''),
            $adminRef
        );
    } elseif ($action === 'review_appeal') {
        $moderation->reviewAppeal(
            (string)($payload['appeal_id'] ?? ''),
            (string)($payload['decision'] ?? ''),
            (string)($payload['note'] ?? ''),
            $adminRef
        );
    } elseif ($action !== 'snapshot') {
        json_response(['ok' => false, 'error' => 'Некорректное действие очереди жалоб.'], 400);
    }

    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
    $queue = $reports->queue(100, [
        'mode' => (string)($filters['mode'] ?? 'active'),
        'query' => (string)($filters['query'] ?? ''),
        'date_from' => (string)($filters['date_from'] ?? ''),
        'date_to' => (string)($filters['date_to'] ?? ''),
    ]);
    foreach ($queue as &$report) {
        $report['case_link'] = './admin.php?report=' . rawurlencode((string)$report['report_id']);
        $report['moderation'] = $moderation->reportSnapshot((string)$report['report_id']);
    }
    unset($report);

    json_response([
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'reports' => $queue,
        'statuses' => PlayerReportService::STATUSES,
        'moderation_options' => $moderation->adminOptions(),
        'admin_ref' => $adminRef,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (PlayerReportException $error) {
    $status = $error->reason === 'report_not_found' ? 404 : 422;
    json_response(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (ModerationException $error) {
    $status = match ($error->reason) {
        'report_not_found','action_not_found','appeal_not_found' => 404,
        'second_admin_required','appeal_forbidden' => 403,
        'ban_review_pending','appeal_exists','ban_review_race','appeal_review_race' => 409,
        default => 422,
    };
    json_response(['ok'=>false,'code'=>$error->reason,'error'=>$error->getMessage()], $status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin reports] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить очередь жалоб.'], 500);
}
