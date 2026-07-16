<?php
declare(strict_types=1);

final class DatabaseConfig
{
    private function __construct(
        private bool $enabled,
        private string $driver,
        private string $host,
        private int $port,
        private string $name,
        private string $user,
        private string $password,
        private string $charset
    ) {}

    public static function fromApplicationConfig(array $config): self
    {
        $database = $config['database'] ?? [];
        if ($database === null || $database === false) {
            $database = [];
        }
        if (!is_array($database)) {
            throw new RuntimeException('Database configuration must be an array.');
        }

        $enabled = self::boolValue($database['enabled'] ?? false);
        $driver = strtolower(trim((string)($database['driver'] ?? 'mysql')));
        if ($driver === 'mariadb') {
            $driver = 'mysql';
        }
        if (!in_array($driver, ['mysql'], true)) {
            throw new RuntimeException('Unsupported database driver.');
        }

        $host = trim((string)($database['host'] ?? ''));
        $port = filter_var($database['port'] ?? 3306, FILTER_VALIDATE_INT);
        $name = trim((string)($database['name'] ?? ''));
        $user = trim((string)($database['user'] ?? ''));
        $password = (string)($database['password'] ?? '');
        $charset = strtolower(trim((string)($database['charset'] ?? 'utf8mb4')));

        if ($enabled) {
            if ($host === '' || $name === '' || $user === '' || $password === '') {
                throw new RuntimeException('Enabled database configuration is incomplete.');
            }
            if ($port === false || $port < 1 || $port > 65535) {
                throw new RuntimeException('Database port is invalid.');
            }
            if ($charset !== 'utf8mb4') {
                throw new RuntimeException('Mini Games World database charset must be utf8mb4.');
            }
            foreach ([$name, $user] as $identifier) {
                if (preg_match('/^[A-Za-z0-9_$.-]{1,128}$/', $identifier) !== 1) {
                    throw new RuntimeException('Database name or user contains unsupported characters.');
                }
            }
        }

        return new self(
            $enabled,
            $driver,
            $host,
            $port === false ? 3306 : (int)$port,
            $name,
            $user,
            $password,
            $charset
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function dsn(): string
    {
        if (!$this->enabled) {
            throw new RuntimeException('Database is not enabled.');
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->name,
            $this->charset
        );
    }

    public function user(): string
    {
        return $this->user;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function safeSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'driver' => $this->driver,
            'configured' => $this->enabled
                && $this->host !== ''
                && $this->name !== ''
                && $this->user !== ''
                && $this->password !== '',
            'charset' => $this->charset,
        ];
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (!is_string($value)) return false;

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
