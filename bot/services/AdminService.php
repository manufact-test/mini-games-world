<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';
require_once __DIR__ . '/../runtime/UnifiedGameZonePolicy.php';

final class AdminService
{
    public function __construct(private array $config) {}

    public function isAdmin(int|string $telegramId): bool
    {
        $id = (string)$telegramId;
        foreach (($this->config['admin_ids'] ?? []) as $adminId) {
            if ((string)$adminId === $id) {
                return true;
            }
        }
        return false;
    }

    public function keyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📊 Обзор', 'callback_data' => 'admin:dashboard'],
                    ['text' => '🎁 Заявки', 'callback_data' => 'admin:orders'],
                ],
                [
                    ['text' => '📩 Обращения', 'callback_data' => 'admin:support'],
                    ['text' => '👥 Пользователи', 'callback_data' => 'admin:users'],
                ],
                [
                    ['text' => '🔎 Найти игрока', 'callback_data' => 'admin:user_search_help'],
                    ['text' => '💳 Платежи', 'callback_data' => 'admin:payments'],
                ],
                [
                    ['text' => '🧹 Убрать payout_done warning', 'callback_data' => 'admin:fix_payout_done'],
                ],
                [
                    ['text' => '🧪 Проверка', 'callback_data' => 'admin:system_check'],
                    ['text' => '🔄 Обновить', 'callback_data' => 'admin:dashboard'],
                ],
            ],
        ];
    }

    public function dashboard(array $db): string
    {
        $stats = (new StatsService())->build($db);
        $orders = $this->orderSummary($db);
        $support = $this->supportSummary($db);
        $paymentSummary = (new PaymentService($this->config, new UserService($this->config)))->adminSummary($db);
        $online = (int)($stats['online_players'] ?? 0);
        $activeGames = (int)($stats['active_games'] ?? 0);
        $userCount = count($db['users'] ?? []);

        return "🛡 Mini Games World — админ-панель\n\n"
            . "👥 Пользователей: {$userCount}\n"
            . "🟢 Онлайн: {$online}\n"
            . "🎮 Активных матчей: {$activeGames}\n"
            . "🎁 Заявок ожидают: {$orders['waiting']}\n"
            . "💳 Платежей ожидают: {$paymentSummary['waiting']}\n"
            . "📩 Обращений открыто: {$support['open']}\n\n"
            . "Выберите раздел ниже.";
    }

    public function users(array $db, int $limit = 20): string
    {
        $users = array_values(array_filter(
            array_reverse($db['users'] ?? [], true),
            static fn(mixed $user): bool => is_array($user) && empty($user['is_dev_user'])
        ));
        $users = array_slice($users, 0, max(1, $limit));

        $lines = ["👥 Пользователи"];
        if (!$users) {
            $lines[] = "\nПользователей пока нет.";
            return implode("\n", $lines);
        }

        foreach ($users as $user) {
            $name = $this->userDisplayName($user);
            $id = (string)($user['id'] ?? '');
            $balance = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
            $lines[] = "\n{$name}\nID: {$id}\nБаланс: {$balance}";
        }
        return implode("\n", $lines);
    }

    public function findUser(array $db, string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return "🔎 Поиск игрока\n\nВведите ID или @username после команды поиска.";
        }
        $user = $this->findUserRecord($db, $query);
        if ($user === null) return "🔎 Игрок не найден: {$query}";

        return "👤 " . $this->userDisplayName($user) . "\n"
            . "ID: " . (string)($user['id'] ?? '') . "\n"
            . "Баланс: " . (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0) . "\n"
            . "Статус: " . (string)($user['status'] ?? 'idle');
    }

    public function orders(array $db, int $limit = 20): string
    {
        $orders = array_values(array_filter(
            array_reverse($db['shop_orders'] ?? []),
            static fn(mixed $order): bool => is_array($order)
        ));
        $summary = $this->orderSummary($db);
        $lines = ["🎁 Заявки на призы (архив)"];
        $lines[] = "Всего: {$summary['total']} · ожидают: {$summary['waiting']}";
        foreach (array_slice($orders, 0, max(1, $limit)) as $order) {
            $lines[] = "\n" . $this->orderCard($order);
        }
        if (!$orders) $lines[] = "\nЗаявок пока нет.";
        return implode("\n", $lines);
    }

    public function completeOrder(array &$db, string $argument, string $adminId): string
    {
        return UnifiedGameZonePolicy::legacyArchiveMessage();
    }

    public function rejectOrder(array &$db, string $argument, string $adminId): string
    {
        return UnifiedGameZonePolicy::legacyArchiveMessage();
    }

    public function support(array $db, int $limit = 20): string
    {
        $items = array_values(array_filter(
            array_reverse($db['support'] ?? []),
            static fn(mixed $item): bool => is_array($item)
        ));
        $lines = ["📩 Обращения"];
        foreach (array_slice($items, 0, max(1, $limit)) as $item) {
            $lines[] = "\n" . $this->supportCard($item);
        }
        if (!$items) $lines[] = "\nОбращений пока нет.";
        return implode("\n", $lines);
    }

    public function applyPayment(array &$db, string $argument, string $adminId): string
    {
        return UnifiedGameZonePolicy::legacyArchiveMessage();
    }

    public function rejectPayment(array &$db, string $argument, string $adminId): string
    {
        return UnifiedGameZonePolicy::legacyArchiveMessage();
    }

    public function goldTools(array $db): string
    {
        return UnifiedGameZonePolicy::legacyArchiveMessage();
    }

    public function addGoldToUser(array &$db, string $argument, string $adminId): string
    {
        return UnifiedGameZonePolicy::legacyArchiveMessage();
    }

    public function systemCheck(array $db): string
    {
        $stats = (new StatsService())->build($db);
        return "🧪 Проверка системы\n\n"
            . "Онлайн: " . (int)($stats['online_players'] ?? 0) . "\n"
            . "Активных матчей: " . (int)($stats['active_games'] ?? 0) . "\n"
            . "Пользователей: " . count($db['users'] ?? []) . "\n"
            . "Игровых записей: " . count($db['games'] ?? []);
    }

    public function fixPayoutDoneWarning(array &$db): string
    {
        $fixed = 0;
        foreach ($db['games'] ?? [] as &$game) {
            if (!is_array($game)) continue;
            if (($game['status'] ?? '') !== 'finished') continue;
            if (!array_key_exists('payout_done', $game)) {
                $game['payout_done'] = true;
                $fixed++;
            }
        }
        unset($game);
        return "🧹 Проверено. Добавлено payout_done: {$fixed}.";
    }

    private function orderSummary(array $db): array
    {
        $total = 0;
        $waiting = 0;
        foreach ($db['shop_orders'] ?? [] as $order) {
            if (!is_array($order)) continue;
            $total++;
            if (in_array((string)($order['status'] ?? ''), ['pending', 'draft'], true)) $waiting++;
        }
        return ['total' => $total, 'waiting' => $waiting];
    }

    private function supportSummary(array $db): array
    {
        $open = 0;
        foreach ($db['support'] ?? [] as $item) {
            if (!is_array($item)) continue;
            if (!in_array((string)($item['status'] ?? 'open'), ['closed', 'done'], true)) $open++;
        }
        return ['open' => $open];
    }

    private function findUserRecord(array $db, string $query): ?array
    {
        $needle = strtolower(ltrim(trim($query), '@'));
        foreach ($db['users'] ?? [] as $user) {
            if (!is_array($user)) continue;
            $id = strtolower((string)($user['id'] ?? ''));
            $username = strtolower((string)($user['username'] ?? ''));
            if ($needle === $id || ($username !== '' && $needle === $username)) return $user;
        }
        return null;
    }

    private function userDisplayName(array $user): string
    {
        $username = trim((string)($user['username'] ?? ''));
        if ($username !== '') return '@' . ltrim($username, '@');
        return trim((string)($user['first_name'] ?? $user['display_name'] ?? 'Игрок')) ?: 'Игрок';
    }

    private function orderCard(array $order): string
    {
        $id = strtoupper(substr(preg_replace('/^shop_/', '', (string)($order['id'] ?? '')), 0, 8));
        return "#{$id} · " . (string)($order['status'] ?? 'unknown')
            . " · " . (int)($order['amount'] ?? 0) . " коинов";
    }

    private function supportCard(array $item): string
    {
        $id = strtoupper(substr((string)($item['id'] ?? ''), 0, 8));
        $type = (string)($item['type'] ?? 'support');
        $text = trim((string)($item['message'] ?? $item['text'] ?? ''));
        return "#{$id} · {$type}\n" . mb_substr($text, 0, 220);
    }
}