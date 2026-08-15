<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';
require_once __DIR__ . '/../runtime/UnifiedGameZonePolicy.php';

final class PaymentService
{
    public function __construct(private array $config, private UserService $users) {}

    public function status(array $db, array $user): array
{
    return [
        'enabled' => false,
        'mode' => 'archive_read_only',
        'message' => UnifiedGameZonePolicy::legacyArchiveMessage(),
        'recent_payments' => $this->recentPaymentsForUser($db, (string)($user['id'] ?? ''), 5),
    ];
}

    public function createDraftFromAmount(array &$db, array $user, string $room, int $amountRub, string $provider = 'manual_test'): array
{
    UnifiedGameZonePolicy::rejectLegacyCommerceWrite();
}

    public function createDraft(array &$db, array $user, int $coins, string $provider = 'manual_test'): array
{
    UnifiedGameZonePolicy::rejectLegacyCommerceWrite();
}

    public function adminApply(array &$db, string $query, string $adminId): string
{
    return UnifiedGameZonePolicy::legacyArchiveMessage();
}

    public function adminReject(array &$db, string $argument, string $adminId): string
{
    return UnifiedGameZonePolicy::legacyArchiveMessage();
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
            return "💳 Заявка не найдена: {$query}\n\nИспользуйте полный ID или точный короткий ID из списка платежей.";
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
        $payments = array_values(array_filter(
            array_reverse($db['payments'] ?? []),
            static fn(mixed $payment): bool => is_array($payment)
        ));

        $waitingPayments = [];
        $processedPayments = [];
        foreach ($payments as $payment) {
            $status = (string)($payment['status'] ?? 'draft');
            if ($this->isWaitingStatus($status)) {
                $waitingPayments[] = $payment;
            } else {
                $processedPayments[] = $payment;
            }
        }

        $lines = ["💳 Платежи"];
        $lines[] = "
Заявки создаются из Mini App. Начисление делает админ вручную.";
        $lines[] = "Всего заявок: " . $summary['total'];
        $lines[] = "Ожидают решения: " . $summary['waiting'];
        $lines[] = "Начислены: " . $summary['paid'];
        $lines[] = "Отклонены: " . $summary['rejected'];

        if ($summary['cancelled'] > 0) {
            $lines[] = "Отменены: " . $summary['cancelled'];
        }
        if ($summary['inconsistent'] > 0) {
            $lines[] = "⚠️ Несогласованные записи: " . $summary['inconsistent'];
        }
        if ($summary['invalid'] > 0) {
            $lines[] = "⚠️ Некорректные записи: " . $summary['invalid'];
        }

        if ($payments) {
            $visibleWaiting = array_slice($waitingPayments, 0, max(0, $limit));
            if ($visibleWaiting) {
                $lines[] = "
⏳ Ожидают решения:";
                foreach ($visibleWaiting as $payment) {
                    $lines[] = "
" . $this->adminPaymentCard($payment, $db);
                }
            }

            $hiddenWaiting = count($waitingPayments) - count($visibleWaiting);
            if ($hiddenWaiting > 0) {
                $lines[] = "
Ещё ожидают решения: {$hiddenWaiting}. Обработайте показанные заявки и обновите список.";
            }

            $remaining = max(0, $limit - count($visibleWaiting));
            $visibleProcessed = array_slice($processedPayments, 0, $remaining);
            if ($visibleProcessed) {
                $lines[] = "
Последние обработанные:";
                foreach ($visibleProcessed as $payment) {
                    $lines[] = "
" . $this->adminPaymentCard($payment, $db);
                }
            }
        } else {
            $lines[] = "
Платежных заявок пока нет.";
        }

        $lines[] = "
Архив доступен только для просмотра.";
        $lines[] = "/mgw_private_admin_7291_payment ID — открыть архивную заявку";

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
            'waiting' => 0,
            'paid' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'applied' => 0,
            'inconsistent' => 0,
            'invalid' => 0,
            'coins_paid_total' => 0,
            'money_paid_total' => 0,
        ];

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                $summary['invalid']++;
                continue;
            }

            $status = (string)($payment['status'] ?? 'draft');
            $applied = !empty($payment['balance_applied']);

            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            if ($this->isWaitingStatus($status)) {
                $summary['waiting']++;
            }
            if ($applied) {
                $summary['applied']++;
            }

            $isConsistentPaid = $status === 'paid' && $applied;
            $isInconsistent = ($status === 'paid' && !$applied)
                || ($status !== 'paid' && $applied);

            if ($isInconsistent) {
                $summary['inconsistent']++;
            }

            if ($isConsistentPaid) {
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
            if (!is_array($payment) || (string)($payment['user_id'] ?? '') !== $userId) {
                continue;
            }

            $status = (string)($payment['status'] ?? 'draft');
            $items[] = [
                'id' => (string)($payment['id'] ?? ''),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
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
            $status = (string)($payment['status'] ?? 'draft');

            $items[] = [
                'id' => $id,
                'short_id' => $this->shortPaymentId($id),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
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
        }

        return null;
    }

    private function adminDetailsFromPayment(array $payment): string
    {
        $id = (string)($payment['id'] ?? '');
        $short = $this->shortPaymentId($id);
        $statusRaw = (string)($payment['status'] ?? 'draft');
        $status = $this->statusLabel($statusRaw);
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
        if (!empty($payment['rejected_at'])) {
            $lines[] = "Отклонена: " . (string)$payment['rejected_at'];
        }
        if (!empty($payment['reject_reason'])) {
            $lines[] = "Причина отклонения: " . (string)$payment['reject_reason'];
        }

        $lines[] = "\nАрхив доступен только для просмотра.";
        if ($this->isActionablePayment($payment)) {
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

        if ($this->isActionablePayment($payment)) {
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

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft', 'pending' => 'ожидает решения',
            'paid' => 'начислено',
            'rejected' => 'отклонено',
            'cancelled' => 'отменено',
            default => $status !== '' ? $status : '—',
        };
    }

    private function isWaitingStatus(string $status): bool
    {
        return in_array($status, ['draft', 'pending'], true);
    }

    private function isActionablePayment(array $payment): bool
    {
        return empty($payment['balance_applied'])
            && $this->isWaitingStatus((string)($payment['status'] ?? 'draft'));
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
