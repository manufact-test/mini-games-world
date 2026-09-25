<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/ledger/CompensationService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $action = trim((string)($payload['action'] ?? ''));
    if (!in_array($action, ['snapshot','lookup','request','confirm'], true)) {
        json_response(['ok'=>false,'error'=>'Некорректное действие компенсации.'], 400);
    }

    $telegramUser = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $telegramId = trim((string)($telegramUser['id'] ?? ''));
    if ($telegramId === '') throw new RuntimeException('Authorized Telegram admin identity is unavailable.');
    $actorRef = 'telegram:' . $telegramId;

    $database = PdoConnectionFactory::create(DatabaseConfig::fromApplicationConfig($config));
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $service = new CompensationService($database, new LedgerWriteService($database), $storage);

    if ($action === 'lookup') {
        json_response([
            'ok'=>true,
            'generated_at'=>gmdate(DATE_ATOM),
            'policy'=>$service->policy(),
            'original'=>$service->lookupOriginal((string)($payload['reference'] ?? '')),
        ]);
    }

    if ($action === 'request') {
        $amount = filter_var($payload['amount'] ?? null, FILTER_VALIDATE_INT);
        if ($amount === false) throw new InvalidArgumentException('Укажите целую сумму компенсации.');
        $compensation = $service->request(
            (string)($payload['reference'] ?? ''),
            (int)$amount,
            (string)($payload['reason'] ?? ''),
            $actorRef,
            (string)($payload['request_token'] ?? '')
        );
        json_response([
            'ok'=>true,
            'generated_at'=>gmdate(DATE_ATOM),
            'policy'=>$service->policy(),
            'compensation'=>$compensation,
            'history'=>$service->history(),
        ]);
    }

    if ($action === 'confirm') {
        $compensation = $service->confirm((string)($payload['compensation_id'] ?? ''), $actorRef);
        json_response([
            'ok'=>true,
            'generated_at'=>gmdate(DATE_ATOM),
            'policy'=>$service->policy(),
            'compensation'=>$compensation,
            'history'=>$service->history(),
        ]);
    }

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'policy'=>$service->policy(),
        'history'=>$service->history(),
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin compensation] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось выполнить компенсацию.'], 500);
}
