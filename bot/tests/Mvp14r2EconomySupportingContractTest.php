<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'game' => $root . '/bot/services/GameService.php',
    'settlement' => $root . '/bot/services/GameSettlementService.php',
    'history' => $root . '/bot/services/HistoryService.php',
    'user' => $root . '/bot/services/UserService.php',
    'catalog' => $root . '/bot/services/ShopCatalogService.php',
    'shop' => $root . '/bot/services/ShopService.php',
    'admin' => $root . '/bot/services/AdminService.php',
    'payment' => $root . '/bot/services/PaymentService.php',
    'weekly' => $root . '/bot/services/WeeklyMatchEconomyService.php',
    'baseline' => $root . '/bot/baseline/JsonEconomySupportingBaselineScenario.php',
    'economy' => $root . '/bot/baseline/JsonEconomyHistoryTrait.php',
    'shop_payment' => $root . '/bot/baseline/JsonShopPaymentsTrait.php',
    'weekly_baseline' => $root . '/bot/baseline/JsonWeeklyBonusTrait.php',
];

$sources = [];
foreach ($paths as $name => $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Economy source contract file is unavailable: ' . $name . '.');
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$contains = static fn(string $source, string $needle): bool => str_contains($source, $needle);
$assertContains = static function (string $source, array $needles, string $domain) use ($assert, $contains): void {
    foreach ($needles as $needle) {
        $assert($contains($source, $needle), $domain . ' source contract changed: ' . $needle);
    }
};

$assertContains($sources['game'], [
    "\$room = \$room === 'gold' ? 'gold' : 'match';",
    "\$bet = \$room === 'match' ? (int)(\$this->config['match_bet'] ?? 10) : \$bet;",
    "if (\$room === 'gold' && !in_array(\$bet, \$this->config['gold_bets'] ?? [10, 20, 30, 50, 100], true))",
    "throw new RuntimeException('Выберите доступную стоимость участия.');",
    "\$balanceKey = \$room === 'gold' ? 'balance_gold' : 'balance_match';",
    "throw new RuntimeException('Недостаточно коинов для участия.');",
    "\$a[\$balanceKey] = (int)(\$a[\$balanceKey] ?? 0) - \$bet;",
    "\$b[\$balanceKey] = (int)(\$b[\$balanceKey] ?? 0) - \$bet;",
    "'type' => 'game_start'",
    "'players' => [\$aId, \$bId]",
], 'Game reservation');

$assertContains($sources['settlement'], [
    "if (!empty(\$game['payout_done']))",
    "if ((\$game['status'] ?? '') === 'finished')",
    "\$balanceKey = \$room === 'gold' ? 'balance_gold' : 'balance_match';",
    "\$bank = \$bet * \$playerCount;",
    "if (\$winnerId === null)",
    "'game_refund'",
    "Возврат коинов при ничьей",
    "ceil(\$bank * (float)(\$this->config['commission_rate'] ?? 0.10))",
    "'game_win'",
    "\$db['system']['fees_' . \$room]",
    "\$db['users'][\$pid]['status'] = 'idle';",
    "\$db['users'][\$pid]['current_game_id'] = null;",
    "match_games_this_week",
    "gold_wagered_total",
    "\$game['payout_done'] = true;",
    "'type' => 'game_finish'",
], 'Settlement');

$assertContains($sources['history'], [
    "'operations' => \$this->balanceOperations(\$db, \$userId, \$limit)",
    "'matches' => \$this->matchHistory(\$db, \$userId, 12)",
    "\$transactions = array_reverse(\$db['transactions'] ?? []);",
    "\$seen = [];",
    "if (isset(\$seen[\$key])) continue;",
    "foreach (array_reverse(\$db['games'] ?? []) as \$game)",
    "if (\$type === 'balance_change')",
    "if (\$this->isTopupCategory(\$category)) return null;",
    "if (\$type === 'shop_order'",
    "if (\$type === 'game_start'",
    "if (\$type === 'game_finish')",
    "'title' => 'Возврат при ничьей'",
    "'amount' => \$amount",
    "'tone' => \$amount > 0 ? 'pos' : (\$amount < 0 ? 'neg' : 'zero')",
    "'result' => \$result",
    "'commission' => (int)(\$game['commission'] ?? 0)",
], 'History');

$assertContains($sources['user'], [
    "public function goldShopAvailable(array \$user): int",
    "\$balance = max(0, (int)(\$user['balance_gold'] ?? 0));",
    "if (\$this->shopTestMode(\$user))",
    "\$turnoverAvailable = max(0, \$wagered - \$spent);",
    "return max(0, min(\$balance, \$turnoverAvailable));",
    "'gold_shop_available' => \$this->goldShopAvailable(\$user)",
], 'Shop ownership');

$assertContains($sources['catalog'], [
    "'currency' => (string)(\$catalog['currency'] ?? 'GOLD')",
    "public function minGoldCost(): int",
    "public function resolveSelection(string \$itemId, string \$denominationId): array",
    "throw new RuntimeException('Некорректный выбор приза. Обновите магазин и попробуйте снова.');",
    "throw new RuntimeException('Выбранный приз больше недоступен. Обновите магазин.');",
    "throw new RuntimeException('Выбранный номинал больше недоступен. Обновите магазин.');",
    "if (!is_array(\$country) || empty(\$country['enabled']))",
    "if (!is_array(\$item) || empty(\$item['enabled']))",
    "if (!is_array(\$denomination) || empty(\$denomination['enabled']))",
    "\$goldCost <= 0",
], 'Catalog');

$assertContains($sources['shop'], [
    "private const RECENT_DUPLICATE_WINDOW_SECONDS = 20;",
    "Exact idempotency: same request key always returns the same order.",
    "\$existing = \$this->findOrderByRequestId",
    "Ключ заказа уже использован для другого приза.",
    "\$recentDuplicate = \$this->findRecentPendingDuplicate",
    "Стоимость приза изменилась.",
    "Недостаточно Gold, доступных для магазина.",
    "Недостаточно Gold на балансе.",
    "\$user['balance_gold'] = (int)\$user['balance_gold'] - \$amount;",
    "\$user['gold_shop_spent_total'] = (int)(\$user['gold_shop_spent_total'] ?? 0) + \$amount;",
    "'status' => 'pending'",
    "'refund_done' => false",
    "'prize_snapshot' => \$snapshot",
    "'category' => 'shop_order'",
    "'amount' => -\$amount",
], 'Shop order');

$assertContains($sources['admin'], [
    "public function completeOrder(array &\$db, string \$argument, string \$adminId): string",
    "if (\$status === 'done')",
    "if (\$status === 'rejected')",
    "\$order['status'] = 'done';",
    "'type' => 'shop_order_done'",
    "public function rejectOrder(array &\$db, string \$argument, string \$adminId): string",
    "if (empty(\$order['refund_done']))",
    "\$user['balance_gold'] = (int)(\$user['balance_gold'] ?? 0) + \$amount;",
    "\$user['gold_shop_spent_total'] = max(0, (int)(\$user['gold_shop_spent_total'] ?? 0) - \$amount);",
    "\$order['refund_done'] = true;",
    "'category' => 'shop_refund'",
    "\$order['status'] = 'rejected';",
    "'type' => 'shop_order_reject'",
], 'Shop decision');

$assertContains($sources['payment'], [
    "'enabled' => false",
    "'mode' => 'draft'",
    "Реальная оплата подключается отдельно.",
    "public function createDraftFromAmount",
    "'status' => 'draft'",
    "'balance_applied' => false",
    "Draft only. No real payment, no balance changes.",
    "'type' => 'payment_draft'",
    "if (\$applied)",
    "if (in_array(\$status, ['rejected', 'cancelled'], true))",
    "if (\$status === 'paid')",
    "if (!\$this->isWaitingStatus(\$status))",
    "\$db['users'][\$userId][\$balanceField] = \$after;",
    "gold_deposited_total",
    "match_deposited_total",
    "\$payment['status'] = 'paid';",
    "\$payment['balance_applied'] = true;",
    "'category' => 'payment_apply'",
    "if (\$status === 'rejected')",
    "if (\$status === 'cancelled')",
    "\$payment['status'] = 'rejected';",
    "'type' => 'payment_reject'",
], 'Payments');

$assertContains($sources['weekly'], [
    "private const DEFAULT_TIMEZONE = 'Europe/Warsaw';",
    "private const DEFAULT_START_AT = '2026-07-13 12:00:00';",
    "private const DEFAULT_BONUS_AMOUNT = 50;",
    "private const DEFAULT_MIN_GAMES = 3;",
    "if (\$checkedKey === \$cycleKey)",
    "if (\$games < \$this->minGames())",
    "if ((string)(\$user['weekly_match_bonus_last_key'] ?? '') === \$cycleKey)",
    "\$user['balance_match'] = \$after;",
    "\$user['weekly_match_bonus_last_key'] = \$cycleKey;",
    "'category' => 'weekly_bonus'",
    "'qualification' => 'activity'",
    "Еженедельный бонус за игровую активность",
    "addWeeklyMatchBonus",
    "if (\$reason === 'already_checked')",
    "if ((string)(\$game['room'] ?? 'match') !== 'match')",
    "if (\$finishedTs >= \$fromTs && \$finishedTs < \$toTs)",
    "return \$value->modify('monday this week')->setTime(12, 0, 0);",
], 'Weekly bonus');

$baselineJoined = $sources['baseline'] . $sources['economy'] . $sources['shop_payment'] . $sources['weekly_baseline'];
$assertContains($baselineJoined, [
    "'reserve_game' => ",
    "'finish_game' => ",
    "'read_history' => ",
    "'read_catalog' => ",
    "'create_shop_order' => ",
    "'complete_shop_order' => ",
    "'reject_shop_order' => ",
    "'create_payment' => ",
    "'apply_payment' => ",
    "'reject_payment' => ",
    "'cancel_payment' => ",
    "'run_weekly' => ",
    "'weekly_status' => ",
    "'measured' => false",
    "'samples' => 0",
    "Rejected economy supporting action mutated state.",
    "Economy supporting baseline retry is not deterministic.",
], 'Baseline isolation');

fwrite(STDOUT, "Mvp14r2EconomySupportingContractTest passed: {$assertions} assertions.\n");
