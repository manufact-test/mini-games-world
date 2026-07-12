<?php
declare(strict_types=1);

return [
    'id' => 'reversi',
    'title' => 'Реверси',
    'enabled' => true,
    'engine' => 'reversi',
    'renderer' => 'reversi',
    'action_type' => 'cell',
    'min_players' => 2,
    'max_players' => 2,
    'rooms' => ['match', 'gold'],
    'supports_bot' => true,
    'board_sizes' => [6, 8, 10],
    'default_board_size' => 8,
    'board_columns' => 8,
    'board_rows' => 8,
];
