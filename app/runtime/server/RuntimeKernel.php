<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

final readonly class RuntimeKernel
{
    public function __construct(private RuntimeApplicationService $application) {}

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $method, string $action, array $payload): array
    {
        $method = strtoupper(trim($method));
        $action = strtolower(trim($action));

        if ($method === 'GET' && $action === 'health') {
            return ['status' => 200, 'body' => $this->application->health()];
        }
        if ($method !== 'POST') {
            return $this->notFound();
        }

        $body = match ($action) {
            'bootstrap' => $this->application->bootstrap($payload),
            'heartbeat' => $this->application->heartbeat($payload),
            'match_start_search' => $this->application->startSearch($payload),
            'match_cancel_search' => $this->application->cancelSearch($payload),
            'match_sync' => $this->application->syncMatch($payload),
            'match_move' => $this->application->move($payload),
            'match_surrender' => $this->application->surrender($payload),
            'match_dismiss_result' => $this->application->dismissResult($payload),
            default => null,
        };

        return $body !== null ? ['status' => 200, 'body' => $body] : $this->notFound();
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function notFound(): array
    {
        return [
            'status' => 404,
            'body' => [
                'ok' => false,
                'error' => 'Unknown clean runtime action.',
            ],
        ];
    }
}
