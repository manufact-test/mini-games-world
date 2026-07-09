<?php
declare(strict_types=1);

final class PaymentService
{
    public function __construct(private array $config, private UserService $users) {}

    public function status(array $db, array $user): array
    {
        return [
            'enabled' => false,
            'mode' => 'draft',
            'message' => 'Заявку на пополнение можно создать. Реальная оплата подключается отдельно.',
            'rates' => $this->rates(),
            'limits' => [
                'min_amount' => 1,
                'max_amount' => 100000,
                'currency' => 'RUB',
            ],
            'recent_payments' => $this->recentPaymentsForUser($db, (string)($user['id'] ?? ''), 5),
        ];
    }

    public function createDraftFromAmount(array &$db, array $user, string $room, int $amountRub, string $provider = 'manual_test'): array
    {
        $room = $this->normalizeRoom($room);
        $amountRub = $this->normalizeAmount($amountRub);
        $rate = $this->rateForRoom($room);
        $coins = $amountRub * $rate;

        $payment = [
            'id' => make_id('pay'),
            'user_id' => (string)($user['id'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'first_name' => (string)($user['first_name'] ?? ''),
            'last_name' => (string)($user['last_name'] ?? ''),
            'provider' => clean_string($provider, 60),
            'status' => 'draft',
            'room' => $room,
            'coins' => $coins,
            'price' => $amountRub,
            'amount_rub' => $amountRub,
            'currency' => 'RUB',
            'rate' => $rate,
            'balance_applied' => false,
            'created_at' => now_iso(),
            'updated_at' => now_iso(),
            'note' => 'Draft only. No real payment, no balance changes.',
        ];

        $db['payments'][] = $payment;

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'payment_draft',
            'category' => 'payment_draft',
            'payment_id' => $payment['id'],
            'user_id' => (string)($user['id'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'room' => $room,
            'amount' => 0,
            'coins' => $coins,
            'amount_rub' => $amountRub,
            'currency' => 'RUB',
            'description' => 'Создана заявка на пополнение',
            'created_at' => now_iso(),
        ];

        return $payment;
    }

    public function createDraft(array &$db, array $user, int $coins, string $provider = 'manual_test'): array
    {
        // Обратная совместимость со старым MVP-5.4: если где-то ещё передают coins,
        // считаем это Gold-пополнением 1:1.
        return $this->createDraftFromAmount($db, $user, 'gold', $coins, $provider);
    }

    public function adminApply(array &$db, string $query, string $adminId): string
    {
        $query = trim($query);
        if ($query === '') {
            return "💳 Подтверждение пополнения\n\nФормат:\n"
                . "/mgw_private_admin_7291_payment_apply ID_ЗАЯВКИ\n\n"
                . "ID можно взять из /mgw_private_admin_7291_payments";
        }

        $index = $this->findPaymentIndex($db, $query);
        if ($index === null) {
            return "💳 Заявка не найдена: {$query}";
        }

        if (!isset($db['payments'][$index]) || !is_array($db['payments'][$index])) {
            return "⚠️ Заявка найдена, но имеет некорректный формат.";
        }

        $payment =& $db['payments'][$index];

        if (!empty($payment['balance_applied'])) {
            return "✅ Эта заявка уже была начислена ранее.\n\n" . $this->adminDetailsFromPayment($payment);
        }

        $status = (string)($payment['status'] ?? 'draft');
        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return "⚠️ Нельзя начислить отклонённую или отменённую заявку.\n\n" . $this->adminDetailsFromPayment($payment);
        }

        $userId = (string)($payment['user_id'] ?? '');
        if ($userId === '' || !isset($db['users'][$userId]) || !is_array($db['users'][$userId])) {
            return "⚠️ Пользователь заявки не найден в users.json. Начисление остановлено.";
        }

        $room = $this->normalizeRoom((string)($payment['room'] ?? 'gold'));
        $coins = (int)($payment['coins'] ?? 0);
        $amountRub = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);

        if ($coins <= 0) {
            return "⚠️ В заявке некорректное количество коинов. Начисление остановлено.";
        }

        $balanceField = $room === 'match' ? 'balance_match' : 'balance_gold';
        $before = (int)($db['users'][$userId][$balanceField] ?? 0);
        $after = $before + $coins;

        $db['users'][$userId][$balanceField] = $after;
        $db['users'][$userId]['last_payment_apply_at'] = now_iso();

        if ($room === 'gold') {
            $depositedBefore = (int)($db['users'][$userId]['gold_deposited_total'] ?? 0);
            $db['users'][$userId]['gold_deposited_total'] = $depositedBefore + $coins;
            $db['users'][$userId]['last_gold_topup_at'] = now_iso();
        } else {
            $matchDepositedBefore = (int)($db['users'][$userId]['match_deposited_total'] ?? 0);
            $db['users'][$userId]['match_deposited_total'] = $matchDepositedBefore + $coins;
            $db['users'][$userId]['last_match_topup_at'] = now_iso();
        }

        $payment['status'] = 'paid';
        $payment['balance_applied'] = true;
        $payment['paid_at'] = $payment['paid_at'] ?? now_iso();
        $payment['applied_at'] = now_iso();
        $payment['applied_by'] = $adminId;
        $payment['updated_at'] = now_iso();

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'payment_apply',
            'payment_id' => (string)($payment['id'] ?? ''),
            'user_id' => $userId,
            'username' => (string)($db['users'][$userId]['username'] ?? ''),
            'room' => $room,
            'amount' => $coins,
            'amount_rub' => $amountRub,
            'currency' => (string)($payment['currency'] ?? 'RUB'),
            'balance_before' => $before,
            'balance_after' => $after,
            'description' => $room === 'gold' ? 'Пополнение Gold по заявке' : 'Пополнение Match по заявке',
            'admin_id' => $adminId,
            'created_at' => now_iso(),
        ];

        $roomLabel = $room === 'gold' ? 'Gold' : 'Match';

        return "✅ Пополнение подтверждено\n\n"
            . "Заявка: " . $this->shortPaymentId((string)($payment['id'] ?? '')) . "\n"
            . "Игрок: " . $this->userLabel($db['users'][$userId]) . "\n"
            . "Комната: {$roomLabel}\n"
            . "Сумма: {$amountRub} RUB\n"
            . "Начислено: {$coins} коинов\n"
            . "Баланс: {$before} → {$after}\n\n"
            . "Повторное начисление этой заявки заблокировано.";
    }

    public function adminReject(array &$db, string $argument, string $adminId): string
    {
        [$query, $reason] = $this->splitQueryAndReason($argument);

        if ($query === '') {
            return "💳 Отклонение заявки\n\nФормат:\n"
                . "/mgw_private_admin_7291_payment_reject ID_ЗАЯВКИ причина";
        }

        $index = $this->findPaymentIndex($db, $query);
        if ($index === null) {
            return "💳 Заявка не найдена: {$query}";
        }

        if (!isset($db['payments'][$index]) || !is_array($db['payments'][$index])) {
            return "⚠️ Заявка найдена, но имеет некорректный формат.";
        }

        $payment =& $db['payments'][$index];

        if (!empty($payment['balance_applied'])) {
            return "⚠️ Нельзя отклонить заявку, которая уже начислена.\n\n" . $this->adminDetailsFromPayment($payment);
        }

        $payment['status'] = 'rejected';
        $payment['rejected_at'] = now_iso();
        $payment['rejected_by'] = $adminId;
        $payment['reject_reason'] = clean_string($reason !== '' ? $reason : 'отклонено администратором', 300);
        $payment['updated_at'] = now_iso();

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'payment_reject',
            'category' => 'payment_reject',
            'payment_id' => (string)($payment['id'] ?? ''),
            'user_id' => (string)($payment['user_id'] ?? ''),
            'username' => (string)($payment['username'] ?? ''),
            'room' => (string)($payment['room'] ?? 'gold'),
            'amount' => 0,
            'reason' => (string)$payment['reject_reason'],
            'admin_id' => $adminId,
            'created_at' => now_iso(),
        ];

        return "🚫 Заявка отклонена\n\n" . $this->adminDetailsFromPayment($payment);
    }

    public function adminDetails(array $db, string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return "💳 Карточка платежной заявки\n\nФормат:\n"
                . "/mgw_private_admin_7291_payment ID_ЗАЯВКИ\n\n"
                . "ID можно взять из /mgw_private_admin_7291_payments";
        }

        $index = $this->findPaymentIndex($db, $query);
        if ($index === null) {
            return "💳 Заявка не найдена: {$query}";
        }

        return $this->adminDetailsFromPayment($db['payments'][$index]);
    }

    public function rates(): array
    {
        return [
            'match' => [
                'room' => 'match',
                'rate' => 2,
                'coin_name' => 'Match',
                'label' => '1 ₽ = 2 Match-коина',
            ],
            'gold' => [
                'room' => 'gold',
                'rate' => 1,
                'coin_name' => 'Gold',
                'label' => '1 ₽ = 1 Gold',
            ],
        ];
    }

    public function adminList(array $db, int $limit = 12): string
    {
        $summary = $this->adminSummary($db);
        $payments = array_reverse($db['payments'] ?? []);

        $lines = ["💳 Платежи"];
        $lines[] = "
Заявки создаются из Mini App. Начисление делает админ вручную.";
        $lines[] = "Всего заявок: " . $summary['total'];
        $lines[] = "Новые: " . $summary['draft'];
        $lines[] = "Начислены: " . $summary['paid'];
        $lines[] = "Отклонены: " . $summary['rejected'];

        if ($payments) {
            $lines[] = "
Последние заявки:";
            $shown = 0;

            foreach ($payments as $payment) {
                if (!is_array($payment)) {
                    continue;
                }

                $lines[] = "
" . $this->adminPaymentCard($payment, $db);
                $shown++;

                if ($shown >= $limit) {
                    break;
                }
            }
        } else {
            $lines[] = "
Платежных заявок пока нет.";
        }

        $lines[] = "
Команды:";
        $lines[] = "/mgw_private_admin_7291_payment ID — открыть заявку";
        $lines[] = "/mgw_private_admin_7291_payment_apply ID — подтвердить и начислить";
        $lines[] = "/mgw_private_admin_7291_payment_reject ID причина — отклонить";

        return implode("
", $lines);
    }

    public function adminSummary(array $db): array
    {
        $payments = $db['payments'] ?? [];
        $summary = [
            'total' => count($payments),
            'draft' => 0,
            'pending' => 0,
            'paid' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'applied' => 0,
            'coins_paid_total' => 0,
            'money_paid_total' => 0,
        ];

        foreach ($payments as $payment) {
            $status = (string)($payment['status'] ?? 'draft');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            if (!empty($payment['balance_applied'])) {
                $summary['applied']++;
            }

            if ($status === 'paid') {
                $summary['coins_paid_total'] += (int)($payment['coins'] ?? 0);
                $summary['money_paid_total'] += (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);
            }
        }

        return $summary;
    }

    private function recentPaymentsForUser(array $db, string $userId, int $limit): array
    {
        if ($userId === '') {
            return [];
        }

        $items = [];
        foreach (array_reverse($db['payments'] ?? []) as $payment) {
            if ((string)($payment['user_id'] ?? '') !== $userId) {
                continue;
            }

            $items[] = [
                'id' => (string)($payment['id'] ?? ''),
                'status' => (string)($payment['status'] ?? 'draft'),
                'room' => (string)($payment['room'] ?? 'gold'),
                'coins' => (int)($payment['coins'] ?? 0),
                'price' => (int)($payment['price'] ?? $payment['amount_rub'] ?? 0),
                'currency' => (string)($payment['currency'] ?? 'RUB'),
                'created_at' => (string)($payment['created_at'] ?? ''),
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function userTopupHistory(array $db, string $userId, int $limit = 20): array
    {
        if ($userId === '') {
            return [];
        }

        $items = [];
        foreach (array_reverse($db['payments'] ?? []) as $payment) {
            if (!is_array($payment) || (string)($payment['user_id'] ?? '') !== $userId) {
                continue;
            }

            $id = (string)($payment['id'] ?? '');
            $room = $this->normalizeRoom((string)($payment['room'] ?? 'gold'));

            $items[] = [
                'id' => $id,
                'short_id' => $this->shortPaymentId($id),
                'status' => (string)($payment['status'] ?? 'draft'),
                'room' => $room,
                'coins' => (int)($payment['coins'] ?? 0),
                'price' => (int)($payment['price'] ?? $payment['amount_rub'] ?? 0),
                'amount_rub' => (int)($payment['amount_rub'] ?? $payment['price'] ?? 0),
                'currency' => (string)($payment['currency'] ?? 'RUB'),
                'rate' => (int)($payment['rate'] ?? $this->rateForRoom($room)),
                'balance_applied' => !empty($payment['balance_applied']),
                'created_at' => (string)($payment['created_at'] ?? ''),
                'updated_at' => (string)($payment['updated_at'] ?? ''),
                'applied_at' => (string)($payment['applied_at'] ?? ''),
                'rejected_at' => (string)($payment['rejected_at'] ?? ''),
                'reject_reason' => (string)($payment['reject_reason'] ?? ''),
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function findPaymentIndex(array $db, string $query): ?int
    {
        $query = strtoupper(trim($query));
        if ($query === '') {
            return null;
        }

        $payments = $db['payments'] ?? [];
        for ($i = count($payments) - 1; $i >= 0; $i--) {
            $payment = $payments[$i] ?? null;
            if (!is_array($payment)) {
                continue;
            }

            $id = (string)($payment['id'] ?? '');
            $short = $this->shortPaymentId($id);
            $normalizedId = strtoupper($id);

            if ($query === $normalizedId || $query === $short) {
                return $i;
            }

            if (strlen($query) >= 4 && str_starts_with($short, $query)) {
                return $i;
            }
        }

        return null;
    }

    private function adminDetailsFromPayment(array $payment): string
    {
        $id = (string)($payment['id'] ?? '');
        $short = $this->shortPaymentId($id);
        $status = $this->statusLabel((string)($payment['status'] ?? 'draft'));
        $room = $this->normalizeRoom((string)($payment['room'] ?? 'gold'));
        $roomLabel = $room === 'gold' ? 'Gold' : 'Match';
        $username = (string)($payment['username'] ?? '');
        $name = trim((string)($payment['first_name'] ?? '') . ' ' . (string)($payment['last_name'] ?? ''));
        $userLabelParts = [];
        if ($name !== '') {
            $userLabelParts[] = $name;
        }
        if ($username !== '') {
            $userLabelParts[] = '@' . ltrim($username, '@');
        }
        $userLabelParts[] = 'TG ID ' . (string)($payment['user_id'] ?? '-');
        $userLabel = implode(' · ', $userLabelParts);
        $coins = (int)($payment['coins'] ?? 0);
        $price = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);
        $currency = (string)($payment['currency'] ?? 'RUB');
        $applied = !empty($payment['balance_applied']) ? 'да' : 'нет';

        $lines = ["💳 Заявка {$short}"];
        $lines[] = "\nСтатус: {$status}";
        $lines[] = "Игрок: {$userLabel}";
        $lines[] = "Комната: {$roomLabel}";
        $lines[] = "Сумма: {$price} {$currency}";
        $lines[] = "К зачислению: {$coins} коинов";
        $lines[] = "Начислено на баланс: {$applied}";
        $lines[] = "Создана: " . (string)($payment['created_at'] ?? '—');

        if (!empty($payment['applied_at'])) {
            $lines[] = "Начислена: " . (string)$payment['applied_at'];
        }

        if (!empty($payment['reject_reason'])) {
            $lines[] = "Причина отклонения: " . (string)$payment['reject_reason'];
        }

        $lines[] = "\nКоманды:";
        if (empty($payment['balance_applied']) && !in_array((string)($payment['status'] ?? ''), ['rejected', 'cancelled'], true)) {
            $lines[] = "/mgw_private_admin_7291_payment_apply {$short} — подтвердить и начислить";
            $lines[] = "/mgw_private_admin_7291_payment_reject {$short} причина — отклонить";
        } else {
            $lines[] = "действий нет";
        }

        return implode("\n", $lines);
    }

    private function normalizeRoom(string $room): string
    {
        return $room === 'match' ? 'match' : 'gold';
    }

    private function normalizeAmount(int $amountRub): int
    {
        if ($amountRub <= 0) {
            throw new RuntimeException('Введите сумму пополнения.');
        }

        if ($amountRub > 100000) {
            throw new RuntimeException('Максимальная сумма пополнения — 100 000 ₽.');
        }

        return $amountRub;
    }

    private function rateForRoom(string $room): int
    {
        return $room === 'match' ? 2 : 1;
    }

    private function adminPaymentCard(array $payment, array $db): string
    {
        $short = $this->shortPaymentId((string)($payment['id'] ?? ''));
        $statusRaw = (string)($payment['status'] ?? 'draft');
        $status = $this->statusLabel($statusRaw);
        $room = $this->normalizeRoom((string)($payment['room'] ?? 'gold'));
        $roomLabel = $room === 'match' ? 'Match' : 'Gold';
        $coins = (int)($payment['coins'] ?? 0);
        $price = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);
        $currency = (string)($payment['currency'] ?? 'RUB');
        $date = (string)($payment['created_at'] ?? '');
        $userLabel = $this->paymentUserLabel($payment, $db);
        $tgId = (string)($payment['user_id'] ?? '—');

        $lines = [];
        $lines[] = "№ {$short} · {$status}";
        $lines[] = "Игрок: {$userLabel}";
        $lines[] = "TG ID: {$tgId}";
        $lines[] = "Комната: {$roomLabel}";
        $lines[] = "Сумма: {$price} {$currency} → {$coins} коинов";
        $lines[] = "Создана: {$date}";

        if (empty($payment['balance_applied']) && !in_array($statusRaw, ['rejected', 'cancelled'], true)) {
            $lines[] = "Начислить: /mgw_private_admin_7291_payment_apply {$short}";
            $lines[] = "Отклонить: /mgw_private_admin_7291_payment_reject {$short} причина";
        } else {
            $lines[] = "Действие: уже обработана";
        }

        return implode("\n", $lines);
    }

    private function paymentUserLabel(array $payment, array $db): string
    {
        $userId = (string)($payment['user_id'] ?? '');
        $user = [];

        if ($userId !== '' && isset($db['users'][$userId]) && is_array($db['users'][$userId])) {
            $user = $db['users'][$userId];
        }

        $username = (string)($user['username'] ?? $payment['username'] ?? '');
        $firstName = (string)($user['first_name'] ?? $payment['first_name'] ?? '');
        $lastName = (string)($user['last_name'] ?? $payment['last_name'] ?? '');

        $name = trim($firstName . ' ' . $lastName);
        $parts = [];

        if ($name !== '') {
            $parts[] = $name;
        }

        if ($username !== '') {
            $parts[] = '@' . ltrim($username, '@');
        }

        if (!$parts) {
            $parts[] = 'Без имени';
        }

        return implode(' · ', $parts);
    }

    private function adminPaymentLine(array $payment): string
    {
        $short = $this->shortPaymentId((string)($payment['id'] ?? ''));
        $status = $this->statusLabel((string)($payment['status'] ?? 'draft'));
        $username = (string)($payment['username'] ?? '');
        $user = $username !== '' ? '@' . ltrim($username, '@') : ('ID ' . (string)($payment['user_id'] ?? '-'));
        $room = $this->normalizeRoom((string)($payment['room'] ?? 'gold'));
        $roomLabel = $room === 'match' ? 'Match' : 'Gold';
        $coins = (int)($payment['coins'] ?? 0);
        $price = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);
        $currency = (string)($payment['currency'] ?? 'RUB');
        $date = (string)($payment['created_at'] ?? '');

        return "№{$short} · {$status} · {$user} · {$roomLabel} · {$price} {$currency} → {$coins} коинов · {$date}";
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'заявка',
            'pending' => 'ожидает',
            'paid' => 'оплачено/начислено',
            'rejected' => 'отклонено',
            'cancelled' => 'отменено',
            default => $status !== '' ? $status : '—',
        };
    }

    private function shortPaymentId(string $id): string
    {
        $id = preg_replace('/^(pay_)/', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '-';
    }

    private function splitQueryAndReason(string $argument): array
    {
        $parts = preg_split('/\s+/', trim($argument), 2);
        return [
            trim((string)($parts[0] ?? '')),
            trim((string)($parts[1] ?? '')),
        ];
    }

    private function userLabel(array $user): string
    {
        $username = (string)($user['username'] ?? '');
        if ($username !== '') {
            return '@' . ltrim($username, '@');
        }

        return (string)($user['first_name'] ?? 'Игрок');
    }
}
