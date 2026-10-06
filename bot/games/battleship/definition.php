<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/localization/ServerLocalization.php';

return [
    'id' => 'battleship',
    'title' => ServerLocalization::copy('game_cards.meta.battleship.title', 'Battleship'),
    'enabled' => true,
    'engine' => 'battleship',
    'renderer' => 'battleship',
    'action_type' => 'battleship_action',
    'min_players' => 2,
    'max_players' => 2,
    'rooms' => ['match', 'gold'],
    'supports_bot' => true,
    'board_sizes' => [10],
    'default_board_size' => 10,
    'board_columns' => 10,
    'board_rows' => 10,
];
