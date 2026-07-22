<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) {
    fwrite(STDERR, "Staging API mutating smoke request requires PHP 8.3.x.\n");
    exit(2);
}

$projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeInput.php';

final class RuntimePrimaryStagingApiMutatingSmokeInputStream
{
    public $context;
    private static string $projectRoot = '';
    private string $payload = '';
    private int $position = 0;

    public static function configure(string $projectRoot): void
    {
        self::$projectRoot = $projectRoot;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($path !== 'php://input' || $mode !== 'rb') {
            throw new RuntimeException('Staging API mutating smoke stream supports only php://input read access.');
        }
        $config = $GLOBALS['config'] ?? null;
        if (!is_array($config) || self::$projectRoot === '') {
            throw new RuntimeException('Staging API mutating smoke input stream is missing application context.');
        }
        $this->payload = RuntimePrimaryStagingApiMutatingSmokeInput::read(
            $config,
            self::$projectRoot
        );
        $this->position = 0;
        $openedPath = $path;
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($count < 1) return '';
        $chunk = substr($this->payload, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->payload);
    }

    public function stream_stat(): array
    {
        return ['size' => strlen($this->payload)];
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return $path === 'php://input' ? ['size' => strlen($this->payload)] : false;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->payload) + $offset,
            default => -1,
        };
        if ($target < 0 || $target > strlen($this->payload)) return false;
        $this->position = $target;
        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }
}

RuntimePrimaryStagingApiMutatingSmokeInputStream::configure($projectRoot);
if (!in_array('php', stream_get_wrappers(), true)) {
    fwrite(STDERR, "Built-in PHP stream wrapper is unavailable.\n");
    exit(1);
}
if (!stream_wrapper_unregister('php')) {
    fwrite(STDERR, "Built-in PHP stream wrapper could not be isolated.\n");
    exit(1);
}
if (!stream_wrapper_register('php', RuntimePrimaryStagingApiMutatingSmokeInputStream::class)) {
    stream_wrapper_restore('php');
    fwrite(STDERR, "Staging API mutating smoke input stream could not be registered.\n");
    exit(1);
}
register_shutdown_function(static function (): void {
    if (in_array('php', stream_get_wrappers(), true)) {
        @stream_wrapper_unregister('php');
    }
    @stream_wrapper_restore('php');
});

$_SERVER['SCRIPT_FILENAME'] = $projectRoot . '/bot/api.php';
$_SERVER['PHP_SELF'] = '/bot/api.php';
$_SERVER['SCRIPT_NAME'] = '/bot/api.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require $projectRoot . '/bot/api.php';
