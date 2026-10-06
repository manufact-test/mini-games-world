<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/localization/ServerLocalization.php';

return [
    'id' => 'chess',
    'title' => ServerLocalization::copy('game_cards.meta.chess.title', 'Chess'),
    'enabled' => true,
    'engine' => 'chess',
    'renderer' => 'chess',
    'action_type' => 'chess_move',
    'min_players' => 2,
    'max_players' => 2,
    'rooms' => ['match', 'gold'],
    'supports_bot' => true,
    'board_sizes' => [8],
    'default_board_size' => 8,
    'board_columns' => 8,
    'board_rows' => 8,
];
