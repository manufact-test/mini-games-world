<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

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
        'wave' => ['glyph' => '👋', 'label_key' => 'profile.reactions.codes.wave'],
        'clap' => ['glyph' => '👏', 'label_key' => 'profile.reactions.codes.clap'],
        'heart' => ['glyph' => '💜', 'label_key' => 'profile.reactions.codes.heart'],
        'fire' => ['glyph' => '🔥', 'label_key' => 'profile.reactions.codes.fire'],
        'target' => ['glyph' => '🎯', 'label_key' => 'profile.reactions.codes.target'],
        'spark' => ['glyph' => '✨', 'label_key' => 'profile.reactions.codes.spark'],
        'crown' => ['glyph' => '👑', 'label_key' => 'profile.reactions.codes.crown'],
        'handshake' => ['glyph' => '🤝', 'label_key' => 'profile.reactions.codes.handshake'],
    ];

    public function __construct(private array $config, private DatabaseConnectionInterface $database) {}

    public function send(string $mgwId, string $providerUserId, string $gameId, string $code): array
    {
        $gameId = trim($gameId);
        $providerUserId = trim($providerUserId);
        $code = strtolower(trim($code));
        if ($gameId === '' || $providerUserId === '' || !isset(self::REACTIONS[$code])) {
            throw new GameReactionException(422, ServerLocalization::copy('server.game_runtime.reactions.invalid', 'Invalid reaction.'));
        }

        $this->activeGameForParticipant($gameId, $providerUserId);
        $allowed = $this->allowedReactionCodes($mgwId);
        if (!in_array($code, $allowed, true)) {
            throw new GameReactionException(403, ServerLocalization::copy('server.game_runtime.reactions.not_owned', 'This reaction has not been purchased yet.'));
        }

        $now = (int)floor(microtime(true) * 1000);
        $path = $this->storagePath();
        $handle = @fopen($path, 'c+b');
        if ($handle === false) throw new GameReactionException(503, ServerLocalization::copy('server.game_runtime.reactions.unavailable', 'Reactions are temporarily unavailable.'));

        try {
            if (!flock($handle, LOCK_EX)) throw new GameReactionException(503, ServerLocalization::copy('server.game_runtime.reactions.unavailable', 'Reactions are temporarily unavailable.'));
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
                throw new GameReactionException(429, ServerLocalization::copy('server.game_runtime.reactions.cooldown', 'Wait a second before sending another reaction.'));
            }

            $seq = max((int)($state['seq'] ?? 0) + 1, $now);
            $definition = self::REACTIONS[$code];
            $event = [
                'seq' => $seq,
                'game_id' => $gameId,
                'sender_id' => $providerUserId,
                'code' => $code,
                'glyph' => (string)$definition['glyph'],
                'label' => ServerLocalization::copy((string)$definition['label_key'], (string)$code),
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
        $allowed = [];
        foreach ((array)($inventory['catalog'] ?? []) as $item) {
            if (!is_array($item)
                || (string)($item['item_type'] ?? '') !== 'profile'
                || (string)($item['item_family'] ?? '') !== 'reaction'
                || (string)($item['equip_slot'] ?? '') !== self::SLOT
                || empty($item['owned'])) continue;

            $codes = is_array($item['metadata']['reactions'] ?? null) ? $item['metadata']['reactions'] : [];
            foreach ($codes as $value) {
                $code = strtolower(trim((string)$value));
                if ($code !== '' && isset(self::REACTIONS[$code])) $allowed[$code] = true;
            }
        }
        return array_keys($allowed);
    }

    private function activeGameForParticipant(string $gameId, string $providerUserId): array
    {
        $storage = new JsonStorageAdapter((string)($this->config['data_dir'] ?? ''));
        $game = $storage->readOnlySections(['games'], static function (array $data) use ($gameId): ?array {
            $candidate = $data['games'][$gameId] ?? null;
            return is_array($candidate) ? $candidate : null;
        });
        if (!is_array($game) || (string)($game['status'] ?? '') !== 'active') {
            throw new GameReactionException(409, ServerLocalization::copy('server.game_runtime.reactions.match_finished', 'The match has already finished.'));
        }
        if (!in_array($providerUserId, array_map('strval', (array)($game['player_ids'] ?? [])), true)) {
            throw new GameReactionException(403, ServerLocalization::copy('server.game_runtime.common.not_participating', 'You are not participating in this game.'));
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
