<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/response.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$order = [];
$GLOBALS['mgw_api_success_hook'] = static function () use (&$order): void {
    $order[] = 'legacy';
};
$GLOBALS['mgw_api_success_hooks'] = [
    static function () use (&$order): void { $order[] = 'realtime'; },
    static function () use (&$order): void { $order[] = 'economy'; },
    'not-callable',
];

mgw_run_api_success_hooks();
$assertSame(['legacy', 'realtime', 'economy'], $order, 'All callable hooks must run once in stable order');
$assertSame(false, isset($GLOBALS['mgw_api_success_hook']), 'Legacy hook must be cleared before response');
$assertSame(false, isset($GLOBALS['mgw_api_success_hooks']), 'Hook list must be cleared before response');

mgw_run_api_success_hooks();
$assertSame(['legacy', 'realtime', 'economy'], $order, 'Repeated execution must not replay cleared hooks');

fwrite(STDOUT, "ApiSuccessHookPipelineTest: {$assertions} assertions passed\n");
