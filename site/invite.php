<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$token = strtolower(trim((string)($_GET['code'] ?? '')));
$snapshot = [
    'available' => false,
    'state' => 'unavailable',
    'phase' => '',
    'waiting_seconds' => 0,
];
$telegramUrl = '';

try {
    if (preg_match('/^[a-f0-9]{24}$/', $token) === 1) {
        require __DIR__ . '/../bot/core/bootstrap.php';
        require_once __DIR__ . '/../bot/services/GameInviteService.php';

        $catalog = new GameCatalogService($config);
        $games = new ChessRuntimeService($config, $catalog, new GameService($config));
        $invites = new GameInviteService($config, $catalog, $games);
        $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/../bot/data')));

        $snapshot = $db->readOnlySections(
            ['invites'],
            static fn(array $data): array => $invites->landingSnapshot($data, $token)
        );

        $botUsername = ltrim(trim((string)($config['bot_username'] ?? '')), '@');
        if (!empty($snapshot['available']) && $botUsername !== '') {
            $telegramUrl = 'https://t.me/' . rawurlencode($botUsername)
                . '?start=invite_' . rawurlencode($token);
        }
    }
} catch (Throwable $error) {
    error_log('[Mini Games World invite landing] ' . $error->getMessage());
    $snapshot = [
        'available' => false,
        'state' => 'unavailable',
        'phase' => '',
        'waiting_seconds' => 0,
    ];
    $telegramUrl = '';
}

$available = !empty($snapshot['available']);
$state = (string)($snapshot['state'] ?? 'unavailable');
$phase = (string)($snapshot['phase'] ?? '');
$remaining = max(0, (int)($snapshot['waiting_seconds'] ?? 0));

if ($available) {
    $headline = 'Вас пригласили сыграть';
    $description = 'Откройте Mini Games World в Telegram. Кто первым откроет приглашение в приложении, тот и станет соперником.';
    $timerLabel = $phase === 'draft' ? 'Ссылка активна ещё' : 'Осталось на открытие';
} elseif ($state === 'expired') {
    $headline = 'Время приглашения истекло';
    $description = 'Попросите отправителя создать новую ссылку.';
    $timerLabel = '';
} else {
    $headline = 'Приглашение уже недоступно';
    $description = 'Оно могло быть принято, отменено или закрыто. Попросите отправителя создать новую ссылку.';
    $timerLabel = '';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="color-scheme" content="dark">
    <title>Приглашение — Mini Games World</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #070a11;
            color: #f7f8fb;
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; }
        body {
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(circle at 50% 0%, rgba(109, 94, 252, .24), transparent 42%),
                linear-gradient(180deg, #0d1220 0%, #070a11 72%);
        }
        .invite-card {
            width: min(100%, 480px);
            padding: 30px;
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 28px;
            background: rgba(17, 22, 36, .92);
            box-shadow: 0 24px 80px rgba(0,0,0,.36);
            text-align: center;
        }
        .mark {
            width: 72px;
            height: 72px;
            margin: 0 auto 20px;
            display: grid;
            place-items: center;
            border-radius: 22px;
            background: linear-gradient(145deg, #7868ff, #4f45d9);
            box-shadow: 0 14px 34px rgba(109,94,252,.28);
            font-size: 34px;
        }
        .eyebrow {
            margin: 0 0 9px;
            color: #aaa3ff;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .13em;
            text-transform: uppercase;
        }
        h1 {
            margin: 0;
            font-size: clamp(27px, 7vw, 38px);
            line-height: 1.06;
            letter-spacing: -.035em;
        }
        .lead {
            margin: 15px auto 0;
            max-width: 390px;
            color: #b9c0d0;
            font-size: 15px;
            line-height: 1.55;
        }
        .timer {
            margin: 24px 0 0;
            padding: 17px 18px;
            border-radius: 18px;
            background: rgba(255,255,255,.055);
        }
        .timer-label {
            display: block;
            margin-bottom: 4px;
            color: #aeb5c7;
            font-size: 13px;
        }
        .timer-value {
            display: block;
            font-variant-numeric: tabular-nums;
            font-size: 31px;
            font-weight: 800;
            letter-spacing: .02em;
        }
        .actions {
            display: grid;
            gap: 11px;
            margin-top: 22px;
        }
        .button {
            min-height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 14px 18px;
            border: 0;
            border-radius: 17px;
            font: inherit;
            font-size: 15px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }
        .button.primary {
            color: white;
            background: linear-gradient(135deg, #7565ff, #574ae5);
            box-shadow: 0 12px 28px rgba(92,78,232,.28);
        }
        .button.secondary {
            color: #e8ebf5;
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.09);
        }
        .button[disabled] {
            opacity: .48;
            cursor: default;
        }
        .note {
            margin: 18px 0 0;
            color: #767f94;
            font-size: 12px;
            line-height: 1.45;
        }
        .copy-state {
            min-height: 18px;
            margin-top: 10px;
            color: #aaa3ff;
            font-size: 12px;
        }
        @media (max-width: 520px) {
            body { padding: 16px; }
            .invite-card { padding: 25px 20px; border-radius: 24px; }
            .mark { width: 64px; height: 64px; border-radius: 20px; font-size: 30px; }
        }
    </style>
</head>
<body data-invite-available="<?= $available ? '1' : '0' ?>" data-invite-remaining="<?= $remaining ?>">
<main class="invite-card" aria-live="polite">
    <div class="mark" aria-hidden="true">🎮</div>
    <p class="eyebrow">Mini Games World</p>
    <h1 data-invite-headline><?= htmlspecialchars($headline, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p class="lead" data-invite-description><?= htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

    <?php if ($available): ?>
        <div class="timer" data-invite-timer>
            <span class="timer-label"><?= htmlspecialchars($timerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong class="timer-value" data-invite-timer-value>0:00</strong>
        </div>
        <div class="actions">
            <?php if ($telegramUrl !== ''): ?>
                <a class="button primary" data-platform="telegram" data-invite-open href="<?= htmlspecialchars($telegramUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Открыть в Telegram</a>
            <?php else: ?>
                <button class="button primary" type="button" disabled>Telegram временно недоступен</button>
            <?php endif; ?>
            <button class="button secondary" type="button" data-copy-link>Скопировать ссылку</button>
        </div>
        <div class="copy-state" data-copy-state aria-live="polite"></div>
        <p class="note">Сейчас игра доступна через Telegram. Для будущих приложений адрес приглашения останется тем же.</p>
    <?php else: ?>
        <p class="note">Личные данные игроков на этой странице не публикуются.</p>
    <?php endif; ?>
</main>
<script>
(() => {
    const body = document.body;
    if (body.dataset.inviteAvailable !== '1') return;

    let remaining = Math.max(0, Number(body.dataset.inviteRemaining || 0));
    const timer = document.querySelector('[data-invite-timer]');
    const value = document.querySelector('[data-invite-timer-value]');
    const open = document.querySelector('[data-invite-open]');
    const headline = document.querySelector('[data-invite-headline]');
    const description = document.querySelector('[data-invite-description]');

    const paint = () => {
        const minutes = Math.floor(remaining / 60);
        const seconds = String(remaining % 60).padStart(2, '0');
        if (value) value.textContent = `${minutes}:${seconds}`;
    };

    const expire = () => {
        if (timer) timer.hidden = true;
        if (open) {
            open.removeAttribute('href');
            open.setAttribute('aria-disabled', 'true');
            open.textContent = 'Приглашение истекло';
        }
        if (headline) headline.textContent = 'Время приглашения истекло';
        if (description) description.textContent = 'Попросите отправителя создать новую ссылку.';
    };

    paint();
    if (remaining <= 0) {
        expire();
    } else {
        const interval = window.setInterval(() => {
            remaining = Math.max(0, remaining - 1);
            paint();
            if (remaining <= 0) {
                window.clearInterval(interval);
                expire();
            }
        }, 1000);
    }

    const copy = document.querySelector('[data-copy-link]');
    const copyState = document.querySelector('[data-copy-state]');
    copy?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
            if (copyState) copyState.textContent = 'Ссылка скопирована';
        } catch (error) {
            if (copyState) copyState.textContent = 'Скопируйте адрес из строки браузера';
        }
    });
})();
</script>
</body>
</html>
