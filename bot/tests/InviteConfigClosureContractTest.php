<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/bot/invites.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('Unable to read bot/invites.php');
}

$pattern = '/\$db->transaction\(function\s*\(array\s*&\$data\)\s*use\s*\((.*?)\)\s*:\s*array\s*\{/s';
if (preg_match($pattern, $source, $matches) !== 1) {
    throw new RuntimeException('Invite transaction closure not found.');
}

$useList = (string)($matches[1] ?? '');
if (preg_match('/(?:^|,)\s*\$config\s*(?:,|$)/m', $useList) !== 1) {
    throw new RuntimeException('Invite transaction must capture application $config.');
}

if (!str_contains($source, 'UnifiedGameZonePolicy::entryCost($config)')) {
    throw new RuntimeException('Invite entry cost must keep the typed application config owner.');
}

if (str_contains($source, 'UnifiedGameZonePolicy::entryCost($config ??')) {
    throw new RuntimeException('Invite entry cost must not hide missing config behind a fallback.');
}

fwrite(STDOUT, "Invite config closure contract: OK\n");
