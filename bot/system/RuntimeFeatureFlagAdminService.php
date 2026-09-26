<?php
declare(strict_types=1);

/**
 * Secure editor for the existing private runtime.php feature-flag owner.
 *
 * It does not create another flag store. RuntimeConfigLoader keeps reading the
 * same private runtime.php on every request and FeatureFlagService remains the
 * sole behavior reader.
 */
final class RuntimeFeatureFlagAdminService
{
    public const FEATURES = ['matchmaking','invitations','payments','shop','tournaments','ads'];
    public const GAMES = ['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'];

    public function __construct(private string $runtimeFile)
    {
        $this->runtimeFile = trim($this->runtimeFile);
        if ($this->runtimeFile === '') {
            throw new InvalidArgumentException('Runtime feature-flag path is required.');
        }
    }

    public function persistedSnapshot(): array
    {
        return $this->normalize($this->readRuntime());
    }

    public function update(array $input): array
    {
        $existing = $this->readRuntime();
        $before = $this->normalize($existing);
        $next = $existing;

        $normalized = $this->normalize($input);
        $next['maintenance_mode'] = $normalized['maintenance_mode'];
        $next['maintenance_message'] = $normalized['maintenance_message'];
        $next['financial_read_only'] = $normalized['financial_read_only'];

        $existingFeatures = is_array($next['features'] ?? null) ? $next['features'] : [];
        foreach (self::FEATURES as $feature) {
            $existingFeatures[$feature] = $normalized['features'][$feature];
        }
        $next['features'] = $existingFeatures;

        $existingGames = is_array($next['games'] ?? null) ? $next['games'] : [];
        foreach (self::GAMES as $game) {
            $existingGames[$game] = $normalized['games'][$game];
        }
        $next['games'] = $existingGames;

        $after = $this->normalize($next);
        if ($before !== $after || !is_file($this->runtimeFile)) {
            $this->writeRuntime($next);
        }

        return [
            'before'=>$before,
            'after'=>$after,
            'changed'=>$before !== $after,
        ];
    }

    private function readRuntime(): array
    {
        if (!is_file($this->runtimeFile)) return [];
        $runtime = require $this->runtimeFile;
        if (!is_array($runtime)) {
            throw new RuntimeException('Private runtime.php must return an array.');
        }
        return $runtime;
    }

    private function writeRuntime(array $runtime): void
    {
        $directory = dirname($this->runtimeFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Private runtime directory is not writable.');
        }

        $body = "<?php\ndeclare(strict_types=1);\n\n// Managed by MINI GAMES WORLD Web Admin.\nreturn "
            . var_export($runtime, true)
            . ";\n";
        $temp = $this->runtimeFile . '.mgw-' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temp, $body, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write runtime feature-flag temporary file.');
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $this->runtimeFile)) {
            @unlink($temp);
            throw new RuntimeException('Unable to atomically publish runtime feature flags.');
        }
    }

    private function normalize(array $source): array
    {
        $features = is_array($source['features'] ?? null) ? $source['features'] : [];
        $games = is_array($source['games'] ?? null) ? $source['games'] : [];

        $normalizedFeatures = [];
        foreach (self::FEATURES as $feature) {
            $default = !in_array($feature, ['tournaments','ads'], true);
            $normalizedFeatures[$feature] = $this->boolValue($features[$feature] ?? $default, $default);
        }

        $normalizedGames = [];
        foreach (self::GAMES as $game) {
            $normalizedGames[$game] = $this->boolValue($games[$game] ?? true, true);
        }

        return [
            'maintenance_mode'=>$this->boolValue($source['maintenance_mode'] ?? false, false),
            'maintenance_message'=>mb_substr(trim((string)($source['maintenance_message'] ?? '')), 0, 500),
            'financial_read_only'=>$this->boolValue($source['financial_read_only'] ?? false, false),
            'features'=>$normalizedFeatures,
            'games'=>$normalizedGames,
        ];
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1','true','yes','on','enabled'], true)) return true;
            if (in_array($value, ['0','false','no','off','disabled'], true)) return false;
        }
        return $default;
    }
}
