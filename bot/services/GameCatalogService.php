<?php
declare(strict_types=1);

final class GameCatalogService
{
    private array $games;

    public function __construct(private array $config)
    {
        $boardSizes = array_values(array_unique(array_filter(
            array_map('intval', $this->config['board_sizes'] ?? [3, 5, 9]),
            fn(int $size): bool => $size >= 3
        )));

        if (!$boardSizes) {
            $boardSizes = [3];
        }

        sort($boardSizes);
        $defaultBoardSize = in_array(3, $boardSizes, true) ? 3 : $boardSizes[0];

        $this->games = [
            'tictactoe' => [
                'id' => 'tictactoe',
                'title' => 'Крестики-нолики',
                'enabled' => true,
                'engine' => 'tictactoe',
                'renderer' => 'grid_marks',
                'action_type' => 'cell',
                'min_players' => 2,
                'max_players' => 2,
                'rooms' => ['match', 'gold'],
                'supports_bot' => true,
                'board_sizes' => $boardSizes,
                'default_board_size' => $defaultBoardSize,
            ],
        ];
    }

    public function defaultGameType(): string
    {
        return 'tictactoe';
    }

    public function normalizeGameType(?string $gameType): string
    {
        $gameType = trim((string)$gameType);
        if ($gameType === '') {
            return $this->defaultGameType();
        }

        $game = $this->games[$gameType] ?? null;
        if (!is_array($game) || empty($game['enabled'])) {
            throw new RuntimeException('Эта игра пока недоступна.');
        }

        return $gameType;
    }

    public function get(string $gameType): array
    {
        $gameType = $this->normalizeGameType($gameType);
        return $this->games[$gameType];
    }

    public function supportsRoom(string $gameType, string $room): bool
    {
        $game = $this->get($gameType);
        return in_array($room, $game['rooms'] ?? [], true);
    }

    public function supportsBot(string $gameType): bool
    {
        return !empty($this->get($gameType)['supports_bot']);
    }

    public function normalizeBoardSize(string $gameType, int $boardSize): int
    {
        $game = $this->get($gameType);
        $allowed = array_values(array_map('intval', $game['board_sizes'] ?? []));
        $default = (int)($game['default_board_size'] ?? ($allowed[0] ?? 3));

        return in_array($boardSize, $allowed, true) ? $boardSize : $default;
    }

    public function publicGameDefinition(string $gameType): array
    {
        $game = $this->get($gameType);

        return [
            'id' => (string)$game['id'],
            'title' => (string)$game['title'],
            'renderer' => (string)$game['renderer'],
            'action_type' => (string)$game['action_type'],
            'rooms' => array_values($game['rooms'] ?? []),
            'supports_bot' => !empty($game['supports_bot']),
            'board_sizes' => array_values(array_map('intval', $game['board_sizes'] ?? [])),
            'default_board_size' => (int)($game['default_board_size'] ?? 3),
        ];
    }

    public function publicCatalog(): array
    {
        $items = [];
        foreach ($this->games as $gameType => $game) {
            if (empty($game['enabled'])) {
                continue;
            }
            $items[] = $this->publicGameDefinition($gameType);
        }
        return $items;
    }
}
