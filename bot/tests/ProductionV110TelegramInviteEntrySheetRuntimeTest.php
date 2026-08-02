<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/invites/GameInviteCreationTrait.php';

$source = file_get_contents($root . '/bot/services/invites/GameInviteCreationTrait.php');
if (!is_string($source)) throw new RuntimeException('Cannot read invite creation source.');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$openedPosition = strpos($source, '$openedInvite = $candidate;');
$trackedPosition = strpos($source, '$trackedInvite = $candidate;');
$returnPosition = strpos($source, "'opened_invite' => \$openedInvite");

$assert($openedPosition !== false, 'Notification-only linked invite must populate opened_invite.');
$assert($trackedPosition !== false, 'Non-notification tracked invites must retain tracked_invite behavior.');
$assert($returnPosition !== false && $returnPosition > $openedPosition, 'opened_invite must be returned after classification.');
$assert(str_contains($source, 'if ($this->isNotificationOnlyPendingInvite($candidate))'), 'Classification must reuse the canonical notification-only predicate.');
$assert(!str_contains($source, '$activeInvite = $openedInvite'), 'One-shot linked invite must never become active invite state.');

fwrite(STDOUT, "ProductionV110TelegramInviteEntrySheetRuntimeTest: {$assertions} assertions passed\n");
