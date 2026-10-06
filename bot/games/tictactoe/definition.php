<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/localization/ServerLocalization.php';

return [
    'id' => 'tictactoe',
    'title' => ServerLocalization::copy('game_cards.meta.tictactoe.title', 'Tic-Tac-Toe'),
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
