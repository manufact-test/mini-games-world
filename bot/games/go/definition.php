<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/localization/ServerLocalization.php';

return [
    'id' => 'go',
    'title' => ServerLocalization::copy('game_cards.meta.go.title', 'Go'),
    'enabled' => true,
    'engine' => 'go',
    'renderer' => 'go',
    'action_type' => 'go_action',
    'min_players' => 2,
    'max_players' => 2,
    'rooms' => ['match', 'gold'],
    'supports_bot' => true,
    'board_sizes' => [9, 13],
    'default_board_size' => 9,
    'board_columns' => 9,
    'board_rows' => 9,
];
