<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Context;

use Mgw\CleanRuntime\Server\Auth\RuntimeAuthenticationService;

final readonly class RuntimeRequestContextFactory
{
    public function __construct(private RuntimeAuthenticationService $authentication) {}

    /** @param array<string,mixed> $payload */
    public function fromPayload(array $payload): RuntimeRequestContext
    {
        $installationId = $this->identifier($payload['installation_id'] ?? '', 'installation');
        $sessionId = $this->identifier($payload['session_id'] ?? '', 'session');
        $identity = $this->authentication->authenticate($payload, $installationId);
        $presence = is_array($payload['presence'] ?? null) ? $payload['presence'] : [];

        return new RuntimeRequestContext(
            identity: $identity->toRecord(),
            installationId: $installationId,
            sessionId: $sessionId,
            presence: $this->normalizePresence($presence),
        );
    }

    /** @param array<string,mixed> $presence @return array{visibility:string,platform:string,timezone_offset:int} */
    private function normalizePresence(array $presence): array
    {
        $visibility = strtolower($this->bounded($presence['visibility'] ?? 'unknown', 16));
        if (!in_array($visibility, ['visible', 'hidden', 'prerender', 'unknown'], true)) {
            $visibility = 'unknown';
        }

        $platform = strtolower($this->bounded($presence['platform'] ?? 'unknown', 32));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $platform)) {
            $platform = 'unknown';
        }

        return [
            'visibility' => $visibility,
            'platform' => $platform,
            'timezone_offset' => max(-840, min(840, (int)($presence['timezone_offset'] ?? 0))),
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

    private function bounded(mixed $value, int $limit): string
    {
        return substr(trim((string)$value), 0, $limit);
    }
}
