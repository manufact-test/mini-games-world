<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/localization/ServerLocalization.php';

return [
    'id' => 'reversi',
    'title' => ServerLocalization::copy('game_cards.meta.reversi.title', 'Reversi'),
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
