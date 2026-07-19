<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/notifications/RuntimeNotificationRepository.php';

$assertions = 0;
$assertTrue = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if (!$value) throw new RuntimeException($message);
};

foreach (['synchronizeAndList', 'auditParity'] as $methodName) {
    $method = new ReflectionMethod(RuntimeNotificationRepository::class, $methodName);
    $parameter = $method->getParameters()[1] ?? null;
    $assertTrue($parameter instanceof ReflectionParameter, $methodName . ' must expose a user ID parameter');

    $type = $parameter->getType();
    $assertTrue($type instanceof ReflectionUnionType, $methodName . ' must accept both string and integer user IDs');
    $names = array_map(static fn(ReflectionNamedType $item): string => $item->getName(), $type->getTypes());
    sort($names, SORT_STRING);
    $assertTrue($names === ['int', 'string'], $methodName . ' user ID type must normalize numeric JSON keys safely');
}

fwrite(STDOUT, "RuntimeNotificationNumericIdContractTest passed: {$assertions} assertions.\n");
