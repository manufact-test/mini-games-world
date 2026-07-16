<?php
declare(strict_types=1);

final class RuntimeConfigLoader
{
    public static function merge(array $config, string $primaryConfigFile): array
    {
        $primaryConfigFile = trim($primaryConfigFile);
        if ($primaryConfigFile === '') return $config;

        $runtimeFile = dirname($primaryConfigFile) . '/runtime.php';
        if (!is_file($runtimeFile)) return $config;

        $runtime = require $runtimeFile;
        if (!is_array($runtime)) {
            throw new RuntimeException('Mini Games World runtime config must return an array.');
        }

        $existing = $config['feature_flags'] ?? [];
        if (!is_array($existing)) $existing = [];
        $config['feature_flags'] = array_replace_recursive($existing, $runtime);
        return $config;
    }
}
