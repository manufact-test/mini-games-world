<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents(
    $root . '/runtime/ProductionPrimaryInviteResidualRecoveryService.php'
);
$factory = file_get_contents(
    $root . '/database/PdoConnectionFactory.php'
);

if (!is_string($service) || $service === ''
    || !is_string($factory) || $factory === '') {
    throw new RuntimeException(
        'Production recovery SQL contract sources are unavailable.'
    );
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($factory, 'PDO::ATTR_EMULATE_PREPARES => false'),
    'Production MySQL must continue using native prepared statements'
);

$assertTrue(
    str_contains(
        $service,
        'WHERE invite_id = :invite_id OR source_match_id = :source_match_id'
    ),
    'Recovery match-reference query must use distinct native placeholders'
);

$assertTrue(
    str_contains($service, "'invite_id' => \$inviteId")
        && str_contains($service, "'source_match_id' => \$inviteId"),
    'Recovery match-reference query must bind both distinct placeholders'
);

$assertTrue(
    !str_contains(
        $service,
        'WHERE invite_id = :invite_id OR source_match_id = :invite_id'
    ),
    'Recovery must not reuse one named placeholder under native MySQL prepares'
);

fwrite(
    STDOUT,
    "ProductionPrimaryInviteResidualRecoveryMysqlPlaceholderContractTest: "
    . "{$assertions} assertions passed\n"
);
