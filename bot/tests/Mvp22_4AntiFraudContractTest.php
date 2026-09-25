<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = (string)file_get_contents($root . '/app/admin.php');
$css = (string)file_get_contents($root . '/app/assets/css/admin-shell.css');
$shell = (string)file_get_contents($root . '/app/assets/js/admin-shell.js');
$ui = (string)file_get_contents($root . '/app/assets/js/admin-antifraud.js');
$endpoint = (string)file_get_contents($root . '/bot/admin-replay.php');
$service = (string)file_get_contents($root . '/bot/antifraud/AntiFraudCaseService.php');
$reader = (string)file_get_contents($root . '/bot/replay/MatchReplayReader.php');
$migration = (string)file_get_contents($root . '/bot/database/migrations/20260925_0064_create_antifraud_cases.php');
$fingerprint = (string)file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'data-admin-nav-target="antifraud"',
    'data-admin-section="antifraud" data-admin-antifraud',
    'data-af-mode="active"',
    'data-af-mode="closed"',
    'data-af-recent-match',
    'data-af-match-id',
    'data-af-prev',
    'data-af-play',
    'data-af-next',
    'data-af-speed',
    'data-af-progress',
    'data-af-create-case',
    'data-af-take-case',
    'data-af-decision="cleared"',
    'data-af-decision="monitor"',
    'data-af-decision="rating_review"',
    'data-af-decision="moderation_review"',
] as $needle) {
    $assert(str_contains($page, $needle), 'Anti-fraud Admin surface missing: ' . $needle);
}


$assert(
    str_contains($page, 'data-af-home-mode="match"')
        && str_contains($page, 'data-af-review-tab="overview"')
        && str_contains($page, 'data-af-review-tab="replay"')
        && str_contains($page, 'data-af-review-tab="pair"')
        && str_contains($page, 'data-af-review-tab="devices"')
        && str_contains($page, 'data-af-review-tab="case"')
        && str_contains($page, 'Проверка матча в три шага')
        && str_contains($page, 'Пошаговый повтор матча')
        && str_contains($page, 'Это не видео.')
        && str_contains($page, 'Технические данные повтора'),
    'Anti-fraud Admin must use a guided match review workspace instead of one long stacked diagnostic page.'
);

$assert(
    str_contains($ui, "const showHome = nextMode =>")
        && str_contains($ui, "const showReviewTab = name =>")
        && str_contains($ui, "const openReviewWorkspace = preferredTab =>")
        && str_contains($ui, "const renderReplayAvailability = review =>")
        && str_contains($ui, "frames.length === 1")
        && str_contains($ui, "Пошаговый повтор недоступен")
        && str_contains($ui, "reviewBox.scrollIntoView")
        && str_contains($ui, "openReviewWorkspace('overview')")
        && str_contains($ui, "openReviewWorkspace('case')"),
    'Anti-fraud UI must visibly transition into review, explain unavailable replay, and route cases to their own tab.'
);

$assert(
    str_contains($page, 'admin-antifraud.js')
        && str_contains($page, 'data-replay-api="../bot/admin-replay.php"')
        && !str_contains($page, 'data-admin-section="tests" data-replay-card'),
    'Replay must have one dedicated anti-fraud UI owner and reuse the canonical replay endpoint.'
);

$assert(
    str_contains($shell, "antifraud:['Проверка игр'")
        && str_contains($shell, "initialParams.has('afcase')")
        && !str_contains($shell, "const renderReplay =")
        && !str_contains($shell, "const loadReplay ="),
    'Admin shell must route to anti-fraud without retaining the old parallel replay owner.'
);

foreach ([
    "mode = 'active'",
    "mode === 'closed' ? 'Обработанные кейсы'",
    "const renderRecentMatches = matches =>",
    "const renderSignals = review =>",
    "const renderPairHistory = review =>",
    "const renderDeviceSession = review =>",
    "const scheduleNext = () =>",
    "playButton.addEventListener('click'",
    "speedSelect.addEventListener('change'",
    "progress.addEventListener('input'",
    "action:'create_case'",
    "action:'take_case'",
    "action:'resolve_case'",
] as $needle) {
    $assert(str_contains($ui, $needle), 'Anti-fraud UI contract missing: ' . $needle);
}

$assert(
    str_contains($css, '.mgw-admin__antifraud')
        && str_contains($css, '.mgw-admin__af-review-tabs')
        && str_contains($css, '.mgw-admin__af-player-grid')
        && str_contains($css, '@media(max-width:640px)'),
    'Anti-fraud Admin workspace must have responsive styling.'
);

foreach ([
    "if (\$action === 'snapshot')",
    "if (\$action === 'match_replay' || \$action === 'match_review')",
    "if (\$action === 'create_case')",
    "if (\$action === 'take_case')",
    "if (\$action === 'resolve_case')",
] as $needle) {
    $assert(str_contains($endpoint, $needle), 'Anti-fraud API action missing: ' . $needle);
}

$assert(
    str_contains($service, "'auto_ban' => false")
        && str_contains($service, "'single_signal_sanction' => false")
        && !preg_match('/UPDATE\s+mgw_users\s+SET\s+status/i', $service)
        && !str_contains($service, 'recommendPermanentBan(')
        && !str_contains($service, 'restrict('),
    'Anti-fraud signals/cases must never apply player sanctions automatically.'
);

foreach ([
    "shared_device_match_window",
    "shared_device_history",
    "repeat_pair_24h",
    "rating_antifarming_limited",
    "replay_integrity_gap",
    "private function pairHistory",
    "private function recentMatches",
    "private function deviceSessionSignals",
    "private function timingSummary",
] as $needle) {
    $assert(str_contains($service, $needle), 'Anti-fraud evidence contract missing: ' . $needle);
}

$assert(
    str_contains($service, "STATUS_OPEN = 'open'")
        && str_contains($service, "STATUS_REVIEWING = 'reviewing'")
        && str_contains($service, "STATUS_CLOSED = 'closed'")
        && str_contains($service, "public function takeInReview")
        && str_contains($service, "public function resolve")
        && str_contains($service, "'case_terminal'"),
    'Anti-fraud cases must use a one-way manual review lifecycle.'
);

$assert(
    str_contains($reader, "'mgw_id' =>")
        && str_contains($reader, "\$row['seat_index'] ?? \$row['seat']")
        && !str_contains($reader, 'ORDER BY seat_index, player_ref'),
    'Replay reader must expose canonical player identity and support the canonical seat schema.'
);

foreach ([
    'mgw_antifraud_cases',
    'mgw_antifraud_case_signals',
    'mgw_antifraud_case_events',
    'UNIQUE KEY uq_mgw_antifraud_case_match',
] as $needle) {
    $assert(str_contains($migration, $needle), 'Anti-fraud migration contract missing: ' . $needle);
}

foreach ([
    'bot/admin-replay.php',
    'bot/replay/MatchReplayReader.php',
    'bot/antifraud/AntiFraudCaseService.php',
    'bot/database/migrations/20260925_0064_create_antifraud_cases.php',
    'app/assets/js/admin-antifraud.js',
] as $runtimeFile) {
    $assert(str_contains($fingerprint, $runtimeFile), 'Staging fingerprint must cover MVP-22.4 runtime file: ' . $runtimeFile);
}

fwrite(STDOUT, "MVP-22.4 AntiFraudContractTest OK: {$assertions} assertions.\n");
