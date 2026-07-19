<?php
declare(strict_types=1);

trait FrozenSnapshotDataTrait
{
    private function dataFingerprint(string $directory): array
    {
        $directory = rtrim(str_replace('\\', '/', trim($directory)), '/');
        if (!is_dir($directory)) throw new RuntimeException('Snapshot data directory is missing.');
        $entries = [];
        $bytes = 0;
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) continue;
            $raw = file_get_contents($path);
            if (!is_string($raw)) throw new RuntimeException('Could not read snapshot JSON file.');
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('Snapshot JSON must contain an array.');
            $entries[basename($path)] = hash('sha256', $raw);
            $bytes += strlen($raw);
        }
        if ($entries === []) throw new RuntimeException('Snapshot data directory contains no JSON files.');
        ksort($entries, SORT_STRING);
        $lines = [];
        foreach ($entries as $name => $hash) $lines[] = $name . ':' . $hash;
        return [
            'file_count' => count($entries),
            'bytes' => $bytes,
            'fingerprint' => hash('sha256', implode("\n", $lines) . "\n"),
        ];
    }
}
