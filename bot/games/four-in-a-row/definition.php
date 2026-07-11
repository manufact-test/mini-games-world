<?php
declare(strict_types=1);

return [
    'id' => 'four_in_a_row',
    'title' => '4 в ряд',
    'enabled' => true,
    'engine' => 'four_in_a_row',
    'renderer' => 'four_in_a_row',
    'action_type' => 'column',
    'min_players' => 2,
    'max_players' => 2,
    'rooms' => ['match', 'gold'],
    'supports_bot' => true,
    'board_sizes' => [6, 7, 8],
    'default_board_size' => 7,
    'board_columns' => 7,
    'board_rows' => 6,
];
