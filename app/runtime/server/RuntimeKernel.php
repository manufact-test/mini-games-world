<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

final readonly class RuntimeKernel
{
    public function __construct(private RuntimeBootstrapService $bootstrapService) {}

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $method, string $action, array $payload): array
    {
        $method = strtoupper(trim($method));
        $action = strtolower(trim($action));

        if ($method === 'GET' && $action === 'health') {
            return ['status' => 200, 'body' => $this->bootstrapService->health()];
        }
        if ($method === 'POST' && $action === 'bootstrap') {
            return ['status' => 200, 'body' => $this->bootstrapService->bootstrap($payload)];
        }
        if ($method === 'POST' && $action === 'heartbeat') {
            return ['status' => 200, 'body' => $this->bootstrapService->heartbeat($payload)];
        }

        return [
            'status' => 404,
            'body' => [
                'ok' => false,
                'error' => 'Unknown clean runtime action.',
            ],
        ];
    }
}
