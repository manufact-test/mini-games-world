<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/replay/MatchReplayReader.php';
require_once __DIR__ . '/antifraud/AntiFraudCaseService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $telegramUser = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $actorRef = 'telegram:' . trim((string)($telegramUser['id'] ?? ''));
    if ($actorRef === 'telegram:') {
        json_response(['ok' => false, 'error' => 'Не удалось определить администратора.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok' => false, 'error' => 'Anti-fraud storage недоступен: DB-primary отключён.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $reader = new MatchReplayReader($database);
    $service = new AntiFraudCaseService($database, $reader);
    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

    if ($action === 'snapshot') {
        $snapshot = $service->snapshot($filters, 100);
        json_response([
            'ok' => true,
            'generated_at' => gmdate(DATE_ATOM),
            'admin_ref' => $actorRef,
        ] + $snapshot);
    }

    if ($action === 'match_replay' || $action === 'match_review') {
        $matchId = trim((string)($payload['matchId'] ?? $payload['match_id'] ?? ''));
        $review = $service->review($matchId);
        json_response([
            'ok' => true,
            'generated_at' => gmdate(DATE_ATOM),
            'admin_ref' => $actorRef,
            'review' => $review,
            // Legacy compatibility for the old diagnostics reader.
            'replay' => $review['replay'] ?? null,
        ]);
    }

    if ($action === 'case') {
        $case = $service->caseById((string)($payload['case_id'] ?? ''));
        $review = $service->review((string)$case['match_id']);
        json_response([
            'ok' => true,
            'generated_at' => gmdate(DATE_ATOM),
            'admin_ref' => $actorRef,
            'case' => $case,
            'review' => $review,
        ] + $service->snapshot($filters, 100));
    }

    if ($action === 'create_case') {
        $case = $service->createCase(
            (string)($payload['match_id'] ?? $payload['matchId'] ?? ''),
            $actorRef,
            (string)($payload['summary'] ?? '')
        );
        $review = $service->review((string)$case['match_id']);
        json_response([
            'ok' => true,
            'generated_at' => gmdate(DATE_ATOM),
            'admin_ref' => $actorRef,
            'case' => $case,
            'review' => $review,
        ] + $service->snapshot($filters, 100));
    }

    if ($action === 'take_case') {
        $case = $service->takeInReview((string)($payload['case_id'] ?? ''), $actorRef);
        $review = $service->review((string)$case['match_id']);
        json_response([
            'ok' => true,
            'generated_at' => gmdate(DATE_ATOM),
            'admin_ref' => $actorRef,
            'case' => $case,
            'review' => $review,
        ] + $service->snapshot($filters, 100));
    }

    if ($action === 'resolve_case') {
        $case = $service->resolve(
            (string)($payload['case_id'] ?? ''),
            (string)($payload['decision'] ?? ''),
            (string)($payload['note'] ?? ''),
            $actorRef
        );
        $review = $service->review((string)$case['match_id']);
        json_response([
            'ok' => true,
            'generated_at' => gmdate(DATE_ATOM),
            'admin_ref' => $actorRef,
            'case' => $case,
            'review' => $review,
        ] + $service->snapshot($filters, 100));
    }

    json_response(['ok' => false, 'error' => 'Некорректный anti-fraud запрос.'], 400);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (AntiFraudCaseException $error) {
    $status = match ($error->reason) {
        'match_not_found', 'case_not_found' => 404,
        'case_terminal', 'case_not_reviewing' => 409,
        default => 400,
    };
    json_response(['ok' => false, 'error' => $error->getMessage(), 'reason' => $error->reason], $status);
} catch (InvalidArgumentException $error) {
    json_response(['ok' => false, 'error' => 'Укажите корректный ID матча.'], 400);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin anti-fraud] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось выполнить anti-fraud проверку.'], 500);
}
