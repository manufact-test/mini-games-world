<?php
declare(strict_types=1);

return [
    'id' => 'domino',
    'title' => 'Домино',
    'enabled' => true,
    'engine' => 'domino',
    'renderer' => 'domino',
    'action_type' => 'domino_action',
    'min_players' => 2,
    'max_players' => 2,
    'rooms' => ['match', 'gold'],
    'supports_bot' => true,
    'board_sizes' => [7],
    'default_board_size' => 7,
    'board_columns' => 7,
    'board_rows' => 1,
];
