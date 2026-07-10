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

$gamesCss = <<<'CSS'
<style id="games-showcase-styles">
.gamesShowcase{position:relative;overflow:hidden}
.gamesShowcase:before{content:"";position:absolute;inset:5% -12% auto 42%;height:430px;background:radial-gradient(circle,rgba(242,45,134,.12),transparent 68%);pointer-events:none}
.gamesShowcase .gamesTop{display:flex;align-items:flex-end;justify-content:space-between;gap:28px;margin-bottom:28px}
.gamesShowcase .gamesIntro{max-width:720px}
.gamesShowcase .gamesKicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;color:#c68cff;font-size:12px;font-weight:950;letter-spacing:.16em;text-transform:uppercase}
.gamesShowcase .gamesIntro h2{font-size:clamp(36px,4.8vw,60px);line-height:1.03;letter-spacing:-.055em;margin:0 0 14px}
.gamesShowcase .gamesIntro p{max-width:680px;margin:0;color:var(--muted);font-size:17px;line-height:1.7}
.gamesShowcase .gamesMore{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border:1px solid rgba(177,76,255,.34);border-radius:14px;background:linear-gradient(145deg,rgba(139,92,246,.16),rgba(242,45,134,.08));font-weight:900;transition:.25s}
.gamesShowcase .gamesMore:hover{transform:translateY(-2px);border-color:rgba(242,45,134,.62);box-shadow:0 16px 40px rgba(139,92,246,.13)}
.gamesShowcase .gamesGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));grid-auto-rows:minmax(235px,auto);gap:16px}
.gamesShowcase .gameCard{--glow:139,92,246;position:relative;isolation:isolate;display:flex;flex-direction:column;min-height:235px;padding:24px;border:1px solid rgba(255,255,255,.09);border-radius:24px;background:linear-gradient(145deg,rgba(255,255,255,.065),rgba(255,255,255,.018));overflow:hidden;transition:transform .28s ease,border-color .28s ease,box-shadow .28s ease}
.gamesShowcase .gameCard:before{content:"";position:absolute;z-index:-2;right:-55px;bottom:-65px;width:180px;height:180px;border-radius:50%;background:rgba(var(--glow),.19);filter:blur(5px)}
.gamesShowcase .gameCard:after{content:"";position:absolute;z-index:-1;inset:0;background:linear-gradient(135deg,rgba(var(--glow),.09),transparent 42%);opacity:.85}
.gamesShowcase .gameCard:hover{transform:translateY(-6px);border-color:rgba(var(--glow),.5);box-shadow:0 24px 55px rgba(0,0,0,.24)}
.gamesShowcase .gameFeatured{grid-column:span 2;grid-row:span 2;min-height:486px;padding:30px;--glow:139,92,246;background:radial-gradient(circle at 84% 18%,rgba(242,45,134,.17),transparent 30%),radial-gradient(circle at 20% 85%,rgba(72,200,255,.12),transparent 30%),linear-gradient(145deg,rgba(139,92,246,.17),rgba(255,255,255,.024))}
.gamesShowcase .gameFeatured .gameEmoji{font-size:66px}
.gamesShowcase .gameFeatured h3{font-size:34px;letter-spacing:-.035em;margin-top:28px}
.gamesShowcase .gameFeatured p{max-width:520px;font-size:16px;line-height:1.72}
.gamesShowcase .gameFeatured .gameMeta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:auto;padding-top:24px}
.gamesShowcase .gameMetaItem{padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:16px;background:rgba(8,11,21,.34)}
.gamesShowcase .gameMetaItem b{display:block;font-size:16px;margin-bottom:4px}
.gamesShowcase .gameMetaItem small{color:var(--muted);line-height:1.4}
.gamesShowcase .gameEmoji{font-size:40px;line-height:1;filter:drop-shadow(0 8px 16px rgba(0,0,0,.2))}
.gamesShowcase .gameCard h3{font-size:21px;line-height:1.2;margin:22px 0 10px}
.gamesShowcase .gameCard p{margin:0;color:var(--muted);font-size:14px;line-height:1.62}
.gamesShowcase .gameBottom{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:auto;padding-top:22px}
.gamesShowcase .gameStatus{display:inline-flex;align-items:center;min-height:30px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:950}
.gamesShowcase .gameStatus.available{color:#8ef2cb;background:rgba(57,223,155,.12);border:1px solid rgba(57,223,155,.18)}
.gamesShowcase .gameStatus.soon{color:#ffd87d;background:rgba(255,200,87,.12);border:1px solid rgba(255,200,87,.18)}
.gamesShowcase .gameStatus.development{color:#efa0ff;background:rgba(177,76,255,.13);border:1px solid rgba(177,76,255,.2)}
.gamesShowcase .gameStatus.planned{color:#cbbdff;background:rgba(139,92,246,.13);border:1px solid rgba(139,92,246,.2)}
.gamesShowcase .gameType{color:#858fa5;font-size:12px;font-weight:800}
.gamesShowcase .battleship{--glow:72,200,255}
.gamesShowcase .connect{--glow:242,45,134}
.gamesShowcase .durak{--glow:177,76,255}
.gamesShowcase .chess{--glow:139,92,246}
.gamesShowcase .dice{--glow:255,106,34}
@media(max-width:1120px){.gamesShowcase .gamesGrid{grid-template-columns:repeat(2,minmax(0,1fr))}.gamesShowcase .gameFeatured{grid-column:span 2;grid-row:auto;min-height:420px}.gamesShowcase .gameFeatured .gameMeta{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.gamesShowcase .gamesTop{align-items:flex-start;flex-direction:column}.gamesShowcase .gamesMore{width:100%}.gamesShowcase .gamesGrid{grid-template-columns:1fr}.gamesShowcase .gameFeatured{grid-column:auto;min-height:auto}.gamesShowcase .gameFeatured .gameMeta{grid-template-columns:1fr}.gamesShowcase .gameCard{min-height:245px}.gamesShowcase .gameFeatured .gameEmoji{font-size:54px}.gamesShowcase .gameFeatured h3{font-size:28px}}
</style>
CSS;

if (strpos($html, 'games-showcase-styles') === false) {
    $html = str_replace('</head>', $gamesCss . '</head>', $html);
}

$gamesSection = <<<'HTML'
<section class="section gamesShowcase" id="games">
  <div class="wrap">
    <div class="gamesTop">
      <div class="gamesIntro">
        <div class="gamesKicker">🎮 <span class="i18n" data-lang="ru">Игровая коллекция</span><span class="i18n" data-lang="en">Game collection</span></div>
        <h2><span class="i18n" data-lang="ru">Игры для быстрых и честных дуэлей</span><span class="i18n" data-lang="en">Games built for quick and fair duels</span></h2>
        <p><span class="i18n" data-lang="ru">Начинаем с знакомой классики и постепенно расширяем каталог. Каждый режим создаётся для коротких матчей один на один, удобного управления в Telegram и понятного результата без долгого обучения.</span><span class="i18n" data-lang="en">We start with familiar classics and gradually expand the catalogue. Every mode is designed for short one-on-one matches, easy Telegram controls and a clear result without a long learning curve.</span></p>
      </div>
      <a class="gamesMore" href="/blog/upcoming-games/"><span class="i18n" data-lang="ru">Подробнее об играх →</span><span class="i18n" data-lang="en">Explore upcoming games →</span></a>
    </div>

    <div class="gamesGrid">
      <article class="gameCard gameFeatured">
        <div class="gameEmoji">✕○</div>
        <h3><span class="i18n" data-lang="ru">Крестики-нолики</span><span class="i18n" data-lang="en">Tic-tac-toe</span></h3>
        <p><span class="i18n" data-lang="ru">Первая доступная игра Mini Games World. Выбирайте классическое поле 3×3 или более глубокие форматы 6×6 и 9×9, находите случайного соперника либо приглашайте друга. Быстрые ходы, понятные правила и отдельная история каждого матча.</span><span class="i18n" data-lang="en">The first playable Mini Games World title. Choose the classic 3×3 board or deeper 6×6 and 9×9 formats, find a random opponent or invite a friend. Quick turns, familiar rules and a separate record for every match.</span></p>
        <div class="gameMeta">
          <div class="gameMetaItem"><b>3×3 · 6×6 · 9×9</b><small><span class="i18n" data-lang="ru">три размера поля</span><span class="i18n" data-lang="en">three board sizes</span></small></div>
          <div class="gameMetaItem"><b>1 vs 1</b><small><span class="i18n" data-lang="ru">случайный игрок или друг</span><span class="i18n" data-lang="en">random player or friend</span></small></div>
          <div class="gameMetaItem"><b>Match</b><small><span class="i18n" data-lang="ru">фиксированная ставка 10 коинов</span><span class="i18n" data-lang="en">fixed 10-coin stake</span></small></div>
        </div>
        <div class="gameBottom"><span class="gameStatus available"><span class="i18n" data-lang="ru">Доступно сейчас</span><span class="i18n" data-lang="en">Available now</span></span><span class="gameType"><span class="i18n" data-lang="ru">Стратегия</span><span class="i18n" data-lang="en">Strategy</span></span></div>
      </article>

      <article class="gameCard battleship">
        <div class="gameEmoji">🚢</div>
        <h3><span class="i18n" data-lang="ru">Морской бой</span><span class="i18n" data-lang="en">Battleship</span></h3>
        <p><span class="i18n" data-lang="ru">Расставьте флот, скрывайте тактику и по координатам находите корабли соперника. Знакомая игра получит быстрый формат с синхронными ходами и защитой игрового поля.</span><span class="i18n" data-lang="en">Place your fleet, hide your tactics and locate enemy ships by coordinates. The familiar classic will receive fast turns, synchronized boards and protected game state.</span></p>
        <div class="gameBottom"><span class="gameStatus soon"><span class="i18n" data-lang="ru">Следующая игра</span><span class="i18n" data-lang="en">Next release</span></span><span class="gameType"><span class="i18n" data-lang="ru">Тактика</span><span class="i18n" data-lang="en">Tactics</span></span></div>
      </article>

      <article class="gameCard connect">
        <div class="gameEmoji">🔴</div>
        <h3>Connect Four</h3>
        <p><span class="i18n" data-lang="ru">По очереди опускайте цветные фишки и первым соберите четыре в ряд — по горизонтали, вертикали или диагонали. Простые правила скрывают глубокую тактику.</span><span class="i18n" data-lang="en">Drop coloured pieces in turns and connect four horizontally, vertically or diagonally before your opponent. Simple rules hide surprisingly deep tactics.</span></p>
        <div class="gameBottom"><span class="gameStatus soon"><span class="i18n" data-lang="ru">Скоро</span><span class="i18n" data-lang="en">Coming soon</span></span><span class="gameType"><span class="i18n" data-lang="ru">Логика</span><span class="i18n" data-lang="en">Logic</span></span></div>
      </article>

      <article class="gameCard durak">
        <div class="gameEmoji">🃏</div>
        <h3><span class="i18n" data-lang="ru">Дурак</span><span class="i18n" data-lang="en">Durak</span></h3>
        <p><span class="i18n" data-lang="ru">Популярная карточная игра в компактном онлайн-формате. Короткие партии, понятный ход игры и удобное управление картами прямо внутри Telegram.</span><span class="i18n" data-lang="en">A popular card game adapted to compact online sessions with quick rounds, familiar gameplay and easy card controls directly inside Telegram.</span></p>
        <div class="gameBottom"><span class="gameStatus development"><span class="i18n" data-lang="ru">В разработке</span><span class="i18n" data-lang="en">In development</span></span><span class="gameType"><span class="i18n" data-lang="ru">Карты</span><span class="i18n" data-lang="en">Cards</span></span></div>
      </article>

      <article class="gameCard chess">
        <div class="gameEmoji">♞</div>
        <h3><span class="i18n" data-lang="ru">Шахматы Blitz</span><span class="i18n" data-lang="en">Blitz Chess</span></h3>
        <p><span class="i18n" data-lang="ru">Полноценные шахматные дуэли с коротким контролем времени. Режим рассчитан на быстрые решения, давление таймера и динамичные партии без долгого ожидания.</span><span class="i18n" data-lang="en">Full chess duels with a short time control, built around quick decisions, clock pressure and dynamic games without a long wait.</span></p>
        <div class="gameBottom"><span class="gameStatus planned"><span class="i18n" data-lang="ru">В планах</span><span class="i18n" data-lang="en">Planned</span></span><span class="gameType"><span class="i18n" data-lang="ru">Классика</span><span class="i18n" data-lang="en">Classic</span></span></div>
      </article>

      <article class="gameCard dice">
        <div class="gameEmoji">🎲</div>
        <h3>Dice Battles</h3>
        <p><span class="i18n" data-lang="ru">Серия коротких раундов, где бросок кубиков сочетается с выбором действий. Удача создаёт ситуацию, а решение игрока определяет, как ею воспользоваться.</span><span class="i18n" data-lang="en">A sequence of short rounds where dice rolls combine with tactical choices. Luck creates the situation, while the player's decision determines how to use it.</span></p>
        <div class="gameBottom"><span class="gameStatus planned"><span class="i18n" data-lang="ru">В планах</span><span class="i18n" data-lang="en">Planned</span></span><span class="gameType"><span class="i18n" data-lang="ru">Удача и тактика</span><span class="i18n" data-lang="en">Luck and tactics</span></span></div>
      </article>
    </div>
  </div>
</section>
HTML;

$pattern = '~<section class="section" id="games">.*?</section>~s';
$updated = preg_replace($pattern, $gamesSection, $html, 1, $count);

if ($updated === null || $count !== 1) {
    http_response_code(500);
    echo 'Games section could not be updated.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
echo $updated;
