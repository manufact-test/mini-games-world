<?php
declare(strict_types=1);

final class RuntimeConfigLoader
{
    public static function merge(array $config, string $primaryConfigFile): array
    {
        $primaryConfigFile = trim($primaryConfigFile);
        if ($primaryConfigFile === '') return $config;

        $runtimeFile = dirname($primaryConfigFile) . '/runtime.php';
        if (is_file($runtimeFile)) {
            $runtime = require $runtimeFile;
            if (!is_array($runtime)) {
                throw new RuntimeException('Mini Games World runtime config must return an array.');
            }
            $existing = $config['feature_flags'] ?? [];
            if (!is_array($existing)) $existing = [];
            $config['feature_flags'] = array_replace_recursive($existing, $runtime);
        }

        $controlFile = dirname($primaryConfigFile) . '/cutover-rehearsal.json';
        if (!is_file($controlFile)) return $config;

        $raw = file_get_contents($controlFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Cutover rehearsal control file is empty.');
        }
        try {
            $control = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Cutover rehearsal control file is invalid JSON.', 0, $error);
        }
        if (!is_array($control)) {
            throw new RuntimeException('Cutover rehearsal control must be an object.');
        }
        if ((string)($control['state'] ?? '') !== 'frozen') return $config;

        $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
        $controlEnvironment = strtolower(trim((string)($control['environment'] ?? '')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Cutover rehearsal freeze is forbidden outside staging/local.');
        }
        if ($controlEnvironment === '' || $controlEnvironment !== $environment) {
            throw new RuntimeException('Cutover rehearsal environment does not match runtime environment.');
        }

        $existing = $config['feature_flags'] ?? [];
        if (!is_array($existing)) $existing = [];
        $config['feature_flags'] = array_replace_recursive($existing, [
            'features' => [
                'matchmaking' => false,
                'invitations' => false,
            ],
            'cutover_rehearsal' => [
                'active' => true,
                'rehearsal_id' => (string)($control['rehearsal_id'] ?? ''),
                'started_at_utc' => (string)($control['started_at_utc'] ?? ''),
            ],
        ]);

        return $config;
    }
}
