<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$entry = $read('app/v110.php');
$layer = $read('app/assets/css/mvp14-invite-pending-backdrop-v1.css');
$inviteCss = $read('app/assets/css/components/game-invites.css');
$client = $read('app/assets/js/games/game-invites-v110.js');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($entry, 'mvp14-invite-pending-backdrop-v1.css?v=1'),
    'The staging entrypoint must load the scoped pending-invite backdrop layer.'
);
$assert(
    str_contains($layer, '.overlay:has(#sheet > .btn.primary.full[aria-disabled="true"][disabled]:not([data-invite-action]))'),
    'The backdrop layer must target only the non-authoritative pre-token cancellation placeholder.'
);
$assert(
    str_contains($layer, 'background:rgba(3,6,12,.84)')
        && str_contains($layer, 'backdrop-filter:blur(6px)')
        && str_contains($inviteCss, 'background:rgba(3,6,12,.84)')
        && str_contains($inviteCss, 'backdrop-filter:blur(6px)'),
    'The first-frame backdrop must exactly match the accepted invite-sheet backdrop.'
);
$assert(
    str_contains($client, 'aria-disabled="true" disabled style="opacity:1">Отменить приглашение</button>')
        && !str_contains($layer, 'data-invite-action="cancel"'),
    'The visual backdrop fix must not create or alter a cancellation action owner.'
);
$assert(
    !str_contains($layer, '.overlay.active')
        && !str_contains($layer, '.sheet')
        && !str_contains($layer, 'transition:'),
    'The scoped layer must not change generic modal, sheet, or animation behavior.'
);

fwrite(STDOUT, "ProductionMvp14InterfaceInviteBackdropFirstFrameTest: {$assertions} assertions passed\n");
