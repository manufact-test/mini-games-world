<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'admin'=>$root . '/app/admin.php',
    'admin_js'=>$root . '/app/assets/js/admin-tournaments.js',
    'screen'=>$root . '/app/assets/js/screens/tournaments-screen-v1.js',
    'css'=>$root . '/app/assets/css/main.css',
    'manifest'=>$root . '/app/runtime/client/version-manifest.php',
];
$source = [];
foreach ($files as $key=>$path) {
    $value = file_get_contents($path);
    if (!is_string($value)) throw new RuntimeException('Missing manual acceptance source: ' . $path);
    $source[$key] = $value;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'Официальный турнир',
    'Управление турниром ещё не загружено.',
    'Создать черновик',
    'Правила турнира',
    'Снимок наград',
    'Взнос фиксирован: 50 000 коинов MGW.',
] as $needle) {
    $assertTrue(str_contains($source['admin'], $needle), 'Tournament Admin must expose Russian copy: ' . $needle);
}

foreach ([
    '>Official Tournament<',
    '>Создать draft<',
    '>Reward snapshot<',
    'Tournament Admin ещё не загружен.',
] as $needle) {
    $assertTrue(!str_contains($source['admin'], $needle), 'Tournament Admin must not expose mixed-language copy: ' . $needle);
}

foreach ([
    "draft:'черновик'",
    "registration_open:'регистрация открыта'",
    "waiting_for_date:'состав набран · ожидает дату'",
    "summaryCard('Взнос'",
    "'Крестики-нолики'",
    'Снимок наград появится после создания черновика.',
    'Золотой билет нельзя продать или передать другому игроку.',
    'Правила являются снимком этого турнира.',
] as $needle) {
    $assertTrue(str_contains($source['admin_js'], $needle), 'Tournament Admin client must localize: ' . $needle);
}
$assertTrue(
    !str_contains($source['admin_js'], "rewards.textContent = JSON.stringify"),
    'Tournament Admin must not dump raw reward JSON to operators.'
);

foreach ([
    "tournamentPendingAction = 'verify';",
    'renderTournamentSnapshot();',
    'const verified = await api.tournamentStatus();',
    'const verifiedSnapshot = verified?.snapshot',
    "const registrationState = String(verifiedSnapshot?.registration?.state || '');",
    'verifiedCommit = verifiedSnapshot;',
    'tournamentSnapshot = verifiedCommit;',
    "if (action === 'register' && registrationState !== 'registered')",
    "if (action === 'leave' && registrationState === 'registered')",
    'errorMessage = humanizeTournamentError',
    'renderTournamentSnapshot(errorMessage);',
    'const insufficient = !registered && available < fee;',
    'Недостаточно коинов',
    'Регистрируем…',
    'Отменяем…',
    'Проверяем…',
    'aria-busy="true"',
    'В турнире участвуют ',
    'Регистрация закроется, когда все места будут заняты.',
    'tournaments-v2-tournament-participants',
    'state.user = verifiedUser;',
    'renderBalances(state.user);',
    'data-tournament-rules-consent',
    'Я прочитал(а) и принимаю правила этого турнира.',
    'Правила турнира приняты',
    'бронзовая награда',
    'Состав набран · ожидаем назначения даты',
] as $needle) {
    $assertTrue(str_contains($source['screen'], $needle), 'Player Tournament corrective missing: ' . $needle);
}
$assertTrue(
    !str_contains($source['screen'], "const state = String(tournamentSnapshot?.registration?.state || '');"),
    'Tournament mutation must not shadow imported app state and trigger a temporal-dead-zone error.'
);
$verifyPos = strpos($source['screen'], 'const verified = await api.tournamentStatus();');
$pendingEndPos = strpos($source['screen'], "tournamentBusy = false;\n    tournamentPendingAction = '';", $verifyPos ?: 0);
$commitSnapshotPos = strpos($source['screen'], 'tournamentSnapshot = verifiedCommit;');
$finalVerifiedRenderPos = strpos($source['screen'], 'renderTournamentSnapshot(errorMessage);', $commitSnapshotPos ?: 0);
$balanceCommitPos = strpos($source['screen'], 'state.user = verifiedUser;');
$assertTrue(
    $verifyPos !== false
    && $pendingEndPos !== false
    && $commitSnapshotPos !== false
    && $finalVerifiedRenderPos !== false
    && $balanceCommitPos !== false
    && $verifyPos < $pendingEndPos
    && $pendingEndPos < $commitSnapshotPos
    && $commitSnapshotPos < $finalVerifiedRenderPos
    && $finalVerifiedRenderPos < $balanceCommitPos,
    'Tournament pending spinner must end before verified seat state and visible header balance are published.'
);
$assertTrue(
    !str_contains(
        $source['screen'],
        "tournamentSnapshot = responseSnapshot;\n\n    if (result?.user && typeof result.user === 'object')"
    ),
    'Tournament write response must not optimistically publish seat/balance before verification.'
);
$assertTrue(
    !str_contains($source['screen'], 'official-tournament-rules-v1')
    && !str_contains($source['screen'], 'official-tournament-rules-v2')
    && !str_contains($source['screen'], 'Сохраняются версия, язык и время согласия.'),
    'Player Tournament must not expose internal rules identifiers or consent-storage implementation copy.'
);
$assertTrue(
    str_contains($source['screen'], '<details class="tournaments-v2-tournament-rules">')
    && !str_contains($source['screen'], '<details class="tournaments-v2-tournament-rules"${!registered'),
    'Tournament rules must render collapsed by default.'
);
$assertTrue(
    !str_contains($source['screen'], 'rules_consent?.version || rules.version')
    && str_contains($source['screen'], 'Правила турнира приняты'),
    'Accepted-rules notice must use human copy without the internal version token.'
);
$assertTrue(
    !str_contains($source['screen'], 'Зарезервировано:'),
    'Player Tournament must not expose ambiguous global reserved copy.'
);
$assertTrue(
    !str_contains($source['screen'], 'Регистрация, зарезервированный взнос и текущий состав турнира.'),
    'Player Tournament must not repeat the removed subtitle.'
);
$assertTrue(
    !str_contains($source['screen'], 'Доступно коинов:'),
    'Player Tournament must not duplicate the global coin balance.'
);
$assertTrue(
    !str_contains($source['screen'], 'В резерве турнира:'),
    'Player Tournament must not repeat reservation money copy under the prizes.'
);
$assertTrue(
    !str_contains($source['screen'], 'Можно регистрироваться.'),
    'Player Tournament must not render a meaningless ready-to-register status card.'
);
$assertTrue(
    !str_contains(
        $source['screen'],
        "void loadTournamentSnapshot();\n    void loadTournamentSnapshot();"
    ),
    'Tournament screen enter must not issue duplicate snapshot loads.'
);
$assertTrue(
    str_contains(
        $source['screen'],
        "onScreenEnter('tournaments', () => {\n    void activateGame(activeGame);\n    void loadArchiveOverview();\n    void loadTournamentSnapshot();\n  });"
    ),
    'Tournament screen enter must load one fresh tournament snapshot.'
);

$catchPos = strpos($source['screen'], 'errorMessage = humanizeTournamentError');
$finalRenderPos = strpos($source['screen'], 'renderTournamentSnapshot(errorMessage);');
$assertTrue(
    $catchPos !== false && $finalRenderPos !== false && $catchPos < $finalRenderPos,
    'Registration error must survive through the final render instead of being cleared.'
);

foreach ([
    '.tournaments-v2-tournament-capacity-copy{',
    'font-size:12px;',
    '.tournaments-v2-tournament-participants{',
    'justify-content:space-between;',
    '.tournaments-v2-tournament-action.is-pending{',
    'mgw-tournament-pending-spin',
    '.tournaments-v2-tournament-rules{',
    'linear-gradient(135deg,rgba(117,91,255,.09),rgba(255,255,255,.025))',
    'border-right:2px solid rgba(222,214,255,.9);',
    '.tournaments-v2-tournament-consent{',
    'align-items:center;',
] as $needle) {
    $assertTrue(str_contains($source['css'], $needle), 'Tournament manual UX CSS missing: ' . $needle);
}

$assertTrue(
    str_contains($source['manifest'], 'client.js?v=1142')
    && str_contains($source['manifest'], 'mvp21_2=tournament-rules-consent-v1'),
    'Corrective release must force a fresh API client module.'
);
$assertTrue(
    str_contains($source['manifest'], 'tournaments-screen-v1.js?v=13')
    && str_contains($source['manifest'], 'mvp21_2=tournament-rules-copy-v2'),
    'Corrective release must force a fresh Tournament screen module.'
);
$assertTrue(
    str_contains($source['manifest'], 'main.css?v=197')
    && str_contains($source['manifest'], 'mvp21_2=tournament-rules-copy-v2'),
    'Corrective release must force fresh Tournament CSS.'
);
$assertTrue(
    str_contains($source['admin'], 'admin-tournaments.js?v=3&mvp21_2=rules-consent-autoclose'),
    'Corrective release must force a fresh Tournament Admin script.'
);

if ($assertions < 42) {
    throw new RuntimeException('MVP-21.1 manual acceptance contract coverage is incomplete.');
}
fwrite(STDOUT, "Mvp21_1TournamentManualAcceptanceContractTest: {$assertions} assertions passed\n");
