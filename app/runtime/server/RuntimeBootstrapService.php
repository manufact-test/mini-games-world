<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

use Mgw\CleanRuntime\Server\Auth\RuntimeAuthenticationService;
use Mgw\CleanRuntime\Server\Contracts\RuntimeRepository;

final readonly class RuntimeBootstrapService
{
    public function __construct(
        private RuntimeConfig $config,
        private RuntimeRepository $repository,
        private RuntimeAuthenticationService $authentication,
    ) {}

    /** @param array<string,mixed> $payload */
    public function bootstrap(array $payload): array
    {
        $installationId = $this->identifier($payload['installation_id'] ?? '', 'installation');
        $sessionId = $this->identifier($payload['session_id'] ?? '', 'session');
        $identity = $this->authentication->authenticate($payload, $installationId);
        $launch = is_array($payload['launch'] ?? null) ? $payload['launch'] : [];
        $presence = is_array($payload['presence'] ?? null) ? $payload['presence'] : [];

        $projection = $this->repository->bootstrap(
            $identity->toRecord(),
            $installationId,
            $sessionId,
            [
                'runtime' => $this->boundedString($launch['runtime'] ?? '', 40),
                'path' => $this->boundedPath($launch['path'] ?? ''),
                'source' => $this->normalizeSource($launch['source'] ?? ''),
                'invite_present' => (bool)($launch['invite_present'] ?? false),
                'telegram_available' => (bool)($launch['telegram_available'] ?? false),
            ],
            $this->normalizePresence($presence),
            $this->config->sessionTimeoutSec,
            $this->config->presenceTtlSec,
        );

        return $this->success($projection);
    }

    /** @param array<string,mixed> $payload */
    public function heartbeat(array $payload): array
    {
        $installationId = $this->identifier($payload['installation_id'] ?? '', 'installation');
        $sessionId = $this->identifier($payload['session_id'] ?? '', 'session');
        $identity = $this->authentication->authenticate($payload, $installationId);
        $presence = is_array($payload['presence'] ?? null) ? $payload['presence'] : [];

        $projection = $this->repository->heartbeat(
            $identity->toRecord(),
            $installationId,
            $sessionId,
            $this->normalizePresence($presence),
            $this->config->sessionTimeoutSec,
            $this->config->presenceTtlSec,
        );

        return $this->success($projection);
    }

    public function health(): array
    {
        return [
            'ok' => true,
            'server_time' => gmdate('c'),
            'server' => $this->serverProjection(),
            'storage' => $this->repository->health(),
        ];
    }

    /** @param array<string,mixed> $projection @return array<string,mixed> */
    private function success(array $projection): array
    {
        return [
            'ok' => true,
            'request_id' => bin2hex(random_bytes(8)),
            'server_time' => gmdate('c'),
            'server' => $this->serverProjection(),
            ...$projection,
        ];
    }

    /** @return array<string,string> */
    private function serverProjection(): array
    {
        return [
            'runtime' => 'mgw-clean-v1',
            'build' => $this->config->build,
            'environment' => $this->config->environment,
        ];
    }

    /** @param array<string,mixed> $presence @return array{visibility:string,platform:string,timezone_offset:int} */
    private function normalizePresence(array $presence): array
    {
        $visibility = strtolower($this->boundedString($presence['visibility'] ?? 'unknown', 16));
        if (!in_array($visibility, ['visible', 'hidden', 'prerender', 'unknown'], true)) {
            $visibility = 'unknown';
        }
        $platform = strtolower($this->boundedString($presence['platform'] ?? 'unknown', 32));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $platform)) {
            $platform = 'unknown';
        }
        $timezoneOffset = max(-840, min(840, (int)($presence['timezone_offset'] ?? 0)));
        return [
            'visibility' => $visibility,
            'platform' => $platform,
            'timezone_offset' => $timezoneOffset,
        ];
    }

    private function identifier(mixed $value, string $label): string
    {
        $identifier = trim((string)$value);
        if (!preg_match('/^[a-zA-Z0-9_-]{20,96}$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid clean runtime ' . $label . ' identifier.');
        }
        return $identifier;
    }

    private function boundedString(mixed $value, int $maxLength): string
    {
        return substr(trim((string)$value), 0, $maxLength);
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
