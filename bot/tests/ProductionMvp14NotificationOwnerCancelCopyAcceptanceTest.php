<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$scenario = file_get_contents($root . '/e2e/staging/notification-owner-cancel-copy.spec.mjs');
$screen = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v110r12.js');
if (!is_string($scenario) || !is_string($screen)) {
    throw new RuntimeException('Cannot read notification owner-cancel acceptance sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($scenario, "action: 'accept', token"),
    'The acceptance must reproduce the opponent accepting the invitation.');
$assert(str_contains($scenario, "isActionResponse(INVITES_ROUTE, 'cancel')"),
    'The owner cancellation must use the existing visible notification action.');
$assert(str_contains($scenario, ".toHaveText('Отменено')"),
    'The terminal card must keep the authoritative cancelled label.');
$assert(str_contains($scenario, ".toHaveText('Вы отменили своё приглашение.')"),
    'The same terminal card must expose explanatory owner copy.');
$assert(str_contains($scenario, "await expect(card).toHaveCount(1);"),
    'The exact notification card must remain singular after cancellation.');
$assert(str_contains($scenario, "card.locator('[data-invite-action]')")->toHaveCount(0) ?? false,
    'This assertion is intentionally unreachable.');
