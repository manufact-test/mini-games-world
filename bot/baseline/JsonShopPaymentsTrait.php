<?php
declare(strict_types=1);

trait JsonShopPaymentsTrait
{
    private function readCatalogStep(array $step): array
    {
        $catalog = $this->normalizeCatalog(is_array($step['catalog'] ?? null) ? $step['catalog'] : []);
        return ['public' => $catalog, 'ledger' => [], 'event_type' => 'catalog_read'];
    }

    private function normalizeCatalog(array $raw): array
    {
        $countries = [];
        $countryNames = [];
        foreach ($raw['countries'] ?? [] as $country) {
            if (!is_array($country) || empty($country['enabled'])) continue;
            $code = strtoupper(trim((string)($country['code'] ?? '')));
            $name = trim((string)($country['name'] ?? ''));
            if ($code === '' || $name === '' || isset($countryNames[$code])) continue;
            $countryNames[$code] = $name;
            $countries[] = ['code' => $code, 'name' => $name, 'sort_order' => (int)($country['sort_order'] ?? 1000)];
        }
        usort($countries, static fn(array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['name'], $b['name']));

        $items = [];
        $seenItems = [];
        $seenDenominations = [];
        foreach ($raw['items'] ?? [] as $item) {
            if (!is_array($item) || empty($item['enabled'])) continue;
            $id = trim((string)($item['id'] ?? ''));
            $countryCode = strtoupper(trim((string)($item['country_code'] ?? '')));
            $providerCode = trim((string)($item['provider_code'] ?? ''));
            $provider = trim((string)($item['provider'] ?? ''));
            if (!$this->validCatalogId($id) || isset($seenItems[$id]) || !isset($countryNames[$countryCode]) || !$this->validCatalogId($providerCode) || $provider === '') continue;
            $denominations = [];
            foreach ($item['denominations'] ?? [] as $denomination) {
                if (!is_array($denomination) || empty($denomination['enabled'])) continue;
                $denominationId = trim((string)($denomination['id'] ?? ''));
                $cost = (int)($denomination['gold_cost'] ?? 0);
                if (!$this->validCatalogId($denominationId) || $cost <= 0 || isset($seenDenominations[$denominationId])) continue;
                $seenDenominations[$denominationId] = true;
                $denominations[] = [
                    'id' => $denominationId,
                    'label' => trim((string)($denomination['label'] ?? '')) ?: ($cost . ' Gold'),
                    'gold_cost' => $cost,
                    'sort_order' => (int)($denomination['sort_order'] ?? 1000),
                ];
            }
            usort($denominations, static fn(array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']) ?: ($a['gold_cost'] <=> $b['gold_cost']));
            if ($denominations === []) continue;
            $seenItems[$id] = true;
            $title = trim((string)($item['title'] ?? $provider)) ?: $provider;
            $items[] = [
                'id' => $id,
                'country_code' => $countryCode,
                'country' => $countryNames[$countryCode],
                'provider_code' => $providerCode,
                'provider' => $provider,
                'title' => $title,
                'description' => trim((string)($item['description'] ?? '')),
                'delivery_type' => trim((string)($item['delivery_type'] ?? 'manual_code')) ?: 'manual_code',
                'image' => trim((string)($item['image'] ?? '')),
                'image_alt' => trim((string)($item['image_alt'] ?? $title)),
                'sort_order' => (int)($item['sort_order'] ?? 1000),
                'min_amount' => min(array_map(static fn(array $row): int => (int)$row['gold_cost'], $denominations)),
                'denominations' => $denominations,
            ];
        }
        usort($items, static fn(array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['title'], $b['title']));
        $usedCountries = array_fill_keys(array_map(static fn(array $item): string => (string)$item['country_code'], $items), true);
        $countries = array_values(array_filter($countries, static fn(array $country): bool => isset($usedCountries[(string)$country['code']])));
        return [
            'version' => max(1, (int)($raw['version'] ?? 1)),
            'currency' => trim((string)($raw['currency'] ?? 'GOLD')) ?: 'GOLD',
            'updated_at' => trim((string)($raw['updated_at'] ?? '')),
            'countries' => $countries,
            'items' => $items,
        ];
    }

    private function createShopOrder(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now
    ): array {
        $userId = trim((string)($step['actor_id'] ?? ''));
        if ($userId === '' || !isset($state['users'][$userId])) throw new RuntimeException('Пользователь не найден.');
        $requestId = trim((string)($step['request_id'] ?? ''));
        if (preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $requestId) !== 1) {
            throw new RuntimeException('Не удалось подтвердить параметры заказа. Обновите магазин и попробуйте снова.');
        }
        $itemId = trim((string)($step['item_id'] ?? ''));
        $denominationId = trim((string)($step['denomination_id'] ?? ''));
        foreach ($state['shop_orders'] as $order) {
            if ((string)($order['user_id'] ?? '') !== $userId || (string)($order['client_request_id'] ?? '') !== $requestId) continue;
            if ((string)($order['item_id'] ?? '') !== $itemId || (string)($order['denomination_id'] ?? '') !== $denominationId) {
                throw new RuntimeException('Ключ заказа уже использован для другого приза. Обновите магазин и попробуйте снова.');
            }
            $existing = $order;
            $existing['request_replayed'] = true;
            return ['public' => $existing, 'ledger' => [], 'event_type' => 'shop_order_replayed', 'order_id' => (string)$order['id']];
        }
        $catalog = $this->normalizeCatalog(is_array($step['catalog'] ?? null) ? $step['catalog'] : []);
        [$item, $denomination] = $this->resolveCatalogSelection($catalog, $itemId, $denominationId);
        $amount = (int)$denomination['gold_cost'];
        $expected = (int)($step['expected_amount'] ?? 0);
        if ($amount !== $expected) throw new RuntimeException('Стоимость приза изменилась. Обновите магазин и подтвердите заказ заново.');
        $user =& $state['users'][$userId];
        $available = $this->goldShopAvailable($user, !empty($step['test_mode']));
        if ($available < $amount) throw new RuntimeException('Недостаточно Gold, доступных для магазина.');
        if ((int)($user['balance_gold'] ?? 0) < $amount) throw new RuntimeException('Недостаточно Gold на балансе.');
        $user['balance_gold'] = (int)$user['balance_gold'] - $amount;
        $user['gold_shop_spent_total'] = (int)($user['gold_shop_spent_total'] ?? 0) + $amount;
        $order = [
            'id' => $fixture->nextId('order'),
            'client_request_id' => $requestId,
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'catalog_version' => (int)$catalog['version'],
            'catalog_updated_at' => (string)$catalog['updated_at'],
            'item_id' => $itemId,
            'denomination_id' => $denominationId,
            'country_code' => (string)$item['country_code'],
            'country' => (string)$item['country'],
            'provider_code' => (string)$item['provider_code'],
            'provider' => (string)$item['provider'],
            'prize_title' => (string)$item['title'],
            'denomination_label' => (string)$denomination['label'],
            'delivery_type' => (string)$item['delivery_type'],
            'amount' => $amount,
            'gold_cost' => $amount,
            'status' => 'pending',
            'refund_done' => false,
            'prize_snapshot' => [
                'catalog_version' => (int)$catalog['version'],
                'catalog_updated_at' => (string)$catalog['updated_at'],
                'currency' => (string)$catalog['currency'],
                'item_id' => $itemId,
                'denomination_id' => $denominationId,
                'country_code' => (string)$item['country_code'],
                'country' => (string)$item['country'],
                'provider_code' => (string)$item['provider_code'],
                'provider' => (string)$item['provider'],
                'title' => (string)$item['title'],
                'description' => (string)$item['description'],
                'delivery_type' => (string)$item['delivery_type'],
                'image' => (string)$item['image'],
                'image_alt' => (string)$item['image_alt'],
                'denomination_label' => (string)$denomination['label'],
                'gold_cost' => $amount,
            ],
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['shop_orders'][] = $order;
        $tx = [
            'id' => $fixture->nextId('tx'),
            'type' => 'balance_change',
            'category' => 'shop_order',
            'order_id' => $order['id'],
            'client_request_id' => $requestId,
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'room' => 'gold',
            'item_id' => $itemId,
            'denomination_id' => $denominationId,
            'provider' => (string)$item['provider'],
            'amount' => -$amount,
            'balance_after' => (int)$user['balance_gold'],
            'description' => 'Заказ приза: ' . $item['title'] . ' · ' . $denomination['label'],
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        $public = $order;
        $public['request_replayed'] = false;
        return ['public' => $public, 'ledger' => [$tx], 'event_type' => 'shop_order_created', 'order_id' => (string)$order['id']];
    }

    private function completeShopOrder(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now
    ): array {
        $index = $this->shopOrderIndex($state, (string)($step['order_ref'] ?? 'last'));
        $order =& $state['shop_orders'][$index];
        $status = (string)($order['status'] ?? 'pending');
        if ($status === 'done') return ['public' => $order, 'ledger' => [], 'event_type' => 'shop_order_done_replayed', 'order_id' => (string)$order['id']];
        if ($status === 'rejected') throw new RuntimeException('Заявка уже отклонена. Выполненной её отметить нельзя.');
        if ($status !== 'pending') throw new RuntimeException('Заявка имеет неподдерживаемый статус. Действие остановлено.');
        $order['status'] = 'done';
        $order['updated_at'] = $now->format(DATE_ATOM);
        $order['completed_at'] = $now->format(DATE_ATOM);
        $order['completed_by'] = (string)($step['admin_id'] ?? 'admin-1');
        if (trim((string)($step['note'] ?? '')) !== '') $order['admin_note'] = trim((string)$step['note']);
        $tx = [
            'id' => $fixture->nextId('tx'),
            'type' => 'shop_order_done',
            'category' => 'shop_order_done',
            'order_id' => (string)$order['id'],
            'user_id' => (string)$order['user_id'],
            'username' => (string)$order['username'],
            'room' => 'gold',
            'provider' => (string)$order['provider'],
            'amount' => 0,
            'description' => 'Заявка магазина выполнена',
            'admin_id' => (string)$order['completed_by'],
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        return ['public' => $order, 'ledger' => [$tx], 'event_type' => 'shop_order_done', 'order_id' => (string)$order['id']];
    }

    private function rejectShopOrder(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now
    ): array {
        $index = $this->shopOrderIndex($state, (string)($step['order_ref'] ?? 'last'));
        $order =& $state['shop_orders'][$index];
        $status = (string)($order['status'] ?? 'pending');
        if ($status === 'rejected') return ['public' => $order, 'ledger' => [], 'event_type' => 'shop_order_reject_replayed', 'order_id' => (string)$order['id']];
        if ($status === 'done') throw new RuntimeException('Заявка уже выполнена. Отклонить её нельзя, чтобы случайно не сделать неверный возврат.');
        if ($status !== 'pending') throw new RuntimeException('Заявка имеет неподдерживаемый статус. Действие остановлено.');
        $userId = (string)($order['user_id'] ?? '');
        if ($userId === '' || !isset($state['users'][$userId])) throw new RuntimeException('Пользователь заявки не найден. Возврат невозможен, статус не изменён.');
        $ledger = [];
        $amount = abs((int)($order['amount'] ?? 0));
        if (empty($order['refund_done'])) {
            $state['users'][$userId]['balance_gold'] = (int)($state['users'][$userId]['balance_gold'] ?? 0) + $amount;
            $state['users'][$userId]['gold_shop_spent_total'] = max(0, (int)($state['users'][$userId]['gold_shop_spent_total'] ?? 0) - $amount);
            $order['refund_done'] = true;
            $order['refund_amount'] = $amount;
            $order['refunded_at'] = $now->format(DATE_ATOM);
            $refund = [
                'id' => $fixture->nextId('tx'),
                'type' => 'balance_change',
                'category' => 'shop_refund',
                'order_id' => (string)$order['id'],
                'user_id' => $userId,
                'username' => (string)($state['users'][$userId]['username'] ?? ''),
                'room' => 'gold',
                'provider' => (string)$order['provider'],
                'amount' => $amount,
                'balance_after' => (int)$state['users'][$userId]['balance_gold'],
                'description' => 'Возврат за отклонённую заявку магазина',
                'admin_id' => (string)($step['admin_id'] ?? 'admin-1'),
                'created_at' => $now->format(DATE_ATOM),
            ];
            $state['transactions'][] = $refund;
            $ledger[] = $refund;
        }
        $order['status'] = 'rejected';
        $order['updated_at'] = $now->format(DATE_ATOM);
        $order['rejected_at'] = $now->format(DATE_ATOM);
        $order['rejected_by'] = (string)($step['admin_id'] ?? 'admin-1');
        if (trim((string)($step['note'] ?? '')) !== '') $order['admin_note'] = trim((string)$step['note']);
        $decision = [
            'id' => $fixture->nextId('tx'),
            'type' => 'shop_order_reject',
            'category' => 'shop_order_reject',
            'order_id' => (string)$order['id'],
            'user_id' => $userId,
            'username' => (string)($order['username'] ?? ''),
            'room' => 'gold',
            'provider' => (string)$order['provider'],
            'amount' => 0,
            'description' => 'Заявка магазина отклонена',
            'admin_id' => (string)$order['rejected_by'],
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $decision;
        $ledger[] = $decision;
        return ['public' => $order, 'ledger' => $ledger, 'event_type' => 'shop_order_rejected', 'order_id' => (string)$order['id']];
    }

    private function createPayment(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        $userId = trim((string)($step['actor_id'] ?? ''));
        if ($userId === '' || !isset($state['users'][$userId])) throw new RuntimeException('Пользователь не найден.');
        $room = (string)($step['room'] ?? 'gold') === 'match' ? 'match' : 'gold';
        $amountRub = (int)($step['amount_rub'] ?? 0);
        if ($amountRub < 1 || $amountRub > 100000) throw new RuntimeException('Укажите сумму от 1 до 100000 RUB.');
        $rate = (int)($config['payment_rates'][$room] ?? ($room === 'match' ? 10 : 1));
        $coins = $amountRub * $rate;
        $payment = [
            'id' => $fixture->nextId('payment'),
            'user_id' => $userId,
            'username' => (string)($state['users'][$userId]['username'] ?? ''),
            'first_name' => (string)($state['users'][$userId]['first_name'] ?? ''),
            'last_name' => (string)($state['users'][$userId]['last_name'] ?? ''),
            'provider' => trim((string)($step['provider'] ?? 'manual_test')),
            'status' => 'draft',
            'room' => $room,
            'coins' => $coins,
            'price' => $amountRub,
            'amount_rub' => $amountRub,
            'currency' => 'RUB',
            'rate' => $rate,
            'balance_applied' => false,
            'created_at' => $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
            'note' => 'Draft only. No real payment, no balance changes.',
        ];
        $state['payments'][] = $payment;
        $tx = [
            'id' => $fixture->nextId('tx'),
            'type' => 'payment_draft',
            'category' => 'payment_draft',
            'payment_id' => (string)$payment['id'],
            'user_id' => $userId,
            'username' => (string)$payment['username'],
            'room' => $room,
            'amount' => 0,
            'coins' => $coins,
            'amount_rub' => $amountRub,
            'currency' => 'RUB',
            'description' => 'Создана заявка на пополнение',
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        return ['public' => $payment, 'ledger' => [$tx], 'event_type' => 'payment_created', 'payment_id' => (string)$payment['id']];
    }

    private function applyPayment(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now
    ): array {
        $index = $this->paymentIndex($state, (string)($step['payment_ref'] ?? 'last'));
        $payment =& $state['payments'][$index];
        $status = (string)($payment['status'] ?? 'draft');
        if (!empty($payment['balance_applied'])) {
            if ($status === 'paid') return ['public' => $payment, 'ledger' => [], 'event_type' => 'payment_apply_replayed', 'payment_id' => (string)$payment['id']];
            throw new RuntimeException('У заявки уже стоит признак начисления, но её статус не равен paid.');
        }
        if (in_array($status, ['rejected','cancelled'], true)) throw new RuntimeException('Нельзя начислить отклонённую или отменённую заявку.');
        if ($status === 'paid') throw new RuntimeException('У заявки уже стоит статус paid, но нет признака начисления на баланс.');
        if (!in_array($status, ['draft','pending'], true)) throw new RuntimeException('Неизвестный статус заявки. Начисление остановлено.');
        $userId = (string)($payment['user_id'] ?? '');
        if ($userId === '' || !isset($state['users'][$userId])) throw new RuntimeException('Пользователь заявки не найден в users.json. Начисление остановлено.');
        $room = (string)($payment['room'] ?? 'gold') === 'match' ? 'match' : 'gold';
        $coins = (int)($payment['coins'] ?? 0);
        $amountRub = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);
        if ($coins <= 0 || $amountRub <= 0) throw new RuntimeException('В заявке некорректная сумма или количество коинов. Начисление остановлено.');
        $balanceField = $room === 'match' ? 'balance_match' : 'balance_gold';
        $before = (int)($state['users'][$userId][$balanceField] ?? 0);
        $after = $before + $coins;
        $state['users'][$userId][$balanceField] = $after;
        $state['users'][$userId]['last_payment_apply_at'] = $now->format(DATE_ATOM);
        if ($room === 'gold') {
            $state['users'][$userId]['gold_deposited_total'] = (int)($state['users'][$userId]['gold_deposited_total'] ?? 0) + $coins;
            $state['users'][$userId]['last_gold_topup_at'] = $now->format(DATE_ATOM);
        } else {
            $state['users'][$userId]['match_deposited_total'] = (int)($state['users'][$userId]['match_deposited_total'] ?? 0) + $coins;
            $state['users'][$userId]['last_match_topup_at'] = $now->format(DATE_ATOM);
        }
        $payment['status'] = 'paid';
        $payment['balance_applied'] = true;
        $payment['paid_at'] = $payment['paid_at'] ?? $now->format(DATE_ATOM);
        $payment['applied_at'] = $now->format(DATE_ATOM);
        $payment['applied_by'] = (string)($step['admin_id'] ?? 'admin-1');
        $payment['updated_at'] = $now->format(DATE_ATOM);
        $tx = [
            'id' => $fixture->nextId('tx'),
            'type' => 'balance_change',
            'category' => 'payment_apply',
            'payment_id' => (string)$payment['id'],
            'user_id' => $userId,
            'username' => (string)($state['users'][$userId]['username'] ?? ''),
            'room' => $room,
            'amount' => $coins,
            'amount_rub' => $amountRub,
            'currency' => (string)($payment['currency'] ?? 'RUB'),
            'balance_before' => $before,
            'balance_after' => $after,
            'description' => $room === 'gold' ? 'Пополнение Gold по заявке' : 'Пополнение Match по заявке',
            'admin_id' => (string)$payment['applied_by'],
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        return ['public' => $payment, 'ledger' => [$tx], 'event_type' => 'payment_applied', 'payment_id' => (string)$payment['id']];
    }

    private function rejectPayment(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now
    ): array {
        $index = $this->paymentIndex($state, (string)($step['payment_ref'] ?? 'last'));
        $payment =& $state['payments'][$index];
        $status = (string)($payment['status'] ?? 'draft');
        if (!empty($payment['balance_applied'])) throw new RuntimeException('Нельзя отклонить заявку, которая уже начислена.');
        if ($status === 'rejected') return ['public' => $payment, 'ledger' => [], 'event_type' => 'payment_reject_replayed', 'payment_id' => (string)$payment['id']];
        if ($status === 'cancelled') throw new RuntimeException('Заявка уже отменена. Отклонение не выполнено.');
        if ($status === 'paid') throw new RuntimeException('У заявки уже стоит статус paid. Отклонение заблокировано.');
        if (!in_array($status, ['draft','pending'], true)) throw new RuntimeException('Неизвестный статус заявки. Отклонение остановлено.');
        $reason = trim((string)($step['reason'] ?? ''));
        if ((function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason)) < 3) throw new RuntimeException('Укажите причину отклонения длиной не менее трёх символов.');
        $payment['status'] = 'rejected';
        $payment['rejected_at'] = $now->format(DATE_ATOM);
        $payment['rejected_by'] = (string)($step['admin_id'] ?? 'admin-1');
        $payment['reject_reason'] = $reason;
        $payment['updated_at'] = $now->format(DATE_ATOM);
        $tx = [
            'id' => $fixture->nextId('tx'),
            'type' => 'payment_reject',
            'category' => 'payment_reject',
            'payment_id' => (string)$payment['id'],
            'user_id' => (string)$payment['user_id'],
            'username' => (string)$payment['username'],
            'room' => (string)$payment['room'],
            'amount' => 0,
            'reason' => $reason,
            'admin_id' => (string)$payment['rejected_by'],
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        return ['public' => $payment, 'ledger' => [$tx], 'event_type' => 'payment_rejected', 'payment_id' => (string)$payment['id']];
    }

    private function cancelPayment(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array $step,
        DateTimeImmutable $now
    ): array {
        $index = $this->paymentIndex($state, (string)($step['payment_ref'] ?? 'last'));
        $payment =& $state['payments'][$index];
        $status = (string)($payment['status'] ?? 'draft');
        if ($status === 'cancelled') return ['public' => $payment, 'ledger' => [], 'event_type' => 'payment_cancel_replayed', 'payment_id' => (string)$payment['id']];
        if (!in_array($status, ['draft','pending'], true) || !empty($payment['balance_applied'])) {
            throw new RuntimeException('Отменить можно только ожидающую заявку без начисления.');
        }
        $payment['status'] = 'cancelled';
        $payment['cancelled_at'] = $now->format(DATE_ATOM);
        $payment['cancelled_by'] = (string)($step['actor_id'] ?? $payment['user_id'] ?? '');
        $payment['updated_at'] = $now->format(DATE_ATOM);
        $tx = [
            'id' => $fixture->nextId('tx'),
            'type' => 'payment_cancel',
            'category' => 'payment_cancel',
            'payment_id' => (string)$payment['id'],
            'user_id' => (string)$payment['user_id'],
            'username' => (string)$payment['username'],
            'room' => (string)$payment['room'],
            'amount' => 0,
            'created_at' => $now->format(DATE_ATOM),
        ];
        $state['transactions'][] = $tx;
        return ['public' => $payment, 'ledger' => [$tx], 'event_type' => 'payment_cancelled', 'payment_id' => (string)$payment['id']];
    }

    private function shopProjection(array $state): array
    {
        $orders = array_values($state['shop_orders'] ?? []);
        usort($orders, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? $b['created_at'] ?? ''), (string)($a['updated_at'] ?? $a['created_at'] ?? '')));
        return [
            'count' => count($orders),
            'pending' => count(array_filter($orders, static fn(array $order): bool => (string)($order['status'] ?? 'pending') === 'pending')),
            'done' => count(array_filter($orders, static fn(array $order): bool => (string)($order['status'] ?? '') === 'done')),
            'rejected' => count(array_filter($orders, static fn(array $order): bool => (string)($order['status'] ?? '') === 'rejected')),
            'orders' => $orders,
        ];
    }

    private function paymentProjection(array $state): array
    {
        $payments = array_values($state['payments'] ?? []);
        usort($payments, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? $b['created_at'] ?? ''), (string)($a['updated_at'] ?? $a['created_at'] ?? '')));
        $summary = ['draft' => 0, 'pending' => 0, 'paid' => 0, 'rejected' => 0, 'cancelled' => 0];
        foreach ($payments as $payment) {
            $status = (string)($payment['status'] ?? 'draft');
            if (array_key_exists($status, $summary)) $summary[$status]++;
        }
        return ['count' => count($payments), 'summary' => $summary, 'payments' => $payments];
    }

    private function resolveCatalogSelection(array $catalog, string $itemId, string $denominationId): array
    {
        foreach ($catalog['items'] ?? [] as $item) {
            if ((string)$item['id'] !== $itemId) continue;
            foreach ($item['denominations'] ?? [] as $denomination) {
                if ((string)$denomination['id'] === $denominationId) return [$item, $denomination];
            }
            throw new RuntimeException('Выбранный номинал больше недоступен. Обновите магазин.');
        }
        throw new RuntimeException('Выбранный приз больше недоступен. Обновите магазин.');
    }

    private function goldShopAvailable(array $user, bool $testMode): int
    {
        $balance = max(0, (int)($user['balance_gold'] ?? 0));
        if ($testMode) return $balance;
        $wagered = (int)($user['gold_wagered_total'] ?? 0);
        $spent = (int)($user['gold_shop_spent_total'] ?? 0);
        return max(0, min($balance, max(0, $wagered - $spent)));
    }

    private function shopOrderIndex(array $state, string $ref): int
    {
        if ($ref === 'last') {
            $index = array_key_last($state['shop_orders']);
            if ($index !== null) return (int)$index;
        }
        foreach ($state['shop_orders'] as $index => $order) {
            if ((string)($order['id'] ?? '') === $ref) return (int)$index;
        }
        throw new RuntimeException('Заявка магазина не найдена.');
    }

    private function paymentIndex(array $state, string $ref): int
    {
        if ($ref === 'last') {
            $index = array_key_last($state['payments']);
            if ($index !== null) return (int)$index;
        }
        foreach ($state['payments'] as $index => $payment) {
            if ((string)($payment['id'] ?? '') === $ref) return (int)$index;
        }
        throw new RuntimeException('Заявка не найдена.');
    }

    private function validCatalogId(string $id): bool
    {
        return $id !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/i', $id) === 1;
    }
}
