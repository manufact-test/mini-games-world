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
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$GLOBALS['mgw_api_data_filters'] = [
    static function (array $data): array {
        $data['sequence'][] = 'database';
        return $data;
    },
    static function (array $data): array {
        $data['sequence'][] = 'format';
        return $data;
    },
];
$result = mgw_normalize_api_data(['sequence' => []]);
$assertSame(['database', 'format'], $result['sequence'], 'API data filters must run in registration order');
$assertSame(false, array_key_exists('mgw_api_data_filters', $GLOBALS), 'API data filters must be consumed once');

$repeat = mgw_normalize_api_data(['sequence' => []]);
$assertSame([], $repeat['sequence'], 'Consumed API filters must not run twice');

$GLOBALS['mgw_api_data_filters'] = [static fn(array $data): string => 'invalid'];
$assertThrows(
    static fn() => mgw_normalize_api_data([]),
    'must return an array',
    'Non-array API filter output must fail closed'
);
unset($GLOBALS['mgw_api_data_filters']);

fwrite(STDOUT, "ApiDataFilterPipelineTest passed: {$assertions} assertions.\n");
