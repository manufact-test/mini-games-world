<?php
declare(strict_types=1);

final class RuntimeMatchEventContext
{
    private static ?array $explicit = null;

    public static function begin(array $context): void
    {
        self::$explicit = self::normalize($context);
    }

    public static function clear(): void
    {
        self::$explicit = null;
    }

    public static function current(): ?array
    {
        if (self::$explicit !== null) {
            return self::$explicit;
        }
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $action = trim((string)($decoded['action'] ?? ''));
        if (!in_array($action, [
            'start_search',
            'game_state',
            'game_action',
            'make_move',
            'leave_game',
        ], true)) {
            return null;
        }

        $requestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $seconds = is_numeric($requestTime) ? (int)floor((float)$requestTime) : time();

        return self::normalize([
            'api_action' => $action,
            'occurred_at_utc' => gmdate('c', $seconds),
            'game_id' => trim((string)($decoded['gameId'] ?? '')),
            'game_action' => self::sanitizeGameAction($decoded),
        ]);
    }

    public static function normalize(array $context): array
    {
        $occurredAt = trim((string)($context['occurred_at_utc'] ?? ''));
        if ($occurredAt === '' || strtotime($occurredAt) === false) {
            $occurredAt = gmdate(DATE_ATOM);
        }

        return [
            'api_action' => trim((string)($context['api_action'] ?? '')),
            'occurred_at_utc' => $occurredAt,
            'game_id' => trim((string)($context['game_id'] ?? '')),
            'game_action' => is_array($context['game_action'] ?? null)
                ? self::sanitizeActionArray($context['game_action'])
                : [],
        ];
    }

    private static function sanitizeGameAction(array $payload): array
    {
        if (is_array($payload['gameAction'] ?? null)) {
            return self::sanitizeActionArray($payload['gameAction']);
        }

        $action = [];
        if (isset($payload['actionType'])) $action['type'] = $payload['actionType'];
        if (array_key_exists('cell', $payload)) $action['cell'] = $payload['cell'];
        if (array_key_exists('column', $payload)) $action['column'] = $payload['column'];
        return self::sanitizeActionArray($action);
    }

    private static function sanitizeActionArray(array $action): array
    {
        $safe = [];
        foreach ([
            'type',
            'cell',
            'column',
            'from',
            'to',
            'piece',
            'orientation',
            'size',
            'row',
            'x',
            'y',
            'pass',
        ] as $field) {
            if (!array_key_exists($field, $action)) continue;
            $value = $action[$field];
            if (is_scalar($value) || $value === null) {
                $safe[$field] = $value;
            }
        }
        return $safe;
    }
}
