<?php
declare(strict_types=1);

final class GameReactionException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class GameReactionService
{
    public const SLOT = 'profile_reaction_set';
    private const COOLDOWN_MS = 900;
    private const EVENT_TTL_MS = 5000;

    private const REACTIONS = [
        'wave' => ['glyph' => '👋', 'label' => 'Привет'],
        'clap' => ['glyph' => '👏', 'label' => 'Браво'],
        'heart' => ['glyph' => '💜', 'label' => 'Сердце'],
        'fire' => ['glyph' => '🔥', 'label' => 'Огонь'],
        'target' => ['glyph' => '🎯', 'label' => 'Точно'],
        'spark' => ['glyph' => '✨', 'label' => 'Вау'],
        'crown' => ['glyph' => '👑', 'label' => 'Корона'],
        'handshake' => ['glyph' => '🤝', 'label' => 'Хорошая игра'],
    ];

    public function __construct(private array $config, private DatabaseConnectionInterface $database) {}

    public function send(string $mgwId, string $providerUserId, string $gameId, string $code): array
    {
        $gameId = trim($gameId);
        $providerUserId = trim($providerUserId);
        $code = strtolower(trim($code));
        if ($gameId === '' || $providerUserId === '' || !isset(self::REACTIONS[$code])) {
            throw new GameReactionException(422, 'Некорректная реакция.');
        }

        $game = $this->activeHumanGameForParticipant($gameId, $providerUserId);
        $allowed = $this->allowedReactionCodes($mgwId);
        if (!in_array($code, $allowed, true)) {
            throw new GameReactionException(403, 'Эта реакция не входит в выбранный набор.');
        }

        $now = (int)floor(microtime(true) * 1000);
        $path = $this->storagePath();
        $handle = @fopen($path, 'c+b');
        if ($handle === false) throw new GameReactionException(503, 'Реакции временно недоступны.');

        try {
            if (!flock($handle, LOCK_EX)) throw new GameReactionException(503, 'Реакции временно недоступны.');
            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = json_decode(is_string($raw) && trim($raw) !== '' ? $raw : '{}', true);
            if (!is_array($state)) $state = [];
            $events = is_array($state['events'] ?? null) ? $state['events'] : [];

            foreach ($events as $id => $event) {
                if (!is_array($event) || $now - (int)($event['created_at_ms'] ?? 0) > self::EVENT_TTL_MS * 3) unset($events[$id]);
            }

            $previous = $events[$gameId] ?? null;
            if (is_array($previous)
                && (string)($previous['sender_id'] ?? '') === $providerUserId
                && $now - (int)($previous['created_at_ms'] ?? 0) < self::COOLDOWN_MS) {
                throw new GameReactionException(429, 'Подождите секунду перед следующей реакцией.');
            }

            $seq = max((int)($state['seq'] ?? 0) + 1, $now);
            $definition = self::REACTIONS[$code];
            $event = [
                'seq' => $seq,
                'game_id' => $gameId,
                'sender_id' => $providerUserId,
                'code' => $code,
                'glyph' => (string)$definition['glyph'],
                'label' => (string)$definition['label'],
                'created_at_ms' => $now,
            ];
            $events[$gameId] = $event;
            $state = ['seq' => $seq, 'events' => $events];

            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            return $event;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function latest(string $gameId): ?array
    {
        $gameId = trim($gameId);
        if ($gameId === '') return null;
        $path = $this->storagePath();
        if (!is_file($path)) return null;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return null;
        try {
            if (!flock($handle, LOCK_SH | LOCK_NB)) return null;
            $raw = stream_get_contents($handle);
            $state = json_decode(is_string($raw) && trim($raw) !== '' ? $raw : '{}', true);
            $event = is_array($state) && is_array($state['events'][$gameId] ?? null) ? $state['events'][$gameId] : null;
            if (!is_array($event)) return null;
            if ((int)floor(microtime(true) * 1000) - (int)($event['created_at_ms'] ?? 0) > self::EVENT_TTL_MS) return null;
            return $event;
        } catch (Throwable $error) {
            return null;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function allowedReactionCodes(string $mgwId): array
    {
        $inventory = (new ProductInventoryService($this->database))->snapshot($mgwId);
        $itemId = strtolower(trim((string)($inventory['equipped'][self::SLOT] ?? '')));
        if ($itemId === '') return [];
        foreach ((array)($inventory['catalog'] ?? []) as $item) {
            if (!is_array($item) || (string)($item['item_id'] ?? '') !== $itemId) continue;
            if ((string)($item['item_type'] ?? '') !== 'profile'
                || (string)($item['item_family'] ?? '') !== 'reaction'
                || (string)($item['equip_slot'] ?? '') !== self::SLOT
                || empty($item['owned'])) return [];
            $codes = is_array($item['metadata']['reactions'] ?? null) ? $item['metadata']['reactions'] : [];
            return array_values(array_filter(array_map(
                static fn($value): string => strtolower(trim((string)$value)),
                $codes
            ), static fn(string $value): bool => isset(self::REACTIONS[$value])));
        }
        return [];
    }

    private function activeHumanGameForParticipant(string $gameId, string $providerUserId): array
    {
        $storage = new JsonStorageAdapter((string)($this->config['data_dir'] ?? ''));
        $game = $storage->readOnlySections(['games'], static function (array $data) use ($gameId): ?array {
            $candidate = $data['games'][$gameId] ?? null;
            return is_array($candidate) ? $candidate : null;
        });
        if (!is_array($game) || (string)($game['status'] ?? '') !== 'active') {
            throw new GameReactionException(409, 'Матч уже завершён.');
        }
        if (!in_array($providerUserId, array_map('strval', (array)($game['player_ids'] ?? [])), true)) {
            throw new GameReactionException(403, 'Вы не участвуете в этой игре.');
        }
        if (!empty($game['is_bot_game'])) {
            throw new GameReactionException(409, 'Реакции доступны в матчах с игроками.');
        }
        return $game;
    }

    private function storagePath(): string
    {
        $dataDir = rtrim((string)($this->config['data_dir'] ?? (dirname(__DIR__) . '/data')), DIRECTORY_SEPARATOR);
        if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);
        return $dataDir . DIRECTORY_SEPARATOR . 'profile-reactions.json';
    }
}
