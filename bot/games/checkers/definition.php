<?php
declare(strict_types=1);

return [
    'id' => 'checkers',
    'title' => 'Шашки',
    'enabled' => true,
    'engine' => 'checkers',
    'renderer' => 'checkers',
    'action_type' => 'checkers_move',
    'min_players' => 2,
    'max_players' => 2,
    'rooms' => ['match', 'gold'],
    'supports_bot' => true,
    'board_sizes' => [8],
    'default_board_size' => 8,
    'board_columns' => 8,
    'board_rows' => 8,
];
