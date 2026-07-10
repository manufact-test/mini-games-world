<?php
ob_start();
require __DIR__ . '/landing.php';
$html = ob_get_clean();

$targetedCss = <<<'CSS'
<style id="quick-start-targeted-styles">
/* Compact typography only inside the highlighted inner cards */
.experienceSection .benefitItem b{font-size:12px;line-height:1.3;margin-bottom:2px}
.experienceSection .benefitItem small{display:block;font-size:10.5px;line-height:1.45}
.experienceSection .benefitItem span:first-child{font-size:15px;line-height:1.2}
.experienceSection .benefitItem{gap:10px;padding:11px 12px}
.experienceSection .fact strong{font-size:25px;margin-bottom:5px}
.experienceSection .fact span{font-size:10.5px;line-height:1.4}
.experienceSection .roomFeature{font-size:11px;line-height:1.4}
.experienceSection .roomFoot{font-size:10.5px;line-height:1.45}

/* Quick start section */
.quickStartSection{position:relative;overflow:hidden}
.quickStartSection:before{content:"";position:absolute;right:-12%;top:4%;width:520px;height:520px;border-radius:50%;background:radial-gradient(circle,rgba(177,76,255,.12),transparent 68%);pointer-events:none}
.quickStartHead{display:flex;align-items:flex-end;justify-content:space-between;gap:30px;margin-bottom:30px}
.quickStartIntro{max-width:760px}
.quickStartKicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;color:#c68cff;font-size:12px;font-weight:950;letter-spacing:.16em;text-transform:uppercase}
.quickStartHead h2{font-size:clamp(36px,4.8vw,60px);line-height:1.03;letter-spacing:-.055em;margin:0 0 14px}
.quickStartHead p{max-width:720px;margin:0;color:var(--muted);font-size:17px;line-height:1.7}
.quickStartHint{max-width:330px;padding:15px 17px;border:1px solid rgba(255,255,255,.08);border-radius:17px;background:rgba(255,255,255,.025);color:#b9c2d3;font-size:13px;line-height:1.55}
.quickSteps{position:relative;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.quickSteps:before{content:"";position:absolute;left:8%;right:8%;top:38px;height:2px;background:linear-gradient(90deg,rgba(139,92,246,.15),rgba(242,45,134,.65),rgba(255,106,34,.22));z-index:0}
.quickStep{--stepGlow:139,92,246;position:relative;z-index:1;display:flex;flex-direction:column;min-height:300px;padding:24px;border:1px solid rgba(255,255,255,.09);border-radius:24px;background:linear-gradient(145deg,rgba(255,255,255,.058),rgba(255,255,255,.018));overflow:hidden;transition:transform .28s ease,border-color .28s ease,box-shadow .28s ease}
.quickStep:after{content:"";position:absolute;z-index:-1;right:-70px;bottom:-85px;width:190px;height:190px;border-radius:50%;background:rgba(var(--stepGlow),.14)}
.quickStep:hover{transform:translateY(-5px);border-color:rgba(var(--stepGlow),.44);box-shadow:0 22px 50px rgba(0,0,0,.22)}
.quickStep:nth-child(2){--stepGlow:72,200,255}.quickStep:nth-child(3){--stepGlow:57,223,155}.quickStep:nth-child(4){--stepGlow:255,106,34}
.quickStepTop{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:24px}
.quickStepNumber{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;color:#fff;font-size:18px;font-weight:950;background:linear-gradient(135deg,var(--purple),var(--pink));box-shadow:0 12px 28px rgba(139,92,246,.2)}
.quickStepStatus{padding:6px 9px;border-radius:999px;background:rgba(255,255,255,.045);color:#9fa9bc;font-size:10px;font-weight:900;letter-spacing:.06em;text-transform:uppercase}
.quickStepEmoji{font-size:30px;line-height:1;margin-bottom:17px}
.quickStep h3{font-size:22px;line-height:1.25;letter-spacing:-.02em;margin:0 0 10px}
.quickStep p{margin:0;color:var(--muted);font-size:14px;line-height:1.65}
.quickStepNote{margin-top:auto;padding-top:20px;color:#d9dfeb;font-size:12px;font-weight:800;line-height:1.45}
.quickStartAction{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-top:18px;padding:22px 24px;border:1px solid rgba(255,255,255,.08);border-radius:22px;background:radial-gradient(circle at 0 50%,rgba(139,92,246,.16),transparent 36%),rgba(255,255,255,.022)}
.quickStartActionText b{display:block;font-size:18px;margin-bottom:5px}
.quickStartActionText span{color:var(--muted);font-size:13px;line-height:1.5}
.quickStartButton{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 21px;border-radius:14px;background:linear-gradient(100deg,var(--orange),var(--pink) 70%,var(--violet));font-weight:900;white-space:nowrap;transition:.25s}
.quickStartButton:hover{transform:translateY(-2px);filter:brightness(1.08)}
@media(max-width:1050px){.quickSteps{grid-template-columns:1fr 1fr}.quickSteps:before{display:none}.quickStartHead{align-items:flex-start;flex-direction:column}.quickStartHint{max-width:620px}}
@media(max-width:650px){.quickSteps{grid-template-columns:1fr}.quickStep{min-height:260px}.quickStartAction{align-items:flex-start;flex-direction:column}.quickStartButton{width:100%}.quickStartHead p{font-size:15px}.experienceSection .factGrid{grid-template-columns:1fr 1fr}}
</style>
CSS;

if (strpos($html, 'quick-start-targeted-styles') === false) {
    $html = str_replace('</head>', $targetedCss . '</head>', $html);
}

$quickStartSection = <<<'HTML'
<section class="section quickStartSection" id="how">
  <div class="wrap">
    <div class="quickStartHead">
      <div class="quickStartIntro">
        <div class="quickStartKicker">🚀 <span class="i18n" data-lang="ru">Быстрый старт</span><span class="i18n" data-lang="en">Quick start</span></div>
        <h2><span class="i18n" data-lang="ru">От Telegram до первого матча — четыре простых шага</span><span class="i18n" data-lang="en">From Telegram to your first match in four simple steps</span></h2>
        <p><span class="i18n" data-lang="ru">Никаких сложных регистраций и настроек. Откройте бота, запустите Mini App, выберите подходящий формат и сразу переходите к игре.</span><span class="i18n" data-lang="en">No complicated signup or setup. Open the bot, launch the Mini App, choose a suitable format and move straight into the game.</span></p>
      </div>
      <div class="quickStartHint"><span class="i18n" data-lang="ru">Telegram автоматически передаёт базовые данные профиля, поэтому отдельный логин и пароль создавать не требуется.</span><span class="i18n" data-lang="en">Telegram securely provides the basic profile details, so you do not need to create another login or password.</span></div>
    </div>

    <div class="quickSteps">
      <article class="quickStep">
        <div class="quickStepTop"><span class="quickStepNumber">01</span><span class="quickStepStatus"><span class="i18n" data-lang="ru">Начало</span><span class="i18n" data-lang="en">Start</span></span></div>
        <div class="quickStepEmoji">🤖</div>
        <h3><span class="i18n" data-lang="ru">Откройте Telegram-бота</span><span class="i18n" data-lang="en">Open the Telegram bot</span></h3>
        <p><span class="i18n" data-lang="ru">Перейдите в Mini Games World по кнопке на сайте. Бот станет вашей точкой входа в игру и будет присылать приглашения и важные уведомления.</span><span class="i18n" data-lang="en">Open Mini Games World from the website. The bot is your entry point and delivers invitations and important updates.</span></p>
        <div class="quickStepNote"><span class="i18n" data-lang="ru">Один переход — без скачивания</span><span class="i18n" data-lang="en">One tap — no download</span></div>
      </article>

      <article class="quickStep">
        <div class="quickStepTop"><span class="quickStepNumber">02</span><span class="quickStepStatus">Mini App</span></div>
        <div class="quickStepEmoji">📱</div>
        <h3><span class="i18n" data-lang="ru">Запустите приложение</span><span class="i18n" data-lang="en">Launch the Mini App</span></h3>
        <p><span class="i18n" data-lang="ru">Mini App откроется прямо внутри Telegram. Профиль создастся автоматически, а ваши балансы, статистика и история матчей будут доступны в одном месте.</span><span class="i18n" data-lang="en">The Mini App opens inside Telegram. Your profile is created automatically, with balances, statistics and match history in one place.</span></p>
        <div class="quickStepNote"><span class="i18n" data-lang="ru">Профиль готов автоматически</span><span class="i18n" data-lang="en">Profile created automatically</span></div>
      </article>

      <article class="quickStep">
        <div class="quickStepTop"><span class="quickStepNumber">03</span><span class="quickStepStatus"><span class="i18n" data-lang="ru">Выбор</span><span class="i18n" data-lang="en">Choose</span></span></div>
        <div class="quickStepEmoji">🎯</div>
        <h3><span class="i18n" data-lang="ru">Выберите комнату и игру</span><span class="i18n" data-lang="en">Choose a room and game</span></h3>
        <p><span class="i18n" data-lang="ru">Начните с Match-комнаты с фиксированной ставкой 10 коинов. Выберите крестики-нолики и подходящий размер поля — 3×3, 6×6 или 9×9.</span><span class="i18n" data-lang="en">Start in the Match room with a fixed 10-coin stake. Select tic-tac-toe and a 3×3, 6×6 or 9×9 board.</span></p>
        <div class="quickStepNote"><span class="i18n" data-lang="ru">Понятные условия до начала матча</span><span class="i18n" data-lang="en">Clear conditions before the match</span></div>
      </article>

      <article class="quickStep">
        <div class="quickStepTop"><span class="quickStepNumber">04</span><span class="quickStepStatus"><span class="i18n" data-lang="ru">Игра</span><span class="i18n" data-lang="en">Play</span></span></div>
        <div class="quickStepEmoji">⚔️</div>
        <h3><span class="i18n" data-lang="ru">Найдите соперника</span><span class="i18n" data-lang="en">Find an opponent</span></h3>
        <p><span class="i18n" data-lang="ru">Запустите быстрый поиск случайного игрока или создайте приглашение для друга. После завершения результат и изменения баланса сохранятся в истории.</span><span class="i18n" data-lang="en">Start quick matchmaking or create an invitation for a friend. The result and balance changes are saved in your history.</span></p>
        <div class="quickStepNote"><span class="i18n" data-lang="ru">Случайный соперник или друг</span><span class="i18n" data-lang="en">Random opponent or friend</span></div>
      </article>
    </div>

    <div class="quickStartAction">
      <div class="quickStartActionText"><b><span class="i18n" data-lang="ru">Готовы начать?</span><span class="i18n" data-lang="en">Ready to begin?</span></b><span><span class="i18n" data-lang="ru">Откройте Mini Games World и проведите первый матч прямо сейчас.</span><span class="i18n" data-lang="en">Open Mini Games World and play your first match right now.</span></span></div>
      <a class="quickStartButton" href="https://t.me/MiniGamesWorld_bot" target="_blank" rel="noopener noreferrer"><span class="i18n" data-lang="ru">Запустить в Telegram</span><span class="i18n" data-lang="en">Launch in Telegram</span></a>
    </div>
  </div>
</section>
HTML;

$pattern = '~<section class="section" id="how">.*?</section>~s';
$updated = preg_replace($pattern, $quickStartSection, $html, 1, $count);

if ($updated === null || $count !== 1) {
    http_response_code(500);
    echo 'Quick start section could not be updated.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
echo $updated;
