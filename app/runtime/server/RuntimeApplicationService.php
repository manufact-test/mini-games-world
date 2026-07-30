<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

use Mgw\CleanRuntime\Server\Context\RuntimeRequestContextFactory;
use Mgw\CleanRuntime\Server\Contracts\RuntimeStateStore;
use Mgw\CleanRuntime\Server\Match\RuntimeMatchService;
use Mgw\CleanRuntime\Server\Session\RuntimeSessionService;

final readonly class RuntimeApplicationService
{
    public function __construct(
        private RuntimeConfig $config,
        private RuntimeStateStore $store,
        private RuntimeRequestContextFactory $contexts,
        private RuntimeSessionService $sessions,
        private RuntimeMatchService $matches,
    ) {}

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function bootstrap(array $payload): array
    {
        $context = $this->contexts->fromPayload($payload);
        $launch = is_array($payload['launch'] ?? null) ? $payload['launch'] : [];
        $nowEpoch = time();

        $projection = $this->store->transaction(function (array &$state) use ($context, $launch, $nowEpoch): array {
            $session = $this->sessions->bootstrap($state, $context, [
                'runtime' => $this->bounded($launch['runtime'] ?? '', 40),
                'path' => $this->path($launch['path'] ?? ''),
                'source' => $this->source($launch['source'] ?? ''),
                'invite_present' => (bool)($launch['invite_present'] ?? false),
                'telegram_available' => (bool)($launch['telegram_available'] ?? false),
            ], $nowEpoch);
            $this->matches->reconcile($state, $context->accountId(), $nowEpoch);
            return $this->mergeProjection($state, $session, $this->matches->projection($state, $context->accountId(), $nowEpoch));
        });
        return $this->success($projection);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function heartbeat(array $payload): array
    {
        $context = $this->contexts->fromPayload($payload);
        $nowEpoch = time();
        $projection = $this->store->transaction(function (array &$state) use ($context, $nowEpoch): array {
            $session = $this->sessions->heartbeat($state, $context, $nowEpoch);
            $this->matches->reconcile($state, $context->accountId(), $nowEpoch);
            return $this->mergeProjection($state, $session, $this->matches->projection($state, $context->accountId(), $nowEpoch));
        });
        return $this->success($projection);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function startSearch(array $payload): array
    {
        return $this->mutateMatch($payload, fn(array &$state, $context, int $now): array =>
            $this->matches->startSearch($state, $context, (string)($payload['command_id'] ?? ''), $now)
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function cancelSearch(array $payload): array
    {
        return $this->mutateMatch($payload, fn(array &$state, $context, int $now): array =>
            $this->matches->cancelSearch($state, $context, (string)($payload['command_id'] ?? ''), $now)
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function syncMatch(array $payload): array
    {
        $context = $this->contexts->fromPayload($payload);
        $nowEpoch = time();
        $projection = $this->store->transaction(function (array &$state) use ($context, $nowEpoch): array {
            $session = $this->sessions->heartbeat($state, $context, $nowEpoch);
            $match = $this->matches->sync($state, $context, $nowEpoch);
            return $this->mergeProjection($state, $session, $match);
        });
        return $this->success($projection);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function move(array $payload): array
    {
        return $this->mutateMatch($payload, fn(array &$state, $context, int $now): array =>
            $this->matches->move(
                $state,
                $context,
                (string)($payload['game_id'] ?? ''),
                (int)($payload['cell'] ?? -1),
                (string)($payload['command_id'] ?? ''),
                $now,
            )
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function surrender(array $payload): array
    {
        return $this->mutateMatch($payload, fn(array &$state, $context, int $now): array =>
            $this->matches->surrender(
                $state,
                $context,
                (string)($payload['game_id'] ?? ''),
                (string)($payload['command_id'] ?? ''),
                $now,
            )
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function dismissResult(array $payload): array
    {
        return $this->mutateMatch($payload, fn(array &$state, $context, int $now): array =>
            $this->matches->dismissResult($state, $context, (string)($payload['command_id'] ?? ''), $now)
        );
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return [
            'ok' => true,
            'server_time' => gmdate('c'),
            'server' => $this->serverProjection(),
            'storage' => $this->store->health(),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param callable(array<string,mixed>&,mixed,int):array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function mutateMatch(array $payload, callable $operation): array
    {
        $context = $this->contexts->fromPayload($payload);
        $nowEpoch = time();
        $projection = $this->store->transaction(function (array &$state) use ($context, $nowEpoch, $operation): array {
            $this->sessions->assertCanMutate($state, $context, $nowEpoch);
            $match = $operation($state, $context, $nowEpoch);
            $session = $this->sessions->currentProjection($state, $context, $nowEpoch);
            return $this->mergeProjection($state, $session, $match);
        });
        return $this->success($projection);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $session @param array<string,mixed> $match */
    private function mergeProjection(array $state, array $session, array $match): array
    {
        return [
            ...$session,
            ...$match,
            'storage' => [
                'adapter' => 'json_file_staging',
                'schema_version' => (int)($state['schema_version'] ?? 3),
                'revision' => (int)($state['revision'] ?? 0),
            ],
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

    private function bounded(mixed $value, int $limit): string
    {
        return substr(trim((string)$value), 0, $limit);
    }

    private function path(mixed $value): string
    {
        $path = $this->bounded($value, 180);
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('Invalid clean runtime launch path.');
        }
        return $path;
    }

    private function source(mixed $value): string
    {
        $source = strtolower(trim((string)$value));
        return in_array($source, ['standard', 'invite'], true) ? $source : 'standard';
    }
}
