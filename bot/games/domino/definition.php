<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/localization/ServerLocalization.php';

return [
    'id' => 'domino',
    'title' => ServerLocalization::copy('game_cards.meta.domino.title', 'Domino'),
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
