<?php
$source = __DIR__ . '/index.html';
$html = is_file($source) ? file_get_contents($source) : '';

if ($html === false || $html === '') {
    http_response_code(500);
    echo 'Landing page source is unavailable.';
    exit;
}

$favicon = '<link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="shortcut icon" href="/favicon.svg">';
if (strpos($html, '/favicon.svg') === false) {
    $html = str_replace('</head>', $favicon . '</head>', $html);
}

$extraCss = <<<'CSS'
<style id="landing-targeted-styles">
.gamesShowcase{position:relative;overflow:hidden}
.gamesShowcase:before{content:"";position:absolute;inset:5% -12% auto 42%;height:430px;background:radial-gradient(circle,rgba(242,45,134,.12),transparent 68%);pointer-events:none}
.gamesShowcase .gamesTop{display:flex;align-items:flex-end;justify-content:space-between;gap:28px;margin-bottom:28px}
.gamesShowcase .gamesIntro{max-width:720px}
.gamesShowcase .gamesKicker,.experienceSection .experienceKicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;color:#c68cff;font-size:12px;font-weight:950;letter-spacing:.16em;text-transform:uppercase}
.gamesShowcase .gamesIntro h2,.experienceSection .experienceHead h2{font-size:clamp(36px,4.8vw,60px);line-height:1.03;letter-spacing:-.055em;margin:0 0 14px}
.gamesShowcase .gamesIntro p,.experienceSection .experienceHead p{max-width:720px;margin:0;color:var(--muted);font-size:17px;line-height:1.7}
.gamesShowcase .gamesMore{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border:1px solid rgba(177,76,255,.34);border-radius:14px;background:linear-gradient(145deg,rgba(139,92,246,.16),rgba(242,45,134,.08));font-weight:900;transition:.25s}
.gamesShowcase .gamesMore:hover{transform:translateY(-2px);border-color:rgba(242,45,134,.62);box-shadow:0 16px 40px rgba(139,92,246,.13)}
.gamesShowcase .gamesGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));grid-auto-rows:minmax(235px,auto);gap:16px}
.gamesShowcase .gameCard{--glow:139,92,246;position:relative;isolation:isolate;display:flex;flex-direction:column;min-height:235px;padding:24px;border:1px solid rgba(255,255,255,.09);border-radius:24px;background:linear-gradient(145deg,rgba(255,255,255,.065),rgba(255,255,255,.018));overflow:hidden;transition:transform .28s ease,border-color .28s ease,box-shadow .28s ease}
.gamesShowcase .gameCard:before{content:"";position:absolute;z-index:-2;right:-55px;bottom:-65px;width:180px;height:180px;border-radius:50%;background:rgba(var(--glow),.19);filter:blur(5px)}
.gamesShowcase .gameCard:after{content:"";position:absolute;z-index:-1;inset:0;background:linear-gradient(135deg,rgba(var(--glow),.09),transparent 42%);opacity:.85}
.gamesShowcase .gameCard:hover{transform:translateY(-6px);border-color:rgba(var(--glow),.5);box-shadow:0 24px 55px rgba(0,0,0,.24)}
.gamesShowcase .gameFeatured{grid-column:span 2;grid-row:span 2;min-height:486px;padding:30px;--glow:139,92,246;background:radial-gradient(circle at 84% 18%,rgba(242,45,134,.17),transparent 30%),radial-gradient(circle at 20% 85%,rgba(72,200,255,.12),transparent 30%),linear-gradient(145deg,rgba(139,92,246,.17),rgba(255,255,255,.024))}
.gamesShowcase .gameFeatured .gameEmoji{font-size:66px}.gamesShowcase .gameFeatured h3{font-size:34px;letter-spacing:-.035em;margin-top:28px}.gamesShowcase .gameFeatured p{max-width:520px;font-size:16px;line-height:1.72}.gamesShowcase .gameFeatured .gameMeta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:auto;padding-top:24px}
.gamesShowcase .gameMetaItem{padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:16px;background:rgba(8,11,21,.34)}.gamesShowcase .gameMetaItem b{display:block;font-size:16px;margin-bottom:4px}.gamesShowcase .gameMetaItem small{color:var(--muted);line-height:1.4}
.gamesShowcase .gameEmoji{font-size:40px;line-height:1;filter:drop-shadow(0 8px 16px rgba(0,0,0,.2))}.gamesShowcase .gameCard h3{font-size:21px;line-height:1.2;margin:22px 0 10px}.gamesShowcase .gameCard p{margin:0;color:var(--muted);font-size:14px;line-height:1.62}.gamesShowcase .gameBottom{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:auto;padding-top:22px}.gamesShowcase .gameStatus{display:inline-flex;align-items:center;min-height:30px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:950}.gamesShowcase .gameStatus.available{color:#8ef2cb;background:rgba(57,223,155,.12);border:1px solid rgba(57,223,155,.18)}.gamesShowcase .gameStatus.soon{color:#ffd87d;background:rgba(255,200,87,.12);border:1px solid rgba(255,200,87,.18)}.gamesShowcase .gameStatus.development{color:#efa0ff;background:rgba(177,76,255,.13);border:1px solid rgba(177,76,255,.2)}.gamesShowcase .gameStatus.planned{color:#cbbdff;background:rgba(139,92,246,.13);border:1px solid rgba(139,92,246,.2)}.gamesShowcase .gameType{color:#858fa5;font-size:12px;font-weight:800}.gamesShowcase .battleship{--glow:72,200,255}.gamesShowcase .connect{--glow:242,45,134}.gamesShowcase .durak{--glow:177,76,255}.gamesShowcase .chess{--glow:139,92,246}

.experienceSection{position:relative;overflow:hidden}.experienceSection:before{content:"";position:absolute;left:-12%;bottom:-20%;width:560px;height:560px;border-radius:50%;background:radial-gradient(circle,rgba(57,223,155,.08),transparent 68%);pointer-events:none}.experienceSection .experienceHead{display:flex;align-items:flex-end;justify-content:space-between;gap:28px;margin-bottom:30px}.experienceSection .experienceHeadText{max-width:760px}.experienceSection .experienceHeadNote{max-width:360px;padding:16px 18px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(255,255,255,.025);color:#b8c1d3;font-size:14px;line-height:1.55}
.experienceGrid{display:grid;grid-template-columns:1.06fr .82fr 1.15fr;gap:16px}.experienceCard{position:relative;isolation:isolate;padding:28px;border:1px solid rgba(255,255,255,.09);border-radius:26px;background:linear-gradient(145deg,rgba(255,255,255,.055),rgba(255,255,255,.018));overflow:hidden}.experienceCard:before{content:"";position:absolute;z-index:-1;right:-80px;bottom:-100px;width:220px;height:220px;border-radius:50%;background:rgba(139,92,246,.1);filter:blur(2px)}.experienceCard.telegram:before{background:rgba(72,200,255,.13)}.experienceCard.numbers:before{background:rgba(177,76,255,.12)}.experienceCard.roomsCompare:before{background:rgba(255,106,34,.12)}
.experienceCardTop{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.experienceIcon{width:50px;height:50px;border-radius:16px;display:grid;place-items:center;font-size:25px;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.08)}.experienceLabel{display:inline-flex;padding:7px 10px;border-radius:999px;background:rgba(255,255,255,.045);color:#aeb8ca;font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.experienceCard h3{font-size:28px;letter-spacing:-.035em;margin:0 0 10px}.experienceCard>p{margin:0;color:var(--muted);font-size:15px;line-height:1.68}
.benefitList{display:grid;gap:12px;margin-top:24px}.benefitItem{display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:start;padding:13px 14px;border:1px solid rgba(255,255,255,.07);border-radius:16px;background:rgba(8,11,21,.28)}.benefitItem span:first-child{font-size:20px}.benefitItem b{display:block;margin-bottom:3px;font-size:14px}.benefitItem small{color:var(--muted);line-height:1.45}
.factGrid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}.fact{min-height:112px;padding:18px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(8,11,21,.3)}.fact strong{display:block;font-size:30px;letter-spacing:-.04em;margin-bottom:8px}.fact span{color:var(--muted);font-size:13px;line-height:1.45}.factAccent{background:linear-gradient(145deg,rgba(139,92,246,.16),rgba(8,11,21,.3))}
.roomsCards{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}.experienceRoom{padding:20px;border:1px solid rgba(255,255,255,.09);border-radius:20px;min-height:228px}.experienceRoom.match{background:linear-gradient(145deg,rgba(24,182,119,.24),rgba(7,58,43,.48))}.experienceRoom.gold{background:linear-gradient(145deg,rgba(255,130,25,.24),rgba(78,30,10,.5))}.roomTitle{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px}.roomTitle h4{font-size:23px;margin:0}.roomBadge{padding:6px 9px;border-radius:999px;background:rgba(255,255,255,.09);font-size:10px;font-weight:900}.roomFeatureList{display:grid;gap:10px}.roomFeature{display:flex;gap:9px;color:#e3e8f1;font-size:13px;line-height:1.45}.roomFeature span:first-child{font-weight:900}.roomFoot{margin-top:18px;padding-top:16px;border-top:1px solid rgba(255,255,255,.1);color:#c8d0de;font-size:12px;line-height:1.5}
@media(max-width:1120px){.gamesShowcase .gamesGrid{grid-template-columns:repeat(2,minmax(0,1fr))}.gamesShowcase .gameFeatured{grid-column:span 2;grid-row:auto;min-height:420px}.experienceGrid{grid-template-columns:1fr 1fr}.experienceCard.roomsCompare{grid-column:span 2}.experienceSection .experienceHead{align-items:flex-start;flex-direction:column}.experienceSection .experienceHeadNote{max-width:620px}}
@media(max-width:700px){.gamesShowcase .gamesTop{align-items:flex-start;flex-direction:column}.gamesShowcase .gamesMore{width:100%}.gamesShowcase .gamesGrid{grid-template-columns:1fr}.gamesShowcase .gameFeatured{grid-column:auto;min-height:auto}.gamesShowcase .gameFeatured .gameMeta{grid-template-columns:1fr}.gamesShowcase .gameCard{min-height:245px}.gamesShowcase .gameFeatured .gameEmoji{font-size:54px}.gamesShowcase .gameFeatured h3{font-size:28px}.experienceGrid{grid-template-columns:1fr}.experienceCard.roomsCompare{grid-column:auto}.roomsCards{grid-template-columns:1fr}.experienceCard{padding:22px}.factGrid{grid-template-columns:1fr 1fr}}
</style>
CSS;

if (strpos($html, 'landing-targeted-styles') === false) {
    $html = str_replace('</head>', $extraCss . '</head>', $html);
}

$gamesSection = <<<'HTML'
<section class="section gamesShowcase" id="games">
  <div class="wrap">
    <div class="gamesTop">
      <div class="gamesIntro">
        <div class="gamesKicker">🎮 <span class="i18n" data-lang="ru">Игровая коллекция</span><span class="i18n" data-lang="en">Game collection</span></div>
        <h2><span class="i18n" data-lang="ru">Игры для быстрых и честных дуэлей</span><span class="i18n" data-lang="en">Games built for quick and fair duels</span></h2>
        <p><span class="i18n" data-lang="ru">Начинаем со знакомой классики и постепенно расширяем каталог. Каждый режим создаётся для коротких матчей один на один, удобного управления в Telegram и понятного результата без долгого обучения.</span><span class="i18n" data-lang="en">We start with familiar classics and gradually expand the catalogue. Every mode is designed for short one-on-one matches, easy Telegram controls and a clear result without a long learning curve.</span></p>
      </div>
      <a class="gamesMore" href="/blog/upcoming-games/"><span class="i18n" data-lang="ru">Подробнее об играх →</span><span class="i18n" data-lang="en">Explore upcoming games →</span></a>
    </div>
    <div class="gamesGrid">
      <article class="gameCard gameFeatured">
        <div class="gameEmoji">✕○</div>
        <h3><span class="i18n" data-lang="ru">Крестики-нолики</span><span class="i18n" data-lang="en">Tic-tac-toe</span></h3>
        <p><span class="i18n" data-lang="ru">Первая доступная игра Mini Games World. Выбирайте классическое поле 3×3 или более глубокие форматы 6×6 и 9×9, находите случайного соперника либо приглашайте друга. Быстрые ходы, понятные правила и отдельная история каждого матча.</span><span class="i18n" data-lang="en">The first playable Mini Games World title. Choose the classic 3×3 board or deeper 6×6 and 9×9 formats, find a random opponent or invite a friend. Quick turns, familiar rules and a separate record for every match.</span></p>
        <div class="gameMeta"><div class="gameMetaItem"><b>3×3 · 6×6 · 9×9</b><small><span class="i18n" data-lang="ru">три размера поля</span><span class="i18n" data-lang="en">three board sizes</span></small></div><div class="gameMetaItem"><b>1 vs 1</b><small><span class="i18n" data-lang="ru">случайный игрок или друг</span><span class="i18n" data-lang="en">random player or friend</span></small></div><div class="gameMetaItem"><b>Match</b><small><span class="i18n" data-lang="ru">фиксированная ставка 10 коинов</span><span class="i18n" data-lang="en">fixed 10-coin stake</span></small></div></div>
        <div class="gameBottom"><span class="gameStatus available"><span class="i18n" data-lang="ru">Доступно сейчас</span><span class="i18n" data-lang="en">Available now</span></span><span class="gameType"><span class="i18n" data-lang="ru">Стратегия</span><span class="i18n" data-lang="en">Strategy</span></span></div>
      </article>
      <article class="gameCard battleship"><div class="gameEmoji">🚢</div><h3><span class="i18n" data-lang="ru">Морской бой</span><span class="i18n" data-lang="en">Battleship</span></h3><p><span class="i18n" data-lang="ru">Расставьте флот, скрывайте тактику и по координатам находите корабли соперника. Знакомая игра получит быстрый формат с синхронными ходами и защитой игрового поля.</span><span class="i18n" data-lang="en">Place your fleet, hide your tactics and locate enemy ships by coordinates. The familiar classic will receive fast turns, synchronized boards and protected game state.</span></p><div class="gameBottom"><span class="gameStatus soon"><span class="i18n" data-lang="ru">Следующая игра</span><span class="i18n" data-lang="en">Next release</span></span><span class="gameType"><span class="i18n" data-lang="ru">Тактика</span><span class="i18n" data-lang="en">Tactics</span></span></div></article>
      <article class="gameCard connect"><div class="gameEmoji">🔴</div><h3>Connect Four</h3><p><span class="i18n" data-lang="ru">По очереди опускайте цветные фишки и первым соберите четыре в ряд — по горизонтали, вертикали или диагонали. Простые правила скрывают глубокую тактику.</span><span class="i18n" data-lang="en">Drop coloured pieces in turns and connect four horizontally, vertically or diagonally before your opponent. Simple rules hide surprisingly deep tactics.</span></p><div class="gameBottom"><span class="gameStatus soon"><span class="i18n" data-lang="ru">Скоро</span><span class="i18n" data-lang="en">Coming soon</span></span><span class="gameType"><span class="i18n" data-lang="ru">Логика</span><span class="i18n" data-lang="en">Logic</span></span></div></article>
      <article class="gameCard durak"><div class="gameEmoji">🃏</div><h3><span class="i18n" data-lang="ru">Дурак</span><span class="i18n" data-lang="en">Durak</span></h3><p><span class="i18n" data-lang="ru">Популярная карточная игра в компактном онлайн-формате. Короткие партии, понятный ход игры и удобное управление картами прямо внутри Telegram.</span><span class="i18n" data-lang="en">A popular card game adapted to compact online sessions with quick rounds, familiar gameplay and easy card controls directly inside Telegram.</span></p><div class="gameBottom"><span class="gameStatus development"><span class="i18n" data-lang="ru">В разработке</span><span class="i18n" data-lang="en">In development</span></span><span class="gameType"><span class="i18n" data-lang="ru">Карты</span><span class="i18n" data-lang="en">Cards</span></span></div></article>
      <article class="gameCard chess"><div class="gameEmoji">♞</div><h3><span class="i18n" data-lang="ru">Шахматы Blitz</span><span class="i18n" data-lang="en">Blitz Chess</span></h3><p><span class="i18n" data-lang="ru">Полноценные шахматные дуэли с коротким контролем времени. Режим рассчитан на быстрые решения, давление таймера и динамичные партии без долгого ожидания.</span><span class="i18n" data-lang="en">Full chess duels with a short time control, built around quick decisions, clock pressure and dynamic games without a long wait.</span></p><div class="gameBottom"><span class="gameStatus planned"><span class="i18n" data-lang="ru">В планах</span><span class="i18n" data-lang="en">Planned</span></span><span class="gameType"><span class="i18n" data-lang="ru">Классика</span><span class="i18n" data-lang="en">Classic</span></span></div></article>
    </div>
  </div>
</section>
HTML;

$roomsSection = <<<'HTML'
<section class="section experienceSection" id="rooms">
  <div class="wrap">
    <div class="experienceHead">
      <div class="experienceHeadText">
        <div class="experienceKicker">✨ <span class="i18n" data-lang="ru">Как устроен проект</span><span class="i18n" data-lang="en">How the platform works</span></div>
        <h2><span class="i18n" data-lang="ru">Всё необходимое для быстрой игры</span><span class="i18n" data-lang="en">Everything needed for quick play</span></h2>
        <p><span class="i18n" data-lang="ru">Mini Games World объединяет быстрый запуск, понятную игровую экономику и два разных формата матчей в одном Telegram Mini App.</span><span class="i18n" data-lang="en">Mini Games World combines instant access, a clear game economy and two distinct match formats inside one Telegram Mini App.</span></p>
      </div>
      <div class="experienceHeadNote"><span class="i18n" data-lang="ru">Один профиль, две комнаты и единая история матчей — без установки отдельного приложения.</span><span class="i18n" data-lang="en">One profile, two rooms and a unified match history — without installing a separate app.</span></div>
    </div>
    <div class="experienceGrid">
      <article class="experienceCard telegram">
        <div class="experienceCardTop"><div class="experienceIcon">✈️</div><span class="experienceLabel">Telegram Mini App</span></div>
        <h3><span class="i18n" data-lang="ru">Почему Telegram?</span><span class="i18n" data-lang="en">Why Telegram?</span></h3>
        <p><span class="i18n" data-lang="ru">Игра открывается внутри привычного приложения и использует ваш Telegram-профиль для входа, приглашений и уведомлений.</span><span class="i18n" data-lang="en">The game opens inside a familiar app and uses your Telegram profile for access, invitations and notifications.</span></p>
        <div class="benefitList">
          <div class="benefitItem"><span>⚡</span><div><b><span class="i18n" data-lang="ru">Мгновенный запуск</span><span class="i18n" data-lang="en">Instant launch</span></b><small><span class="i18n" data-lang="ru">Один переход из бота — и вы уже в игре.</span><span class="i18n" data-lang="en">One tap from the bot and you are ready to play.</span></small></div></div>
          <div class="benefitItem"><span>👥</span><div><b><span class="i18n" data-lang="ru">Друзья уже рядом</span><span class="i18n" data-lang="en">Friends are already there</span></b><small><span class="i18n" data-lang="ru">Приглашение в матч отправляется прямо в Telegram.</span><span class="i18n" data-lang="en">Match invitations are sent directly in Telegram.</span></small></div></div>
          <div class="benefitItem"><span>📱</span><div><b><span class="i18n" data-lang="ru">Телефон и компьютер</span><span class="i18n" data-lang="en">Mobile and desktop</span></b><small><span class="i18n" data-lang="ru">Одинаково удобно на смартфоне и ПК.</span><span class="i18n" data-lang="en">Comfortable to use on both mobile and desktop.</span></small></div></div>
        </div>
      </article>
      <article class="experienceCard numbers">
        <div class="experienceCardTop"><div class="experienceIcon">📊</div><span class="experienceLabel"><span class="i18n" data-lang="ru">Текущая версия</span><span class="i18n" data-lang="en">Current version</span></span></div>
        <h3><span class="i18n" data-lang="ru">Проект в цифрах</span><span class="i18n" data-lang="en">Project facts</span></h3>
        <p><span class="i18n" data-lang="ru">Коротко о том, что уже доступно игрокам прямо сейчас.</span><span class="i18n" data-lang="en">A quick view of what players can already use today.</span></p>
        <div class="factGrid">
          <div class="fact factAccent"><strong>1</strong><span><span class="i18n" data-lang="ru">игра доступна</span><span class="i18n" data-lang="en">game available</span></span></div>
          <div class="fact"><strong>2</strong><span><span class="i18n" data-lang="ru">игровые комнаты</span><span class="i18n" data-lang="en">game rooms</span></span></div>
          <div class="fact"><strong>&lt;10 сек</strong><span><span class="i18n" data-lang="ru">до запуска Mini App</span><span class="i18n" data-lang="en">to launch the Mini App</span></span></div>
          <div class="fact"><strong>24/7</strong><span><span class="i18n" data-lang="ru">доступность сервиса</span><span class="i18n" data-lang="en">service availability</span></span></div>
        </div>
      </article>
      <article class="experienceCard roomsCompare">
        <div class="experienceCardTop"><div class="experienceIcon">🎮</div><span class="experienceLabel"><span class="i18n" data-lang="ru">Выбор формата</span><span class="i18n" data-lang="en">Choose your format</span></span></div>
        <h3><span class="i18n" data-lang="ru">Две комнаты — два опыта</span><span class="i18n" data-lang="en">Two rooms — two experiences</span></h3>
        <p><span class="i18n" data-lang="ru">Выберите спокойную регулярную игру или соревновательный режим с отдельным балансом.</span><span class="i18n" data-lang="en">Choose regular play or a competitive mode with a separate balance.</span></p>
        <div class="roomsCards">
          <div class="experienceRoom match"><div class="roomTitle"><h4>Match</h4><span class="roomBadge"><span class="i18n" data-lang="ru">Доступно</span><span class="i18n" data-lang="en">Available</span></span></div><div class="roomFeatureList"><div class="roomFeature"><span>✓</span><span><span class="i18n" data-lang="ru">Фиксированная ставка 10 коинов</span><span class="i18n" data-lang="en">Fixed 10-coin stake</span></span></div><div class="roomFeature"><span>✓</span><span><span class="i18n" data-lang="ru">Еженедельные начисления активным игрокам</span><span class="i18n" data-lang="en">Weekly rewards for active players</span></span></div><div class="roomFeature"><span>✓</span><span><span class="i18n" data-lang="ru">Подходит для регулярных матчей</span><span class="i18n" data-lang="en">Designed for regular matches</span></span></div></div><div class="roomFoot"><span class="i18n" data-lang="ru">Лучший выбор для знакомства с платформой.</span><span class="i18n" data-lang="en">The best place to start with the platform.</span></div></div>
          <div class="experienceRoom gold"><div class="roomTitle"><h4>Gold</h4><span class="roomBadge"><span class="i18n" data-lang="ru">Готовится</span><span class="i18n" data-lang="en">Preparing</span></span></div><div class="roomFeatureList"><div class="roomFeature"><span>✓</span><span><span class="i18n" data-lang="ru">Выбор размера ставки</span><span class="i18n" data-lang="en">Selectable stake amount</span></span></div><div class="roomFeature"><span>✓</span><span><span class="i18n" data-lang="ru">Отдельный Gold-баланс</span><span class="i18n" data-lang="en">Separate Gold balance</span></span></div><div class="roomFeature"><span>✓</span><span><span class="i18n" data-lang="ru">Пополнения и вывод средств</span><span class="i18n" data-lang="en">Funding and withdrawals</span></span></div></div><div class="roomFoot"><span class="i18n" data-lang="ru">Соревновательный режим откроется после финального тестирования.</span><span class="i18n" data-lang="en">The competitive mode will open after final testing.</span></div></div>
        </div>
      </article>
    </div>
  </div>
</section>
HTML;

$gamesPattern = '~<section class="section" id="games">.*?</section>~s';
$html = preg_replace($gamesPattern, $gamesSection, $html, 1, $gamesCount);
$roomsPattern = '~<section class="section" id="rooms">.*?</section>~s';
$html = preg_replace($roomsPattern, $roomsSection, $html, 1, $roomsCount);

if ($html === null || $gamesCount !== 1 || $roomsCount !== 1) {
    http_response_code(500);
    echo 'Landing sections could not be updated.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
