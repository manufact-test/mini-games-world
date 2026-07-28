<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/InviteSignalService.php';

$config = [
    'base_url' => 'https://v105-runtime-' . bin2hex(random_bytes(6)) . '.test',
    'data_dir' => '/nonexistent/application-json-' . bin2hex(random_bytes(4)),
];
$signals = new InviteSignalService($config);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$invite = [
    'token' => bin2hex(random_bytes(12)),
    'status' => 'pending',
    'source' => 'direct',
    'is_owner' => true,
    'is_invitee' => false,
    'is_participant' => true,
    'can_accept' => false,
    'can_decline' => false,
    'can_start' => false,
    'can_cancel' => true,
    'inviter_name' => '@owner',
    'invitee_name' => '@recipient',
    'game_type' => 'tictactoe',
    'game_title' => 'Крестики-нолики',
    'room' => 'match',
    'room_label' => 'Матч-комната',
    'bet' => 10,
    'board_size' => 3,
    'board_columns' => 3,
    'board_rows' => 3,
];

$signals->publish('recipient-1', $invite);
$received = $signals->latest('recipient-1');
$assert(is_array($received), 'The intended recipient must read the transient signal.');
$assert(($received['token'] ?? '') === $invite['token'], 'The signal must preserve the exact invite token.');
$assert(!empty($received['is_invitee']) && empty($received['is_owner']), 'The signal must expose the invitee view, not the sender view.');
$assert(!empty($received['can_accept']) && !empty($received['can_decline']) && empty($received['can_cancel']), 'The invitee action permissions must be correct.');
$assert($signals->latest('recipient-2') === null, 'A different account must not see the signal.');

$signals->clear('recipient-1', $invite['token']);
$assert($signals->latest('recipient-1') === null, 'Cancellation must remove the transient signal immediately.');

$finished = $invite;
$finished['token'] = bin2hex(random_bytes(12));
$finished['status'] = 'cancelled';
$signals->publish('recipient-1', $finished);
$assert($signals->latest('recipient-1') === null, 'A non-pending invite must never be published.');

fwrite(STDOUT, "ProductionV105InviteSignalRuntimeTest: {$assertions} assertions passed\n");
