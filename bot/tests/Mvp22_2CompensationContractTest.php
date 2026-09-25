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
$assert(str_contains($service, "l.category<>:compensation_category"), 'Operation browser must exclude compensation-on-compensation rows.');
$assert(!preg_match('/UPDATE\s+mgw_balances/i', $service), 'Compensation service must never directly update balances.');
$assert(!preg_match('/INSERT\s+INTO\s+mgw_ledger_entries/i', $service), 'Compensation service must never become a parallel ledger writer.');

$assert(str_contains($endpoint, "['snapshot','operations','lookup','request','confirm']"), 'Admin endpoint must expose the bounded compensation actions.');
$assert(str_contains($endpoint, 'AdminWebAuth::authorize'), 'Compensation endpoint must reuse Admin Web auth.');
$assert(str_contains($endpoint, "routeFor('economy')"), 'Compensation endpoint must require the canonical economy DB route.');
$assert(str_contains($endpoint, 'StorageFactory::create($config)'), 'Compensation endpoint must use the active runtime storage owner for projection.');

$assert(str_contains($admin, 'data-compensation-api="../bot/admin-compensation.php"'), 'Web Admin must publish the compensation endpoint.');
$assert(str_contains($admin, 'data-compensation-operation'), 'Web Admin must keep exact original-operation lookup available.');
$assert(str_contains($admin, 'data-compensation-browser-query'), 'Web Admin must expose a human-usable source-operation browser.');
$assert(str_contains($admin, 'data-compensation-operations'), 'Web Admin must render recent canonical source operations.');
$assert(str_contains($admin, 'data-compensation-reason'), 'Web Admin must require a reason.');
$assert(str_contains($admin, 'data-compensation-confirmation'), 'Web Admin must expose the second-confirmation state.');
$assert(str_contains($admin, 'Баланс напрямую здесь не редактируется'), 'Admin copy must make the no-direct-balance-edit boundary explicit.');

$assert(str_contains($client, "action:'operations'"), 'Admin client must browse canonical source operations without requiring copied technical IDs.');
$assert(str_contains($client, 'chooseOperation'), 'Admin client must let an operator select a browsed source operation.');
$assert(str_contains($client, "data-compensation-operation-select"), 'Operation browser selections must have a stable control owner.');
$assert(str_contains($client, "querySelectorAll('[data-compensation-operation-select]')"), 'Busy-state recovery must re-enable non-selected operation buttons after async search.');
$assert(str_contains($client, "action:'lookup'"), 'Admin client must retain exact original-operation lookup.');
$assert(str_contains($client, "action:'request'"), 'Admin client must use a dedicated compensation request action.');
$assert(str_contains($client, "action:'confirm'"), 'Admin client must use a distinct confirmation request.');
$assert(str_contains($client, 'request_token'), 'Admin client must provide request idempotency.');
$assert(str_contains($client, 'pending_confirmation'), 'Admin client must render pending large compensations.');
$assert(str_contains($client, 'available_before'), 'Admin client must show the audited ledger balance transition.');

$assert(str_contains($entrypoints, "'bot/admin-compensation.php'"), 'Compensation endpoint must inherit the canonical API DB-primary route.');

fwrite(STDOUT, "MVP-22.2 compensation static contract OK ($assertions assertions).\n");
