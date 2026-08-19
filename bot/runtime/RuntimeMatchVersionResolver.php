<?php
declare(strict_types=1);

final class RuntimeMatchVersionResolver
{
    private const GAME_DIRS = [
        'tictactoe' => 'tictactoe',
        'four_in_a_row' => 'four-in-a-row',
        'battleship' => 'battleship',
        'checkers' => 'checkers',
        'reversi' => 'reversi',
        'chess' => 'chess',
        'go' => 'go',
        'domino' => 'domino',
    ];

    private const ENGINE_OWNER_FILES = [
        'bot/services/GameActionService.php',
        'bot/services/GameRuntimeService.php',
        'bot/services/ChessRuntimeService.php',
        'bot/services/FourInARowService.php',
        'bot/services/GameSettlementService.php',
    ];

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Match version resolver project root is unavailable.');
        }
    }

    public function resolve(string $gameType): array
    {
        $gameType = trim($gameType);
        $directory = self::GAME_DIRS[$gameType] ?? null;
        if ($directory === null) {
            throw new RuntimeException('Match event log cannot version unsupported game type: ' . $gameType . '.');
        }

        $definition = $this->projectRoot . '/bot/games/' . $directory . '/definition.php';
        if (!is_file($definition)) {
            throw new RuntimeException('Match event rules owner is missing for: ' . $gameType . '.');
        }

        $rulesVersion = hash_file('sha256', $definition);
        if (!is_string($rulesVersion) || preg_match('/^[a-f0-9]{64}$/', $rulesVersion) !== 1) {
            throw new RuntimeException('Match event rules fingerprint is unavailable.');
        }

        $engineOwners = [$definition];
        foreach (self::ENGINE_OWNER_FILES as $relative) {
            $path = $this->projectRoot . '/' . $relative;
            if (!is_file($path)) {
                throw new RuntimeException('Match event engine owner is missing: ' . $relative . '.');
            }
            $engineOwners[] = $path;
        }

        $parts = [];
        foreach ($engineOwners as $path) {
            $fingerprint = hash_file('sha256', $path);
            if (!is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
                throw new RuntimeException('Match event engine fingerprint is unavailable.');
            }
            $parts[] = str_replace($this->projectRoot . '/', '', $path) . ':' . $fingerprint;
        }

        return [
            'rules_version' => $rulesVersion,
            'engine_version' => hash('sha256', implode("\n", $parts)),
        ];
    }
}
