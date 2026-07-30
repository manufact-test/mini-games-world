<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

use Mgw\CleanRuntime\Server\Contracts\RuntimeRepository;

final readonly class RuntimeBootstrapService
{
    public function __construct(
        private RuntimeConfig $config,
        private RuntimeRepository $repository,
    ) {}

    /** @param array<string,mixed> $payload */
    public function bootstrap(array $payload): array
    {
        $installationId = trim((string)($payload['installation_id'] ?? ''));
        $launch = is_array($payload['launch'] ?? null) ? $payload['launch'] : [];

        $projection = $this->repository->bootstrap($installationId, [
            'runtime' => $this->boundedString($launch['runtime'] ?? '', 40),
            'path' => $this->boundedPath($launch['path'] ?? ''),
            'source' => $this->normalizeSource($launch['source'] ?? ''),
            'invite_present' => (bool)($launch['invite_present'] ?? false),
            'telegram_available' => (bool)($launch['telegram_available'] ?? false),
        ]);

        return [
            'ok' => true,
            'request_id' => bin2hex(random_bytes(8)),
            'server_time' => gmdate('c'),
            'server' => [
                'runtime' => 'mgw-clean-v1',
                'build' => $this->config->build,
                'environment' => $this->config->environment,
            ],
            ...$projection,
        ];
    }

    public function health(): array
    {
        return [
            'ok' => true,
            'server_time' => gmdate('c'),
            'server' => [
                'runtime' => 'mgw-clean-v1',
                'build' => $this->config->build,
                'environment' => $this->config->environment,
            ],
            'storage' => $this->repository->health(),
        ];
    }

    private function boundedString(mixed $value, int $maxLength): string
    {
        return mb_substr(trim((string)$value), 0, $maxLength);
    }

    private function boundedPath(mixed $value): string
    {
        $path = $this->boundedString($value, 180);
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('Invalid clean runtime launch path.');
        }
        return $path;
    }

    private function normalizeSource(mixed $value): string
    {
        $source = strtolower(trim((string)$value));
        return in_array($source, ['standard', 'invite'], true) ? $source : 'standard';
    }
}
