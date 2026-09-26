<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/economy/CompensationService.php') ?: '';
$endpoint = file_get_contents($root . '/admin-compensation.php') ?: '';
$admin = file_get_contents(dirname($root) . '/app/admin.php') ?: '';
$client = file_get_contents(dirname($root) . '/app/assets/js/admin-compensation.js') ?: '';
$entrypoints = file_get_contents($root . '/runtime/ProductionPrimaryApplicationEntrypoints.php') ?: '';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($service, 'LedgerWriteService'), 'Compensation must use the canonical LedgerWriteService.');
$assert(str_contains($service, 'StorageTransactionInterface'), 'Compensation must project the accepted ledger result into the active runtime storage transaction.');
$assert(str_contains($service, 'UnifiedBalanceRuntimeState::FIELD'), 'Compensation must keep the runtime unified balance projection converged.');
$assert(str_contains($service, "'category' => 'admin_compensation'"), 'Compensation ledger category must be explicit.');
$assert(str_contains($service, "'source_type' => 'admin_compensation'"), 'Compensation source type must be explicit.');
$assert(str_contains($service, 'LARGE_AMOUNT_THRESHOLD = 50000'), 'Large compensation threshold must be server-owned.');
$assert(str_contains($service, 'MAX_AMOUNT = 250000'), 'Compensation maximum must be server-owned.');
$assert(str_contains($service, "STATUS_PENDING_CONFIRMATION"), 'Large compensation must persist a pending confirmation state.');
$assert(str_contains($service, 'public function confirm('), 'Second confirmation must be a separate server action.');
$assert(str_contains($service, "'original_entry_id'"), 'Compensation metadata must link the original ledger entry.');
$assert(str_contains($service, "'original_operation_key'"), 'Compensation metadata must link the original operation key.');
$assert(str_contains($service, 'public function recentOperations('), 'Compensation service must expose a bounded browser of canonical source operations.');
$assert(str_contains($service, "l.available_delta<0"), 'Operation browser must expose only debit operations.');
$assert(str_contains($service, "l.category<>:compensation_category"), 'Operation browser must exclude compensation-on-compensation rows.');
$assert(str_contains($service, "Компенсировать можно только исходное списание MGW Coins."), 'Server must reject positive source operations even through exact-ID lookup.');
$assert(!preg_match('/UPDATE\s+mgw_balances/i', $service), 'Compensation service must never directly update balances.');
$assert(!preg_match('/INSERT\s+INTO\s+mgw_ledger_entries/i', $service), 'Compensation service must never become a parallel ledger writer.');

$assert(str_contains($endpoint, "['snapshot','operations','lookup','request','confirm']"), 'Admin endpoint must expose the bounded compensation actions.');
$assert(str_contains($endpoint, 'AdminWebAuth::authorize'), 'Compensation endpoint must reuse Admin Web auth.');
$assert(str_contains($endpoint, "routeFor('economy')"), 'Compensation endpoint must require the canonical economy DB route.');
$assert(str_contains($endpoint, 'StorageFactory::create($config)'), 'Compensation endpoint must use the active runtime storage owner for projection.');

$assert(str_contains($admin, 'data-compensation-api="../bot/admin-compensation.php"'), 'Web Admin must publish the compensation endpoint.');
$assert(str_contains($admin, 'data-compensation-operation-picker') && str_contains($admin, 'data-compensation-operation-trigger') && str_contains($admin, 'data-compensation-operation-list'), 'Web Admin must expose an in-app source-operation picker instead of a native long dropdown.');
$assert(str_contains($admin, 'data-compensation-operation'), 'Web Admin must keep exact original-operation lookup inside the advanced path.');
$assert(str_contains($admin, 'data-compensation-browser-query'), 'Web Admin must keep optional advanced search available.');
$assert(str_contains($admin, 'Технические данные'), 'Technical ledger details must be collapsed behind an explicit disclosure.');
$assert(str_contains($admin, 'Не нашли нужную операцию?'), 'Technical lookup must stay outside the primary operator flow.');
$assert(str_contains($admin, 'data-compensation-reason'), 'Web Admin must require a reason.');
$assert(str_contains($admin, 'data-compensation-confirmation'), 'Web Admin must expose the second-confirmation state.');
$assert(str_contains($admin, 'Возврат коинов по списанию'), 'Admin copy must describe compensation in operator language instead of ledger terminology.');

$assert(str_contains($client, "action:'operations'"), 'Admin client must browse canonical source operations without requiring copied technical IDs.');
$assert(str_contains($client, 'const renderPicker = rows =>') && str_contains($client, "pickerTrigger.addEventListener('click'") && !str_contains($client, 'chooseFromPicker'), 'Admin client must use the managed in-app picker as the primary selection owner.');
$assert(str_contains($client, 'rows.filter(canCompensate)'), 'Admin client must hide ineligible credit operations from the primary picker.');
$assert(!str_contains($admin, '<select data-compensation-operation-picker>'), 'Compensation must not regress to the Android native full-screen operation select.');
$assert(str_contains($admin, 'data-compensation-pagination'), 'Compensation history must expose bounded pagination.');
$assert(str_contains($client, 'selectedTech'), 'Technical operation metadata must render only inside the collapsed technical disclosure.');
$assert(str_contains($client, "action:'lookup'"), 'Admin client must retain exact original-operation lookup.');
$assert(str_contains($client, "action:'request'"), 'Admin client must use a dedicated compensation request action.');
$assert(str_contains($client, "action:'confirm'"), 'Admin client must use a distinct confirmation request.');
$assert(str_contains($client, 'request_token'), 'Admin client must provide request idempotency.');
$assert(str_contains($client, 'pending_confirmation'), 'Admin client must render pending large compensations.');
$assert(str_contains($client, 'available_before'), 'Admin client must show the audited ledger balance transition.');

$assert(str_contains($entrypoints, "'bot/admin-compensation.php'"), 'Compensation endpoint must inherit the canonical API DB-primary route.');

fwrite(STDOUT, "MVP-22.2 compensation static contract OK ($assertions assertions).\n");
