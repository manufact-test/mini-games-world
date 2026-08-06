<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$base = $root . '/app/assets/js';
$matches = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') continue;
    $content = file_get_contents($file->getPathname());
    if (!is_string($content)) continue;
    if (!preg_match_all("~(?:\.\./|\./)?api/client\.js\?v=([0-9.]+)~", $content, $found)) continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach (array_unique($found[1]) as $version) {
        $matches[] = ['path' => $relative, 'version' => $version];
    }
}
usort($matches, static fn(array $a, array $b): int => [$a['version'], $a['path']] <=> [$b['version'], $b['path']]);
fwrite(STDOUT, "MGW_API_CLIENT_IMPORT_GRAPH=" . json_encode($matches, JSON_UNESCAPED_SLASHES) . "\n");
throw new RuntimeException('Intentional API client import graph diagnostic.');
