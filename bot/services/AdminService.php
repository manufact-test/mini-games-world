<?php
declare(strict_types=1);

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
                    ['text' => '🪙 Gold-тест', 'callback_data' => 'admin:gold_tools'],
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
        $users = array_values($db['users'] ?? []);
        $realUsers = array_values(array_filter($users, fn($user) => !$this->isDevUser($user)));
        $devUsersCount = count($users) - count($realUsers);

        $activeGames = 0;
        $finishedGames = 0;
        $botGames = 0;
        foreach ($db['games'] ?? [] as $game) {
            if (($game['status'] ?? '') === 'active') $activeGames++;
            if (($game['status'] ?? '') === 'finished') $finishedGames++;
            if (!empty($game['is_bot_game'])) $botGames++;
        }

        $queueCount = count($db['queue'] ?? []);
        $supportCount = count($db['support'] ?? []);
        $operationsCount = count($db['transactions'] ?? []);
        $paymentsCount = count($db['payments'] ?? []);
        $orders = $db['shop_orders'] ?? [];
        $pendingOrders = 0;
        foreach ($orders as $order) {
            if (($order['status'] ?? 'pending') === 'pending') $pendingOrders++;
        }

        $feesMatch = (int)($db['system']['fees_match'] ?? 0);
        $feesGold = (int)($db['system']['fees_gold'] ?? 0);
        $feesTotal = $feesMatch + $feesGold;

        $text = "🛠 Панель Mini Games World\n\n";
        $text .= "📊 Обзор\n";
        $text .= "Пользователей: " . count($realUsers) . "\n";
        if ($devUsersCount > 0) $text .= "Dev-записей: {$devUsersCount}\n";
        $text .= "Активных игр: {$activeGames}\n";
        $text .= "Завершённых игр: {$finishedGames}\n";
        $text .= "Матчей с ботами: {$botGames}\n";
        $text .= "Операций в истории: {$operationsCount}\n";
        $text .= "Платежных записей: {$paymentsCount}\n";
        $text .= "В очереди: {$queueCount}\n\n";

        $text .= "💰 Казна клуба\n";
        $text .= "Match-комната: {$feesMatch} коинов\n";
        $text .= "Gold-комната: {$feesGold} коинов\n";
        $text .= "Всего комиссий: {$feesTotal} коинов\n\n";

        $text .= "🎁 Магазин\n";
        $text .= "Заявки ожидают: {$pendingOrders}\n\n";

        $text .= "📩 Обратная связь\n";
        $text .= "Обращений всего: {$supportCount}\n\n";

        $text .= "🎮 Последние матчи\n";
        $text .= $this->latestGamesBlock($db, 5) . "\n\n";

        $text .= "🧾 Последние операции\n";
        $text .= $this->latestTransactionsBlock($db, 5) . "\n\n";

        $text .= "Команды:\n";
        $text .= ($this->config['admin_command'] ?? '/mgw_private_admin_7291') . " — открыть панель\n";
        $text .= ($this->config['admin_orders_command'] ?? '/mgw_private_admin_7291_orders') . " — заявки\n";
        $text .= ($this->config['admin_order_command'] ?? '/mgw_private_admin_7291_order') . " ABC123 — карточка заявки\n";
        $text .= ($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done') . " ABC123 — отметить выполненной\n";
        $text .= ($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject') . " ABC123 причина — отклонить и вернуть коины\n";
        $text .= ($this->config['admin_payments_command'] ?? '/mgw_private_admin_7291_payments') . " — платежи\n";
        $text .= ($this->config['admin_gold_tools_command'] ?? '/mgw_private_admin_7291_gold') . " — Gold-тест\n";
        $text .= ($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add') . " @username 100 причина — начислить Gold\n";
        $text .= ($this->config['admin_support_command'] ?? '/mgw_private_admin_7291_support') . " — обращения\n";
        $text .= ($this->config['admin_users_command'] ?? '/mgw_private_admin_7291_users') . " — обзор пользователей\n";
        $text .= ($this->config['admin_user_command'] ?? '/mgw_private_admin_7291_user') . " @username — карточка игрока\n";
        $text .= ($this->config['admin_check_command'] ?? '/mgw_private_admin_7291_check') . " — проверка системы\n";
        $text .= ($this->config['admin_fix_payout_done_command'] ?? '/mgw_private_admin_7291_fix_payout_done') . " — убрать старое предупреждение payout_done";

        return $text;
    }

    public function systemCheck(array $db): string
    {
        $issues = [];
        $warnings = [];

        $files = [
            'users' => $db['users'] ?? null,
            'games' => $db['games'] ?? null,
            'queue' => $db['queue'] ?? null,
            'transactions' => $db['transactions'] ?? null,
            'support' => $db['support'] ?? null,
            'shop_orders' => $db['shop_orders'] ?? null,
            'payments' => $db['payments'] ?? null,
            'system' => $db['system'] ?? null,
        ];

        foreach ($files as $name => $value) {
            if ($value === null) $issues[] = "нет данных {$name}.json";
        }

        $users = $db['users'] ?? [];
        $games = $db['games'] ?? [];
        $queue = $db['queue'] ?? [];
        $transactions = $db['transactions'] ?? [];
        $payments = $db['payments'] ?? [];
        $system = $db['system'] ?? [];

        $realUsers = 0;
        $devUsers = 0;
        $goldBalanceTotal = 0;
        $goldDepositedTotal = 0;
        $negativeBalances = 0;
        $playingWithoutGame = 0;
        $searchingWithoutQueue = 0;

        $queueUserIds = [];
        foreach ($queue as $queueItem) {
            if (isset($queueItem['user_id'])) $queueUserIds[(string)$queueItem['user_id']] = true;
        }

        foreach ($users as $user) {
            if ($this->isDevUser($user)) $devUsers++;
            else $realUsers++;

            $goldBalanceTotal += (int)($user['balance_gold'] ?? 0);
            $goldDepositedTotal += (int)($user['gold_deposited_total'] ?? 0);

            if ((int)($user['balance_match'] ?? 0) < 0 || (int)($user['balance_gold'] ?? 0) < 0) $negativeBalances++;

            $status = (string)($user['status'] ?? 'idle');
            if ($status === 'playing') {
                $currentGameId = (string)($user['current_game_id'] ?? '');
                if ($currentGameId === '' || !isset($games[$currentGameId]) || ($games[$currentGameId]['status'] ?? '') !== 'active') {
                    $playingWithoutGame++;
                }
            }
            if ($status === 'searching') {
                $userId = (string)($user['id'] ?? '');
                if ($userId !== '' && !isset($queueUserIds[$userId])) $searchingWithoutQueue++;
            }
        }

        if ($negativeBalances > 0) $issues[] = "отрицательные балансы у пользователей: {$negativeBalances}";
        if ($playingWithoutGame > 0) $issues[] = "статус playing без активной игры: {$playingWithoutGame}";
        if ($searchingWithoutQueue > 0) $warnings[] = "статус searching без очереди: {$searchingWithoutQueue}";

        $activeGames = 0;
        $finishedGames = 0;
        $unfinishedWithoutPlayers = 0;
        $finishedWithoutPayoutFlag = 0;

        foreach ($games as $game) {
            $status = (string)($game['status'] ?? '');
            if ($status === 'active') $activeGames++;
            if ($status === 'finished') {
                $finishedGames++;
                if (($game['payout_done'] ?? false) !== true) $finishedWithoutPayoutFlag++;
            }

            $players = $game['player_ids'] ?? [];
            if (!is_array($players) || count($players) < 2) $unfinishedWithoutPlayers++;
        }

        if ($unfinishedWithoutPlayers > 0) $warnings[] = "игры с неполным списком игроков: {$unfinishedWithoutPlayers}";
        if ($finishedWithoutPayoutFlag > 0) {
            $warnings[] = "старые finished-игры без payout_done: {$finishedWithoutPayoutFlag}";
            $warnings[] = "нажмите кнопку 🧹 Убрать payout_done warning или отправьте команду " . ($this->config['admin_fix_payout_done_command'] ?? '/mgw_private_admin_7291_fix_payout_done');
        }

        $queueWithoutUser = 0;
        foreach ($queue as $item) {
            $userId = (string)($item['user_id'] ?? '');
            if ($userId === '' || !isset($users[$userId])) $queueWithoutUser++;
        }
        if ($queueWithoutUser > 0) $issues[] = "записи очереди без пользователя: {$queueWithoutUser}";

        $badTransactions = 0;
        foreach ($transactions as $tx) {
            if (!isset($tx['id']) || !isset($tx['created_at'])) $badTransactions++;
        }
        if ($badTransactions > 0) $warnings[] = "операции без id/даты: {$badTransactions}";

        $feesMatch = (int)($system['fees_match'] ?? 0);
        $feesGold = (int)($system['fees_gold'] ?? 0);

        $lines = ["🧪 Проверка системы"];
        $lines[] = "\nСтруктура: OK";
        $lines[] = "Пользователей: {$realUsers}";
        if ($devUsers > 0) $lines[] = "Dev-записей: {$devUsers}";
        $lines[] = "Активных игр: {$activeGames}";
        $lines[] = "Завершённых игр: {$finishedGames}";
        $lines[] = "В очереди: " . count($queue);
        $lines[] = "Операций: " . count($transactions);
        $lines[] = "Платежных записей: " . count($payments);
        $lines[] = "Казна Match: {$feesMatch} коинов";
        $lines[] = "Казна Gold: {$feesGold} коинов";
        $lines[] = "Gold на балансах игроков: {$goldBalanceTotal} коинов";
        $lines[] = "Gold начислено/куплено всего: {$goldDepositedTotal} коинов";

        $lines[] = "\nОшибки:";
        if ($issues) foreach (array_slice($issues, 0, 10) as $issue) $lines[] = "❌ {$issue}";
        else $lines[] = "✅ критичных ошибок не найдено";

        $lines[] = "\nПредупреждения:";
        if ($warnings) foreach (array_slice($warnings, 0, 10) as $warning) $lines[] = "⚠️ {$warning}";
        else $lines[] = "✅ предупреждений нет";

        $lines[] = "\nКоманда:";
        $lines[] = ($this->config['admin_check_command'] ?? '/mgw_private_admin_7291_check');

        return implode("\n", $lines);
    }

    public function fixLegacyPayoutDone(array &$db, string $adminId): string
    {
        $fixed = 0;
        $alreadyOk = 0;
        $skipped = 0;
        $remaining = 0;
        $now = now_iso();

        if (!isset($db['games']) || !is_array($db['games'])) {
            $db['games'] = [];
        }

        foreach ($db['games'] as $gameId => &$game) {
            if (!is_array($game)) {
                $skipped++;
                continue;
            }

            if (($game['status'] ?? '') !== 'finished') {
                $skipped++;
                continue;
            }

            if (($game['payout_done'] ?? false) === true) {
                $alreadyOk++;
                continue;
            }

            // Это старые завершённые игры, где payout_done отсутствует или равен false.
            // Денежные расчёты не повторяем. Только ставим технический флаг,
            // чтобы система больше не считала эти матчи проблемой.
            $game['payout_done'] = true;
            $game['payout_migrated'] = true;
            $game['payout_migrated_at'] = $now;
            $game['payout_migrated_by'] = $adminId;

            $fixed++;
        }
        unset($game);

        // Контрольный пересчёт после изменения того же массива.
        foreach ($db['games'] as $game) {
            if (!is_array($game)) {
                continue;
            }

            if (($game['status'] ?? '') === 'finished' && (($game['payout_done'] ?? false) !== true)) {
                $remaining++;
            }
        }

        $db['system']['migrations']['mvp6_1_fix_legacy_payout_done'] = [
            'ran_at' => $now,
            'admin_id' => $adminId,
            'fixed_games' => $fixed,
            'already_ok_games' => $alreadyOk,
            'remaining_without_payout_done' => $remaining,
        ];

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'system_migration',
            'category' => 'system_migration',
            'amount' => 0,
            'description' => 'MVP-6.1: старым finished-играм добавлен payout_done=true без повторных начислений',
            'fixed_games' => $fixed,
            'remaining_without_payout_done' => $remaining,
            'admin_id' => $adminId,
            'created_at' => $now,
        ];

        $lines = ["🧹 MVP-6.1: payout_done cleanup"];
        $lines[] = "
Готово.";
        $lines[] = "Исправлено старых finished-игр с payout_done не true: {$fixed}";
        $lines[] = "Уже были в порядке: {$alreadyOk}";
        $lines[] = "Активные/другие игры пропущены: {$skipped}";
        $lines[] = "Осталось без payout_done=true: {$remaining}";

        if ($fixed > 0) {
            $lines[] = "
Что сделано:";
            $lines[] = "• добавлен payout_done=true";
            $lines[] = "• добавлен payout_migrated=true";
            $lines[] = "• выплаты НЕ пересчитывались";
            $lines[] = "• балансы НЕ менялись";
        } else {
            $lines[] = "
Нечего исправлять: старых игр без payout_done уже нет.";
        }

        if ($remaining > 0) {
            $lines[] = "
⚠️ Остаток всё ещё есть. Значит, games.json имеет нестандартную структуру — тогда нужно будет смотреть файл.";
        }

        $lines[] = "
Теперь нажмите:";
        $lines[] = ($this->config['admin_check_command'] ?? '/mgw_private_admin_7291_check');

        return implode("
", $lines);
    }

    public function orders(array $db): string
    {
        $orders = $db['shop_orders'] ?? [];
        if (!$orders) {
            return "🎁 Заявки магазина\n\nЗаявок пока нет.\n\nКоманда: "
                . ($this->config['admin_orders_command'] ?? '/mgw_private_admin_7291_orders');
        }

        $pending = [];
        $done = [];
        $rejected = [];
        $other = [];

        foreach ($orders as $order) {
            $status = (string)($order['status'] ?? 'pending');
            if ($status === 'pending') $pending[] = $order;
            elseif ($status === 'done') $done[] = $order;
            elseif ($status === 'rejected') $rejected[] = $order;
            else $other[] = $order;
        }

        $sortDesc = fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        usort($pending, $sortDesc);
        usort($done, $sortDesc);
        usort($rejected, $sortDesc);
        usort($other, $sortDesc);

        $lines = ["🎁 Заявки магазина"];
        $lines[] = "\nОжидают: " . count($pending);
        $lines[] = "Выполнено: " . count($done);
        $lines[] = "Отклонено: " . count($rejected);

        if ($pending) {
            $lines[] = "\n⏳ Ожидают обработки";
            foreach (array_slice($pending, 0, 10) as $order) $lines[] = $this->orderListLine($order);
        } else {
            $lines[] = "\n⏳ Ожидают обработки\nНет ожидающих заявок.";
        }

        $recentProcessed = array_merge($done, $rejected, $other);
        usort($recentProcessed, fn($a, $b) => strcmp((string)(($b['updated_at'] ?? '') ?: ($b['created_at'] ?? '')), (string)(($a['updated_at'] ?? '') ?: ($a['created_at'] ?? ''))));

        if ($recentProcessed) {
            $lines[] = "\n📦 Последние обработанные";
            foreach (array_slice($recentProcessed, 0, 5) as $order) $lines[] = $this->orderListLine($order);
        }

        $orderCmd = $this->config['admin_order_command'] ?? '/mgw_private_admin_7291_order';
        $doneCmd = $this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done';
        $rejectCmd = $this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject';

        $lines[] = "\nКоманды:";
        $lines[] = "{$orderCmd} ABC123 — открыть заявку";
        $lines[] = "{$doneCmd} ABC123 — отметить выполненной";
        $lines[] = "{$rejectCmd} ABC123 причина — отклонить и вернуть коины";

        return implode("\n", $lines);
    }

    public function orderDetails(array $db, string $query): string
    {
        $query = trim($query);
        if ($query === '') return $this->orders($db);

        $index = $this->findOrderIndex($db, $query);
        if ($index === null) {
            return "🎁 Карточка заявки\n\nЗаявка не найдена: {$query}\n\nОткройте список заявок:\n"
                . ($this->config['admin_orders_command'] ?? '/mgw_private_admin_7291_orders');
        }

        return $this->orderDetailsText($db, $db['shop_orders'][$index]);
    }

    public function completeOrder(array &$db, string $argument, string $adminId): string
    {
        [$query, $note] = $this->parseOrderActionArgument($argument);
        if ($query === '') {
            return "✅ Выполнить заявку\n\nУкажите ID заявки.\n\nПример:\n"
                . ($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done') . " ABC123";
        }

        $index = $this->findOrderIndex($db, $query);
        if ($index === null) return "✅ Выполнить заявку\n\nЗаявка не найдена: {$query}";

        $order =& $db['shop_orders'][$index];
        $status = (string)($order['status'] ?? 'pending');

        if ($status === 'done') return "✅ Заявка уже была отмечена выполненной.\n\n" . $this->orderDetailsText($db, $order);
        if ($status === 'rejected') return "⚠️ Заявка уже отклонена. Выполненной её отметить нельзя.\n\n" . $this->orderDetailsText($db, $order);
        if ($status !== 'pending') return "⚠️ Заявка имеет статус: " . $this->orderStatusLabel($status) . ". Действие остановлено.";

        $now = now_iso();
        $order['status'] = 'done';
        $order['updated_at'] = $now;
        $order['completed_at'] = $now;
        $order['completed_by'] = $adminId;
        if ($note !== '') $order['admin_note'] = $note;

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'shop_order_done',
            'category' => 'shop_order_done',
            'order_id' => (string)($order['id'] ?? ''),
            'user_id' => (string)($order['user_id'] ?? ''),
            'username' => (string)($order['username'] ?? ''),
            'room' => 'gold',
            'provider' => (string)($order['provider'] ?? ''),
            'amount' => 0,
            'description' => 'Заявка магазина выполнена',
            'admin_id' => $adminId,
            'created_at' => $now,
        ];

        return "✅ Заявка отмечена выполненной.\n\n" . $this->orderDetailsText($db, $order);
    }

    public function rejectOrder(array &$db, string $argument, string $adminId): string
    {
        [$query, $note] = $this->parseOrderActionArgument($argument);
        if ($query === '') {
            return "🚫 Отклонить заявку\n\nУкажите ID заявки.\n\nПример:\n"
                . ($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject') . " ABC123 причина";
        }

        $index = $this->findOrderIndex($db, $query);
        if ($index === null) return "🚫 Отклонить заявку\n\nЗаявка не найдена: {$query}";

        $order =& $db['shop_orders'][$index];
        $status = (string)($order['status'] ?? 'pending');

        if ($status === 'rejected') return "🚫 Заявка уже отклонена.\n\n" . $this->orderDetailsText($db, $order);
        if ($status === 'done') return "⚠️ Заявка уже выполнена. Отклонить её нельзя, чтобы случайно не сделать неверный возврат.\n\n" . $this->orderDetailsText($db, $order);
        if ($status !== 'pending') return "⚠️ Заявка имеет статус: " . $this->orderStatusLabel($status) . ". Действие остановлено.";

        $userId = (string)($order['user_id'] ?? '');
        if ($userId === '' || !isset($db['users'][$userId])) {
            return "⚠️ Пользователь заявки не найден. Возврат невозможен, статус не изменён.";
        }

        $amount = abs((int)($order['amount'] ?? 0));
        $now = now_iso();
        $user =& $db['users'][$userId];

        if (empty($order['refund_done'])) {
            $user['balance_gold'] = (int)($user['balance_gold'] ?? 0) + $amount;
            $user['gold_shop_spent_total'] = max(0, (int)($user['gold_shop_spent_total'] ?? 0) - $amount);

            $order['refund_done'] = true;
            $order['refund_amount'] = $amount;
            $order['refunded_at'] = $now;

            $db['transactions'][] = [
                'id' => make_id('tx'),
                'type' => 'balance_change',
                'category' => 'shop_refund',
                'order_id' => (string)($order['id'] ?? ''),
                'user_id' => $userId,
                'username' => (string)($user['username'] ?? ''),
                'room' => 'gold',
                'provider' => (string)($order['provider'] ?? ''),
                'amount' => $amount,
                'balance_after' => (int)($user['balance_gold'] ?? 0),
                'description' => 'Возврат за отклонённую заявку магазина',
                'admin_id' => $adminId,
                'created_at' => $now,
            ];
        }

        $order['status'] = 'rejected';
        $order['updated_at'] = $now;
        $order['rejected_at'] = $now;
        $order['rejected_by'] = $adminId;
        if ($note !== '') $order['admin_note'] = $note;

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'shop_order_reject',
            'category' => 'shop_order_reject',
            'order_id' => (string)($order['id'] ?? ''),
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'room' => 'gold',
            'provider' => (string)($order['provider'] ?? ''),
            'amount' => 0,
            'description' => 'Заявка магазина отклонена',
            'admin_id' => $adminId,
            'created_at' => $now,
        ];

        return "🚫 Заявка отклонена. Коины возвращены игроку.\n\n" . $this->orderDetailsText($db, $order);
    }

    public function payments(array $db): string
    {
        $paymentService = new PaymentService($this->config, new UserService($this->config));
        return $paymentService->adminList($db);
    }

    public function paymentDetails(array $db, string $argument): string
    {
        $paymentService = new PaymentService($this->config, new UserService($this->config));
        return $paymentService->adminDetails($db, $argument);
    }

    public function applyPayment(array &$db, string $argument, string $adminId): string
    {
        $paymentService = new PaymentService($this->config, new UserService($this->config));
        return $paymentService->adminApply($db, $argument, $adminId);
    }

    public function rejectPayment(array &$db, string $argument, string $adminId): string
    {
        $paymentService = new PaymentService($this->config, new UserService($this->config));
        return $paymentService->adminReject($db, $argument, $adminId);
    }

    public function goldTools(array $db): string
    {
        $users = array_values(array_filter($db['users'] ?? [], fn($user) => !$this->isDevUser($user)));
        usort($users, fn($a, $b) => (int)($b['balance_gold'] ?? 0) <=> (int)($a['balance_gold'] ?? 0));

        $addCommand = $this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add';

        $lines = ["🪙 Gold-тест"];
        $lines[] = "\nЭто тестовое админское начисление Gold-коинов.";
        $lines[] = "Реальная оплата не подключена.";
        $lines[] = "Каждое начисление записывается в историю операций.";
        $lines[] = "\nКоманда:";
        $lines[] = "{$addCommand} @username 100 причина";
        $lines[] = "{$addCommand} 123456789 100 причина";
        $lines[] = "\nПример:";
        $lines[] = "{$addCommand} @SlojniyTip 100 тестовое пополнение";

        if ($users) {
            $lines[] = "\nИгроки с Gold-балансом:";
            $shown = 0;
            foreach ($users as $user) {
                $gold = (int)($user['balance_gold'] ?? 0);
                $deposited = (int)($user['gold_deposited_total'] ?? 0);
                if ($gold <= 0 && $deposited <= 0 && $shown >= 5) continue;

                $lines[] = $this->userLabel($user)
                    . " · ID " . (string)($user['id'] ?? '-')
                    . " · Gold " . $gold
                    . " · начислено/куплено всего " . $deposited;
                $shown++;

                if ($shown >= 8) break;
            }
        }

        return implode("\n", $lines);
    }

    public function addGoldToUser(array &$db, string $argument, string $adminId): string
    {
        [$query, $amount, $reason] = $this->parseGoldAddArgument($argument);

        if ($query === '' || $amount <= 0) {
            return "🪙 Начислить Gold\n\nФормат команды:\n"
                . ($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add')
                . " @username 100 причина\n\n"
                . "Пример:\n"
                . ($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add')
                . " @SlojniyTip 100 тестовое пополнение";
        }

        if ($amount > 100000) {
            return "⚠️ Слишком большая сумма. Максимум для одного тестового начисления: 100000 коинов.";
        }

        $user = $this->findUser($db, $query);
        if (!$user) return "🪙 Начислить Gold\n\nПользователь не найден: {$query}";

        $userId = (string)($user['id'] ?? '');
        if ($userId === '' || !isset($db['users'][$userId])) {
            return "⚠️ Пользователь найден, но его ID отсутствует в users.json. Начисление остановлено.";
        }

        $reason = $reason !== '' ? $reason : 'тестовое админское начисление';

        $before = (int)($db['users'][$userId]['balance_gold'] ?? 0);
        $depositedBefore = (int)($db['users'][$userId]['gold_deposited_total'] ?? 0);

        $db['users'][$userId]['balance_gold'] = $before + $amount;
        $db['users'][$userId]['gold_deposited_total'] = $depositedBefore + $amount;
        $db['users'][$userId]['last_gold_topup_at'] = now_iso();

        $txId = make_id('tx');
        $db['transactions'][] = [
            'id' => $txId,
            'type' => 'balance_change',
            'category' => 'admin_gold_topup',
            'user_id' => $userId,
            'username' => (string)($db['users'][$userId]['username'] ?? ''),
            'room' => 'gold',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $before + $amount,
            'gold_deposited_before' => $depositedBefore,
            'gold_deposited_after' => $depositedBefore + $amount,
            'description' => 'Тестовое начисление Gold администратором',
            'reason' => clean_string($reason, 300),
            'admin_id' => $adminId,
            'created_at' => now_iso(),
        ];

        return "✅ Gold начислен\n\n"
            . "Игрок: " . $this->userLabel($db['users'][$userId]) . "\n"
            . "ID: {$userId}\n"
            . "Начислено: {$amount} коинов\n"
            . "Баланс до: {$before}\n"
            . "Баланс после: " . ($before + $amount) . "\n"
            . "Gold начислено/куплено всего: " . ($depositedBefore + $amount) . "\n"
            . "Операция: " . $this->prettyId($txId) . "\n"
            . "Причина: {$reason}";
    }

    public function support(array $db): string
    {
        $items = array_reverse($db['support'] ?? []);
        if (!$items) {
            return "📩 Обращения\n\nОбращений пока нет.\n\nКоманда: "
                . ($this->config['admin_support_command'] ?? '/mgw_private_admin_7291_support');
        }

        $lines = ["📩 Последние обращения"];
        foreach (array_slice($items, 0, 10) as $item) {
            $message = trim((string)($item['message'] ?? ''));
            if (mb_strlen($message) > 350) $message = mb_substr($message, 0, 350) . '…';

            $lines[] =
                "\nОбращение №" . $this->prettyId((string)($item['id'] ?? '-'))
                . "\nПользователь: @" . (($item['username'] ?? '') ?: ($item['user_id'] ?? '-'))
                . "\nID: " . ($item['user_id'] ?? '-')
                . "\nТип: " . ($item['type'] ?? '-')
                . "\nДата: " . ($item['created_at'] ?? '-')
                . "\nТекст: " . $message;
        }

        $lines[] = "\nКоманда: " . ($this->config['admin_support_command'] ?? '/mgw_private_admin_7291_support');
        return implode("\n", $lines);
    }

    public function userSearchHelp(array $db): string
    {
        $users = array_values(array_filter($db['users'] ?? [], fn($user) => !$this->isDevUser($user)));
        usort($users, fn($a, $b) => strcmp((string)($b['last_seen_at'] ?? ''), (string)($a['last_seen_at'] ?? '')));

        $command = $this->config['admin_user_command'] ?? '/mgw_private_admin_7291_user';
        $usersCommand = $this->config['admin_users_command'] ?? '/mgw_private_admin_7291_users';

        $lines = ["🔎 Поиск игрока"];
        $lines[] = "\nЧтобы открыть карточку конкретного игрока, отправьте команду:";
        $lines[] = "{$command} @username";
        $lines[] = "{$command} 123456789";
        $lines[] = "\nТакже работает старый вариант:";
        $lines[] = "{$usersCommand} @username";
        $lines[] = "\nЧто будет в карточке:";
        $lines[] = "• балансы Match и Gold";
        $lines[] = "• статус игрока";
        $lines[] = "• реальная статистика по завершённым матчам";
        $lines[] = "• последние матчи";
        $lines[] = "• последние операции";
        $lines[] = "• технические ID для разбора спорных случаев";

        if ($users) {
            $lines[] = "\nПоследние активные игроки:";
            foreach (array_slice($users, 0, 6) as $user) {
                $label = $this->userLabel($user);
                $id = (string)($user['id'] ?? '-');
                $last = $this->formatDate((string)($user['last_seen_at'] ?? ''));
                $lines[] = "{$label} · ID {$id} · {$last}";
            }
        }

        return implode("\n", $lines);
    }

    public function users(array $db, string $query = ''): string
    {
        $query = trim($query);
        if ($query !== '') return $this->userDetails($db, $query);

        $users = array_values(array_filter($db['users'] ?? [], fn($user) => !$this->isDevUser($user)));
        if (!$users) {
            return "👥 Пользователи\n\nПользователей пока нет.\n\nКоманда: "
                . ($this->config['admin_users_command'] ?? '/mgw_private_admin_7291_users');
        }

        $today = date('Y-m-d');
        $newToday = 0;
        $activeToday = 0;
        $playing = 0;
        $searching = 0;
        $problems = [];

        foreach ($users as $user) {
            if (str_starts_with((string)($user['registered_at'] ?? ''), $today)) $newToday++;
            if (str_starts_with((string)($user['last_seen_at'] ?? ''), $today)) $activeToday++;

            $status = (string)($user['status'] ?? 'idle');
            if ($status === 'playing') {
                $playing++;
                if (empty($user['current_game_id']) || !isset($db['games'][(string)$user['current_game_id']])) {
                    $problems[] = $this->userLabel($user) . " — статус playing без активной игры";
                }
            }
            if ($status === 'searching') $searching++;
            if ((int)($user['balance_match'] ?? 0) < 0 || (int)($user['balance_gold'] ?? 0) < 0) {
                $problems[] = $this->userLabel($user) . " — отрицательный баланс";
            }
        }

        $calculatedByUser = $this->calculatedStatsByUser($db);

        $top = $users;
        usort($top, function ($a, $b) use ($calculatedByUser) {
            $aId = (string)($a['id'] ?? '');
            $bId = (string)($b['id'] ?? '');
            return (int)($calculatedByUser[$bId]['games_played'] ?? 0) <=> (int)($calculatedByUser[$aId]['games_played'] ?? 0);
        });

        $recent = $users;
        usort($recent, fn($a, $b) => strcmp((string)($b['registered_at'] ?? ''), (string)($a['registered_at'] ?? '')));

        $lines = ["👥 Пользователи"];
        $lines[] = "\nВсего: " . count($users);
        $lines[] = "Новых сегодня: {$newToday}";
        $lines[] = "Активных сегодня: {$activeToday}";
        $lines[] = "Сейчас в игре: {$playing}";
        $lines[] = "В поиске: {$searching}";

        $lines[] = "\n🔥 Самые активные";
        foreach (array_slice($top, 0, 5) as $user) {
            $userId = (string)($user['id'] ?? '');
            $stats = $calculatedByUser[$userId] ?? $this->emptyCalculatedStats();
            $lines[] = $this->userLabel($user)
                . " — " . (int)$stats['games_played'] . " завершённых матчей"
                . ", побед: " . (int)$stats['wins']
                . ", ничьих: " . (int)$stats['draws']
                . "\nБаланс: Match " . (int)($user['balance_match'] ?? 0) . " коинов · Gold " . (int)($user['balance_gold'] ?? 0) . " коинов";
        }

        $lines[] = "\n🆕 Последние новые";
        foreach (array_slice($recent, 0, 5) as $user) {
            $lines[] = $this->userLabel($user) . " — " . $this->formatDate((string)($user['registered_at'] ?? ''));
        }

        $lines[] = "\n⚠️ Возможные проблемы";
        if ($problems) foreach (array_slice($problems, 0, 5) as $problem) $lines[] = $problem;
        else $lines[] = "Проблем не найдено.";

        $cmd = $this->config['admin_users_command'] ?? '/mgw_private_admin_7291_users';
        $lines[] = "\nКарточка игрока:";
        $lines[] = "{$cmd} @username";
        $lines[] = "{$cmd} 123456789";

        return implode("\n", $lines);
    }

    public function userDetails(array $db, string $query): string
    {
        $user = $this->findUser($db, $query);
        if (!$user) {
            $command = $this->config['admin_user_command'] ?? '/mgw_private_admin_7291_user';
            return "👤 Карточка игрока\n\nПользователь не найден: {$query}\n\nИскать можно по:\n• @username\n• Telegram ID\n• имени\n\nПример:\n{$command} @username";
        }

        $id = (string)($user['id'] ?? '');
        $stats = $this->calculatedStatsForUser($db, $id);
        $available = $this->shopAvailableForAdmin($user);
        $winRate = $this->winRate((int)($stats['wins'] ?? 0), (int)($stats['games_played'] ?? 0));

        $currentGameId = (string)($user['current_game_id'] ?? '');
        $currentGameLine = '—';
        if ($currentGameId !== '') {
            $currentGameLine = 'Матч №' . $this->prettyMatchId($currentGameId);
            if (isset($db['games'][$currentGameId])) {
                $currentGameLine .= ' · ' . (($db['games'][$currentGameId]['status'] ?? '') ?: 'status unknown');
            }
        }

        $lines = ["👤 Карточка игрока"];
        $lines[] = "\n" . $this->userLabel($user);
        $lines[] = "ID: {$id}";
        $lines[] = "Имя: " . ((string)($user['first_name'] ?? '') ?: '—');
        $lines[] = "Username: " . ((string)($user['username'] ?? '') ? '@' . ltrim((string)$user['username'], '@') : '—');
        $lines[] = "Статус: " . (string)($user['status'] ?? 'idle');
        $lines[] = "Текущая игра: {$currentGameLine}";
        $lines[] = "Создан: " . $this->formatDate((string)($user['registered_at'] ?? ''));
        $lines[] = "Был в игре: " . $this->formatDate((string)($user['last_seen_at'] ?? ''));

        $lines[] = "\n💰 Балансы";
        $lines[] = "Match-комната: " . (int)($user['balance_match'] ?? 0) . " коинов";
        $lines[] = "Gold-комната: " . (int)($user['balance_gold'] ?? 0) . " коинов";
        $lines[] = "Gold-оборот: " . (int)($user['gold_wagered_total'] ?? 0) . " коинов";
        $lines[] = "Gold начислено/куплено всего: " . (int)($user['gold_deposited_total'] ?? 0) . " коинов";
        $lines[] = "Потрачено в магазине: " . (int)($user['gold_shop_spent_total'] ?? 0) . " коинов";
        $lines[] = "Доступно для магазина: {$available} коинов";

        $lines[] = "\n🎯 Статистика";
        $lines[] = "Завершённых матчей: " . (int)($stats['games_played'] ?? 0);
        $lines[] = "Побед: " . (int)($stats['wins'] ?? 0);
        $lines[] = "Поражений: " . (int)($stats['losses'] ?? 0);
        $lines[] = "Ничьих: " . (int)($stats['draws'] ?? 0);
        $lines[] = "Win-rate: {$winRate}%";
        $lines[] = "Match-матчей: " . (int)($stats['match_games'] ?? 0);
        $lines[] = "Gold-матчей: " . (int)($stats['gold_games'] ?? 0);
        $lines[] = "Матчей с ботом: " . (int)($stats['bot_games'] ?? 0);

        $lines[] = "\n🎮 Последние матчи";
        $lines[] = $this->userGamesBlock($db, $id, 7);

        $lines[] = "\n🧾 Последние операции";
        $lines[] = $this->userTransactionsBlock($db, $id, 7);

        $command = $this->config['admin_user_command'] ?? '/mgw_private_admin_7291_user';
        $lines[] = "\nПовторить поиск:";
        $lines[] = "{$command} @username";
        $lines[] = "{$command} {$id}";

        return implode("\n", $lines);
    }

    private function orderDetailsText(array $db, array $order): string
    {
        $orderId = (string)($order['id'] ?? '');
        $short = $this->prettyId($orderId);
        $status = (string)($order['status'] ?? 'pending');
        $userId = (string)($order['user_id'] ?? '');
        $user = $userId !== '' ? ($db['users'][$userId] ?? null) : null;
        $userLabel = is_array($user) ? $this->userLabel($user) : ('ID ' . ($userId ?: '—'));

        $lines = ["🎁 Карточка заявки"];
        $lines[] = "\nЗаявка №{$short}";
        $lines[] = "Полный ID: {$orderId}";
        $lines[] = "Статус: " . $this->orderStatusLabel($status);
        $lines[] = "Пользователь: {$userLabel}";
        $lines[] = "User ID: " . ($userId ?: '—');
        $lines[] = "Страна: " . ((string)($order['country'] ?? '') ?: '—');
        $lines[] = "Приз: " . ((string)($order['provider'] ?? '') ?: '—');
        $lines[] = "Сумма: " . (int)($order['amount'] ?? 0) . " коинов";
        $lines[] = "Создана: " . $this->formatDate((string)($order['created_at'] ?? ''));
        $lines[] = "Обновлена: " . $this->formatDate((string)($order['updated_at'] ?? ''));

        if (!empty($order['completed_at'])) $lines[] = "Выполнена: " . $this->formatDate((string)$order['completed_at']);
        if (!empty($order['rejected_at'])) $lines[] = "Отклонена: " . $this->formatDate((string)$order['rejected_at']);
        if (!empty($order['refund_done'])) $lines[] = "Возврат: +" . (int)($order['refund_amount'] ?? $order['amount'] ?? 0) . " коинов";
        if (!empty($order['admin_note'])) $lines[] = "Заметка: " . (string)$order['admin_note'];

        if ($status === 'pending') {
            $done = $this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done';
            $reject = $this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject';
            $lines[] = "\nДействия:";
            $lines[] = "{$done} {$short}";
            $lines[] = "{$reject} {$short} причина";
        } else {
            $lines[] = "\nДействия недоступны: заявка уже обработана.";
        }

        return implode("\n", $lines);
    }

    private function orderListLine(array $order): string
    {
        $short = $this->prettyId((string)($order['id'] ?? ''));
        $status = $this->orderStatusLabel((string)($order['status'] ?? 'pending'));
        $username = trim((string)($order['username'] ?? ''));
        $user = $username !== '' ? '@' . ltrim($username, '@') : ('ID ' . (string)($order['user_id'] ?? '-'));
        $provider = (string)($order['provider'] ?? '-');
        $amount = (int)($order['amount'] ?? 0);
        $date = $this->formatDate((string)($order['created_at'] ?? ''));

        return "№{$short} · {$status} · {$user} · {$provider} · {$amount} коинов · {$date}";
    }

    private function findOrderIndex(array $db, string $query): ?int
    {
        $query = trim($query);
        $query = ltrim($query, '#№');
        $queryLower = mb_strtolower($query);

        foreach (($db['shop_orders'] ?? []) as $index => $order) {
            $id = (string)($order['id'] ?? '');
            $short = $this->prettyId($id);

            if ($query === $id
                || mb_strtolower($short) === $queryLower
                || str_starts_with(mb_strtolower($id), $queryLower)) {
                return (int)$index;
            }
        }

        return null;
    }

    private function parseOrderActionArgument(string $argument): array
    {
        $argument = trim($argument);
        if ($argument === '') return ['', ''];

        $parts = preg_split('/\s+/', $argument, 2);
        return [
            trim((string)($parts[0] ?? '')),
            trim((string)($parts[1] ?? '')),
        ];
    }

    private function orderStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'ожидает',
            'done' => 'выполнена',
            'rejected' => 'отклонена',
            default => $status !== '' ? $status : '—',
        };
    }

    private function parseGoldAddArgument(string $argument): array
    {
        $argument = trim($argument);
        if ($argument === '') return ['', 0, ''];

        $parts = preg_split('/\s+/', $argument, 3);

        return [
            trim((string)($parts[0] ?? '')),
            (int)($parts[1] ?? 0),
            trim((string)($parts[2] ?? '')),
        ];
    }

    private function calculatedStatsByUser(array $db): array
    {
        $result = [];

        foreach ($db['users'] ?? [] as $userId => $user) {
            $result[(string)$userId] = $this->emptyCalculatedStats();
        }

        foreach ($db['games'] ?? [] as $game) {
            if (($game['status'] ?? '') !== 'finished') continue;

            $room = ($game['room'] ?? '') === 'gold' ? 'gold' : 'match';
            $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : '';

            foreach (($game['player_ids'] ?? []) as $playerId) {
                $playerId = (string)$playerId;
                if (str_starts_with($playerId, 'bot_')) continue;

                if (!isset($result[$playerId])) $result[$playerId] = $this->emptyCalculatedStats();

                $result[$playerId]['games_played']++;
                if ($room === 'gold') $result[$playerId]['gold_games']++;
                else $result[$playerId]['match_games']++;

                if (!empty($game['is_bot_game'])) $result[$playerId]['bot_games']++;

                if ($winnerId === '') $result[$playerId]['draws']++;
                elseif ($winnerId === $playerId) $result[$playerId]['wins']++;
                else $result[$playerId]['losses']++;
            }
        }

        return $result;
    }

    private function calculatedStatsForUser(array $db, string $userId): array
    {
        $all = $this->calculatedStatsByUser($db);
        return $all[$userId] ?? $this->emptyCalculatedStats();
    }

    private function emptyCalculatedStats(): array
    {
        return [
            'games_played' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'match_games' => 0,
            'gold_games' => 0,
            'bot_games' => 0,
        ];
    }

    private function latestGamesBlock(array $db, int $limit): string
    {
        $lines = [];
        foreach (array_reverse($db['games'] ?? []) as $game) {
            if (($game['status'] ?? '') !== 'finished') continue;
            $lines[] = $this->gameAdminLine($game);
            if (count($lines) >= $limit) break;
        }
        return $lines ? implode("\n", $lines) : "Матчей ещё нет.";
    }

    private function latestTransactionsBlock(array $db, int $limit): string
    {
        $lines = [];
        foreach (array_reverse($db['transactions'] ?? []) as $tx) {
            $lines[] = $this->transactionAdminLine($tx);
            if (count($lines) >= $limit) break;
        }
        return $lines ? implode("\n", $lines) : "Операций ещё нет.";
    }

    private function userGamesBlock(array $db, string $userId, int $limit): string
    {
        $lines = [];
        foreach (array_reverse($db['games'] ?? []) as $game) {
            if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) continue;
            $lines[] = $this->userGameLine($game, $userId);
            if (count($lines) >= $limit) break;
        }
        return $lines ? implode("\n", $lines) : "Матчей нет.";
    }

    private function userTransactionsBlock(array $db, string $userId, int $limit): string
    {
        $lines = [];
        foreach (array_reverse($db['transactions'] ?? []) as $tx) {
            if ((string)($tx['user_id'] ?? '') !== $userId) continue;
            $lines[] = $this->transactionAdminLine($tx);
            if (count($lines) >= $limit) break;
        }
        return $lines ? implode("\n", $lines) : "Операций нет.";
    }

    private function userGameLine(array $game, string $userId): string
    {
        $id = $this->prettyMatchId((string)($game['id'] ?? ''));
        $room = ($game['room'] ?? '') === 'gold' ? 'Gold' : 'Match';
        $bet = (int)($game['bet'] ?? 0);
        $board = (int)($game['board_size'] ?? 0);
        $status = (string)($game['status'] ?? '-');
        $date = $this->formatDate((string)(($game['finished_at'] ?? '') ?: ($game['created_at'] ?? '')));
        $reason = $this->reasonLabel((string)($game['finish_reason'] ?? ''));

        $players = array_map('strval', $game['player_ids'] ?? []);
        $opponents = [];
        foreach ($players as $pid) {
            if ($pid !== $userId) $opponents[] = (string)($game['player_names'][$pid] ?? $pid);
        }
        $opponent = $opponents ? implode(', ', $opponents) : '—';

        $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : '';
        if ($status !== 'finished') $result = 'активная';
        elseif ($winnerId === '') $result = 'ничья';
        elseif ($winnerId === $userId) $result = 'победа';
        else $result = 'поражение';

        $commission = (int)($game['commission'] ?? 0);
        $payout = (int)($game['payout'] ?? 0);
        $bot = !empty($game['is_bot_game']) ? ' · бот ' . ($game['bot_difficulty'] ?? '-') : '';
        $reasonPart = $reason !== '' && $reason !== '-' ? " · {$reason}" : '';

        return "{$date} · №{$id} · {$room} · {$board}×{$board} · ставка {$bet} · {$result}{$reasonPart} · соперник {$opponent} · выплата {$payout} · комиссия {$commission}{$bot}";
    }

    private function gameAdminLine(array $game): string
    {
        $id = $this->prettyMatchId((string)($game['id'] ?? ''));
        $room = ($game['room'] ?? '') === 'gold' ? 'Gold' : 'Match';
        $bet = (int)($game['bet'] ?? 0);
        $winner = (string)($game['winner_id'] ?? '');
        $winnerName = $winner !== '' ? (string)($game['player_names'][$winner] ?? $winner) : 'ничья';
        $payout = (int)($game['payout'] ?? 0);
        $commission = (int)($game['commission'] ?? 0);
        $bot = !empty($game['is_bot_game']) ? ' · бот ' . ($game['bot_difficulty'] ?? '-') : '';

        $players = [];
        foreach (($game['player_ids'] ?? []) as $pid) {
            $pid = (string)$pid;
            $players[] = (string)($game['player_names'][$pid] ?? $pid);
        }

        return "Матч №{$id} · {$room} {$bet} · " . implode(' vs ', $players) . " · победитель: {$winnerName} · выплата {$payout} · комиссия {$commission}{$bot}";
    }

    private function transactionAdminLine(array $tx): string
    {
        $id = $this->prettyId((string)($tx['id'] ?? ''));
        $label = $this->transactionLabel((string)($tx['category'] ?? $tx['type'] ?? '-'));
        $room = (string)($tx['room'] ?? '');
        $game = (string)($tx['game_id'] ?? '');
        $userId = (string)($tx['user_id'] ?? '');
        $amount = (int)($tx['amount'] ?? 0);
        $amountLabel = $amount > 0 ? '+' . $amount : (string)$amount;
        $date = $this->formatDate((string)($tx['created_at'] ?? ''));

        $parts = ["{$date}", "Операция №{$id}", $label];

        if ($userId !== '') $parts[] = "user {$userId}";
        if ($room !== '') $parts[] = $room;
        if ($game !== '') $parts[] = "Матч №" . $this->prettyMatchId($game);
        if ($amount !== 0) $parts[] = "{$amountLabel} коинов";
        if (isset($tx['balance_after'])) $parts[] = "баланс после " . (int)$tx['balance_after'];

        return implode(' · ', $parts);
    }

    private function findUser(array $db, string $query): ?array
    {
        $query = trim($query);
        $query = ltrim($query, '@');

        foreach ($db['users'] ?? [] as $user) {
            if ($this->isDevUser($user)) continue;

            $id = (string)($user['id'] ?? '');
            $username = (string)($user['username'] ?? '');
            $firstName = (string)($user['first_name'] ?? '');

            if ($query === $id || mb_strtolower($query) === mb_strtolower($username) || mb_strtolower($query) === mb_strtolower($firstName)) {
                return $user;
            }
        }

        return null;
    }

    private function userLabel(array $user): string
    {
        $username = trim((string)($user['username'] ?? ''));
        $firstName = trim((string)($user['first_name'] ?? ''));

        if ($username !== '') return '@' . ltrim($username, '@');
        if ($firstName !== '') return $firstName;

        return 'ID ' . (string)($user['id'] ?? '-');
    }

    private function transactionLabel(string $value): string
    {
        return match ($value) {
            'game_entry', 'game_start' => 'участие в матче',
            'game_win' => 'выигрыш',
            'game_refund' => 'возврат при ничьей',
            'game_finish' => 'завершение матча',
            'shop_order' => 'заказ приза',
            'shop_refund' => 'возврат за приз',
            'payment_draft' => 'заявка на пополнение',
            'payment_paid' => 'платёж оплачен',
            'payment_apply' => 'пополнение начислено',
            'payment_reject' => 'заявка отклонена',
            'admin_gold_topup' => 'админское начисление Gold',
            'system_migration' => 'системная миграция',
            'shop_order_done' => 'заявка выполнена',
            'shop_order_reject' => 'заявка отклонена',
            'support' => 'обращение',
            default => $value,
        };
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'normal_win' => 'победа',
            'draw' => 'ничья',
            'timeout' => 'таймаут',
            'player_left' => 'выход игрока',
            'system_cancel' => 'отмена системой',
            default => $reason,
        };
    }

    private function prettyId(string $id): string
    {
        $id = preg_replace('/^(tx_|support_|order_|shop_|queue_|pay_)/', '', $id);
        $id = strtoupper(substr((string)$id, 0, 6));
        return $id !== '' ? $id : '-';
    }

    private function prettyMatchId(string $id): string
    {
        $id = preg_replace('/^(game_)/', '', $id);
        $id = strtoupper(substr((string)$id, 0, 6));
        return $id !== '' ? $id : '-';
    }

    private function formatDate(string $value): string
    {
        if ($value === '') return '—';

        $timestamp = strtotime($value);
        if (!$timestamp) return $value;

        return date('d.m H:i', $timestamp);
    }

    private function shopAvailableForAdmin(array $user): int
    {
        $balance = (int)($user['balance_gold'] ?? 0);
        $wagered = (int)($user['gold_wagered_total'] ?? 0);
        $spent = (int)($user['gold_shop_spent_total'] ?? 0);
        return max(0, min($balance, $wagered - $spent));
    }

    private function winRate(int $wins, int $games): int
    {
        if ($games <= 0) return 0;
        return (int)round(($wins / $games) * 100);
    }

    public function isDevUser(array $user): bool
    {
        if (!empty($user['is_dev_user'])) return true;

        $id = (string)($user['id'] ?? '');
        $username = (string)($user['username'] ?? '');
        $firstName = (string)($user['first_name'] ?? '');

        if (str_starts_with($id, 'dev_')) return true;
        if ($firstName === 'Тестовый игрок' && str_starts_with($username, 'test_')) return true;
        if (preg_match('/^\d{6}$/', $id) && preg_match('/^test_\d{6}$/', $username)) return true;

        return false;
    }
}
