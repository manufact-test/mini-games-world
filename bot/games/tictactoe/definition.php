<?php
declare(strict_types=1);

return [
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
    'board_columns' => $defaultBoardSize,
    'board_rows' => $defaultBoardSize,
];
