<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/admin-read.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('admin-read.php unavailable.');
}

$start = strpos($source, 'function mgw_admin_init_data_is_fresh');
$end = strpos($source, "\nfunction mgw_admin_read_only_text", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Admin freshness helper unavailable.');
}

eval(substr($source, $start, $end - $start));

$now = 1_800_000_000;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(mgw_admin_init_data_is_fresh('auth_date=' . $now, $now), 'Current auth_date must be accepted.');
$assert(mgw_admin_init_data_is_fresh('auth_date=' . ($now - 900), $now), 'Exact 15-minute auth_date must be accepted.');
$assert(!mgw_admin_init_data_is_fresh('auth_date=' . ($now - 901), $now), 'Older than 15 minutes must be rejected.');
$assert(mgw_admin_init_data_is_fresh('auth_date=' . ($now + 60), $now), 'Exact allowed future skew must be accepted.');
$assert(!mgw_admin_init_data_is_fresh('auth_date=' . ($now + 61), $now), 'Future auth_date outside allowed skew must be rejected.');
$assert(!mgw_admin_init_data_is_fresh('', $now), 'Empty initData must be rejected.');
$assert(!mgw_admin_init_data_is_fresh('auth_date=nope', $now), 'Invalid auth_date must be rejected.');
$assert(!mgw_admin_init_data_is_fresh('foo=bar', $now), 'Missing auth_date must be rejected.');

fwrite(STDOUT, "Mvp14WebAdminShellFreshnessTest: {$assertions} assertions passed\n");
