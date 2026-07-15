<?php
declare(strict_types=1);

/*
 * Copy this complete file to _private_mgw/runtime.php next to config.php.
 * It contains no tokens, passwords, admin IDs or database credentials.
 * Delete runtime.php to return to the safe defaults below.
 */
return [
    'maintenance_mode' => false,
    'maintenance_message' => '',
    'financial_read_only' => false,

    'features' => [
        'matchmaking' => true,
        'invitations' => true,
        'payments' => true,
        'shop' => true,
        'tournaments' => false,
        'ads' => false,
    ],

    'games' => [
        'tictactoe' => true,
        'four_in_a_row' => true,
        'battleship' => true,
        'checkers' => true,
        'reversi' => true,
        'chess' => true,
        'go' => true,
        'domino' => true,
    ],
];
