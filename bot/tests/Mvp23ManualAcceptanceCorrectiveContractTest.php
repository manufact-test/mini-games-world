<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing manual-acceptance source: ' . $path);
    return $content;
};

$page = $read('app/admin.php');
$incidentJs = $read('app/assets/js/admin-incident.js');
$incidentCss = $read('app/assets/css/admin-incident.css');
$shortcuts = $read('app/assets/js/components/account-shortcuts.js');
$main = $read('app/assets/js/main-v110-handoff-shell.js');
$versionManifest = $read('app/runtime/client/version-manifest.php');
$stagingEntry = $read('app/v110.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($page, 'data-incident-archive-panel')
        && str_contains($page, 'История инцидентов')
        && str_contains($page, 'Одновременно активна только одна карточка.')
        && str_contains($page, 'data-incident-rehearsal-result'),
    'Incident UI must explain one-active-card history and expose rehearsal result.'
);

$assert(
    str_contains($incidentJs, ".filter(row => String(row?.incident_status || '') === 'resolved')")
        && str_contains($incidentJs, '.slice(0, 10)')
        && str_contains($incidentJs, 'incidentArchive'),
    'Incident archive must render only a bounded set of resolved incidents.'
);

$assert(
    str_contains($incidentCss, '[data-incident-archive]')
        && str_contains($incidentCss, 'max-height:300px')
        && str_contains($incidentCss, 'overflow:auto'),
    'Incident history must be scroll-bounded instead of growing the page indefinitely.'
);

$assert(
    str_contains($incidentCss, '.mgw-admin__incident-actions button')
        && str_contains($incidentCss, 'background:#282342')
        && str_contains($incidentCss, 'border:1px solid #6557d9')
        && str_contains($incidentCss, '@media(max-width:640px)'),
    'Incident buttons must use the Admin visual system on desktop and mobile.'
);

$assert(
    !str_contains($incidentJs, "window.confirm('Запустить безопасную учебную симуляцию?")
        && str_contains($incidentJs, "run_staging_rehearsal")
        && str_contains($incidentJs, 'rehearsalResult.hidden = false')
        && str_contains($incidentJs, 'incidentArchivePanel.open = true'),
    'Safe rehearsal must use in-page feedback and reveal its archived result instead of a native confirm flash.'
);

$openStart = strpos($shortcuts, 'async function openAccountDataShortcut()');
$loadPos = strpos($shortcuts, 'const module = await loadAccountDataModule();', $openStart === false ? 0 : $openStart);
$closePos = strpos($shortcuts, 'closeSheet();', $openStart === false ? 0 : $openStart);
$assert(
    $openStart !== false
        && $loadPos !== false
        && $closePos !== false
        && $loadPos < $closePos,
    'Account-data first open must keep the existing menu visible until the lazy module has loaded.'
);

$assert(
    str_contains($shortcuts, 'visible empty-sheet flash only on the first mobile open')
        && str_contains($main, "account-shortcuts.js?v=48")
        && str_contains($versionManifest, "'./assets/js/components/account-shortcuts.js?v=48'")
        && !str_contains($versionManifest, 'mvp23=account-data-first-open-no-flash-v1')
        && str_contains($stagingEntry, "\$accountShortcutsImportKey = './assets/js/components/account-shortcuts.js?v=48';")
        && str_contains($stagingEntry, "\$imports[\$accountShortcutsImportKey] .= '&mvp23=account-data-first-open-no-flash-v1';"),
    'First-open account-data corrective must keep the canonical manifest target intact and use the active staging entry only for the acceptance cache token.'
);

$assert(
    str_contains($page, 'admin-incident.css?v=2&mvp23=manual-acceptance-polish-v1')
        && str_contains($page, 'admin-incident.js?v=2&mvp23=manual-acceptance-polish-v1'),
    'Manual-acceptance incident assets must be cache-busted.'
);

fwrite(STDOUT, "MVP-23 manual acceptance corrective contract OK ({$assertions} assertions).\n");
