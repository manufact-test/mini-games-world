<?php
declare(strict_types=1);

// API/browser requests must never leak PHP warnings/notices into the JSON body.
// They remain available in the server error log. Keep CLI diagnostics unchanged.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    // A single malformed legacy/database string must not turn a successful API
    // response into an empty HTTP 200 body. Substitute invalid UTF-8 at the API
    // boundary and fail closed with valid JSON if encoding still cannot complete.
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"error":"Не удалось сформировать ответ API."}';
    }

    echo $json;
    exit;
}

function mgw_payment_activity_at(array $payment): int {
    foreach (['applied_at', 'rejected_at', 'cancelled_at', 'updated_at', 'created_at'] as $field) {
        $timestamp = strtotime((string)($payment[$field] ?? '')) ?: 0;
        if ($timestamp > 0) return $timestamp;
    }
    return 0;
}

function mgw_sort_payments_by_activity(array $payments): array {
    $payments = array_values(array_filter($payments, 'is_array'));
    usort($payments, static function (array $left, array $right): int {
        return mgw_payment_activity_at($right) <=> mgw_payment_activity_at($left);
    });
    return $payments;
}

function mgw_run_api_data_filters(array $data): array {
    $filters = $GLOBALS['mgw_api_data_filters'] ?? [];
    unset($GLOBALS['mgw_api_data_filters']);
    if (!is_array($filters)) return $data;

    foreach ($filters as $filter) {
        if (!is_callable($filter)) continue;
        $filtered = $filter($data);
        if (!is_array($filtered)) {
            throw new RuntimeException('API data filter must return an array.');
        }
        $data = $filtered;
    }
    return $data;
}

/**
 * Resolve visible game names from the canonical MGW account database without
 * mutating authenticated runtime users, search/session state or stored games.
 *
 * The legacy game record still owns mechanics and persistence. This helper is
 * a read-only response projection used only when a successful API response
 * already contains public game players.
 */
function mgw_canonical_game_player_names(array $playerIds): array {
    $subjects = [];
    foreach ($playerIds as $playerId) {
        $subject = trim((string)$playerId);
        if ($subject === '' || str_starts_with($subject, 'bot_')) continue;
        $subjects[$subject] = true;
    }
    $subjects = array_keys($subjects);
    if ($subjects === []) return [];

    $config = $GLOBALS['config'] ?? null;
    if (!is_array($config)
        || !class_exists('DatabaseConfig')
        || !class_exists('PdoConnectionFactory')) {
        return [];
    }

    try {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) return [];
        $database = PdoConnectionFactory::create($databaseConfig);

        $placeholders = [];
        $parameters = [];
        foreach ($subjects as $index => $subject) {
            $key = ':subject_' . $index;
            $placeholders[] = $key;
            $parameters[$key] = $subject;
        }

        $rows = $database->fetchAll(
            'SELECT i.provider_subject, i.mgw_id, u.nickname
             FROM mgw_identities i
             INNER JOIN mgw_users u ON u.mgw_id = i.mgw_id
             WHERE i.provider_subject IN (' . implode(', ', $placeholders) . ")
               AND i.provider IN ('telegram', 'development')
               AND u.status = 'active'",
            $parameters
        );
    } catch (Throwable $error) {
        error_log('Mini Games World game identity projection failed: ' . $error->getMessage());
        return [];
    }

    // A provider subject may theoretically exist under more than one provider.
    // Only project when all rows for that subject resolve to one MGW owner;
    // otherwise preserve the already-safe legacy display name.
    $owners = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $subject = trim((string)($row['provider_subject'] ?? ''));
        $mgwId = trim((string)($row['mgw_id'] ?? ''));
        $nickname = trim((string)($row['nickname'] ?? ''));
        if ($subject === '' || $mgwId === '' || $nickname === '') continue;
        $owners[$subject][$mgwId] = $nickname;
    }

    $names = [];
    foreach ($owners as $subject => $byOwner) {
        if (count($byOwner) !== 1) continue;
        $nickname = reset($byOwner);
        if (is_string($nickname) && trim($nickname) !== '') {
            $names[(string)$subject] = trim($nickname);
        }
    }
    return $names;
}

function mgw_project_canonical_game_identity(array $data): array {
    foreach (['game', 'active_game'] as $gameKey) {
        $game = $data[$gameKey] ?? null;
        if (!is_array($game) || !isset($game['players']) || !is_array($game['players'])) {
            continue;
        }

        $playerIds = [];
        foreach ($game['players'] as $player) {
            if (!is_array($player)) continue;
            $playerIds[] = (string)($player['id'] ?? '');
        }
        $canonicalNames = mgw_canonical_game_player_names($playerIds);
        if ($canonicalNames === []) continue;

        foreach ($game['players'] as &$player) {
            if (!is_array($player)) continue;
            $playerId = trim((string)($player['id'] ?? ''));
            if ($playerId !== '' && isset($canonicalNames[$playerId])) {
                $player['name'] = $canonicalNames[$playerId];
            }
        }
        unset($player);

        $data[$gameKey] = $game;
    }

    return $data;
}

function mgw_normalize_api_data(array $data): array {
    $data = mgw_run_api_data_filters($data);
    $data = mgw_project_canonical_game_identity($data);

    if ((string)($data['message'] ?? '') === 'Заявка на пополнение создана. Баланс не изменён.') {
        $data['message'] = 'Баланс изменится после подтверждения администратором.';
    }

    if (isset($data['payments']['message']) && is_string($data['payments']['message'])) {
        $data['payments']['message'] = 'Баланс изменится после подтверждения администратором.';
    }

    if (isset($data['payments']['recent_payments']) && is_array($data['payments']['recent_payments'])) {
        $data['payments']['recent_payments'] = mgw_sort_payments_by_activity($data['payments']['recent_payments']);
    }

    if (isset($data['topups']) && is_array($data['topups'])) {
        $data['topups'] = mgw_sort_payments_by_activity($data['topups']);
    }

    $operations = $data['history']['operations'] ?? null;
    if (!is_array($operations)) {
        return $data;
    }

    // Старые версии магазина создавали две записи на один заказ:
    // 1) реальное balance_change со списанием;
    // 2) техническую shop_order с той же датой и суммой.
    // Сопоставляем их попарно, а не через простой unique-key, чтобы два
    // настоящих одинаковых заказа в одну секунду не схлопнулись в один.
    $groups = [];
    foreach ($operations as $index => $item) {
        if (!is_array($item) || (string)($item['title'] ?? '') !== 'Заказ приза') {
            continue;
        }

        $description = (string)($item['description'] ?? '');
        $kind = null;
        if (str_starts_with($description, 'Заказ приза:')) {
            $kind = 'financial';
        } elseif (str_starts_with($description, 'Магазин призов ·')) {
            $kind = 'technical';
        }

        if ($kind === null) {
            continue;
        }

        $key = implode('|', [
            (string)($item['created_at'] ?? ''),
            (string)($item['amount'] ?? 0),
        ]);
        $groups[$key][$kind][] = $index;
    }

    $dropIndexes = [];
    foreach ($groups as $group) {
        $financial = $group['financial'] ?? [];
        $technical = $group['technical'] ?? [];
        $pairs = min(count($financial), count($technical));
        for ($i = 0; $i < $pairs; $i++) {
            $dropIndexes[$technical[$i]] = true;
        }
    }

    $normalized = [];
    foreach ($operations as $index => $item) {
        if (!isset($dropIndexes[$index])) {
            $normalized[] = $item;
        }
    }

    // Older cached Mini App clients used created_at + amount to hide the old
    // duplicate pair. Keep two genuine same-second/same-amount shop orders
    // distinct for those clients by adding response-only milliseconds.
    $seenShopKeys = [];
    foreach ($normalized as &$item) {
        if (!is_array($item)) {
            continue;
        }

        if ((string)($item['title'] ?? '') === 'Операция баланса'
            && (string)($item['description'] ?? '') === 'Первые коины в Матч-комнате') {
            $item['title'] = 'Стартовый бонус';
        }

        if ((string)($item['title'] ?? '') !== 'Заказ приза') {
            continue;
        }

        $createdAt = (string)($item['created_at'] ?? '');
        $key = implode('|', [
            $createdAt,
            (string)($item['amount'] ?? 0),
            (string)($item['title'] ?? ''),
        ]);

        $occurrence = (int)($seenShopKeys[$key] ?? 0);
        $seenShopKeys[$key] = $occurrence + 1;

        if ($occurrence > 0
            && preg_match('/^(.*:\d{2})(Z|[+-]\d{2}:\d{2})$/', $createdAt, $matches)) {
            $milliseconds = min(999, $occurrence);
            $item['created_at'] = $matches[1] . sprintf('.%03d', $milliseconds) . $matches[2];
        }
    }
    unset($item);

    $data['history']['operations'] = $normalized;
    return $data;
}

function mgw_run_api_success_hooks(): void {
    $hooks = [];
    $legacyHook = $GLOBALS['mgw_api_success_hook'] ?? null;
    if (is_callable($legacyHook)) $hooks[] = $legacyHook;

    $configuredHooks = $GLOBALS['mgw_api_success_hooks'] ?? [];
    if (is_array($configuredHooks)) {
        foreach ($configuredHooks as $hook) {
            if (is_callable($hook)) $hooks[] = $hook;
        }
    }

    unset($GLOBALS['mgw_api_success_hook'], $GLOBALS['mgw_api_success_hooks']);
    foreach ($hooks as $hook) $hook();
}

function mgw_public_api_error(string $message): string {
    $message = trim($message);
    if ($message === '') {
        return 'Не удалось выполнить действие. Попробуйте ещё раз.';
    }

    $technical = preg_match(
        '/(?:Runtime module|projection|parity|DB-primary|database fingerprint|state fingerprint|Production atomic|SQLSTATE|PDO|stack trace|internal contract)/i',
        $message
    ) === 1;

    if (!$technical) {
        return $message;
    }

    $incident = substr(hash('sha256', $message . '|' . microtime(true)), 0, 10);
    error_log('[MiniGamesWorld API ' . $incident . '] ' . $message);

    return 'Не удалось загрузить данные. Закройте и снова откройте приложение.';
}

function api_ok(array $data = []): void {
    mgw_run_api_success_hooks();
    json_response(['ok' => true] + mgw_normalize_api_data($data));
}

function api_error(string $message, int $status = 400): void {
    json_response(['ok' => false, 'error' => mgw_public_api_error($message)], $status);
}
