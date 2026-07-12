<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/landing-v12.php';
$html = (string) ob_get_clean();

$howSection = <<<'HTML'
<section class="section startV13" id="how" aria-labelledby="how-v13-title">
  <div class="wrap">
    <div class="v13Head">
      <div>
        <div class="v13Kicker">4 шага до первого матча</div>
        <h2 id="how-v13-title">Как начать играть в Mini Games World</h2>
        <p>Весь путь проходит внутри Telegram: откройте бота, запустите Mini App, выберите комнату и игру, а затем найдите соперника или отправьте приглашение другу.</p>
      </div>
      <aside><strong>5 игр</strong><span>доступны в комнатах Match и Gold без отдельной установки</span></aside>
    </div>

    <div class="startV13Flow" data-v13-reveal>
      <div class="startV13Line" aria-hidden="true"><i></i></div>

      <article class="startV13Step" style="--step-delay:0ms">
        <div class="startV13StepTop"><span class="startV13Number">01</span><i class="startV13Icon" aria-hidden="true">✈</i></div>
        <h3>Откройте Telegram-бота</h3>
        <p>Перейдите в Mini Games World через бота. Telegram-профиль используется для входа, приглашений и уведомлений.</p>
        <div class="startV13Proof"><span class="startV13Dot"></span>Без отдельной регистрации</div>
      </article>

      <article class="startV13Step" style="--step-delay:100ms">
        <div class="startV13StepTop"><span class="startV13Number">02</span><i class="startV13Icon" aria-hidden="true">▣</i></div>
        <h3>Запустите Mini App</h3>
        <p>Нажмите кнопку запуска в боте. Профиль, балансы, история матчей и магазин откроются прямо внутри Telegram.</p>
        <div class="startV13Proof"><span class="startV13Dot"></span>Один интерфейс для всех функций</div>
      </article>

      <article class="startV13Step startV13StepChoice" style="--step-delay:200ms">
        <div class="startV13StepTop"><span class="startV13Number">03</span><i class="startV13Icon" aria-hidden="true">🎮</i></div>
        <h3>Выберите комнату и одну из пяти игр</h3>
        <p>Сначала выберите Match или Gold, затем игру и доступный для неё размер поля.</p>
        <div class="startV13Rooms"><b class="active">Match</b><b>Gold</b></div>
        <div class="startV13Games" aria-label="Пять доступных игр">
          <span title="Крестики-нолики">✕○</span>
          <span title="4 в ряд">🔴</span>
          <span title="Морской бой">⚓</span>
          <span title="Шашки">⚪</span>
          <span title="Реверси">◐</span>
        </div>
        <small>Крестики-нолики · 4 в ряд · Морской бой · Шашки · Реверси</small>
      </article>

      <article class="startV13Step" style="--step-delay:300ms">
        <div class="startV13StepTop"><span class="startV13Number">04</span><i class="startV13Icon" aria-hidden="true">⚔</i></div>
        <h3>Найдите соперника или пригласите друга</h3>
        <p>Запустите случайный поиск по выбранным условиям либо создайте ссылку-приглашение и отправьте её другу в Telegram.</p>
        <div class="startV13Modes"><span><i>⌕</i>Случайный поиск</span><span><i>↗</i>Пригласить друга</span></div>
      </article>
    </div>

    <div class="startV13Footer">
      <div><strong>Готовы начать?</strong><span>Все пять игр и обе комнаты уже доступны в Mini App.</span></div>
      <a href="https://t.me/MiniGamesWorld_bot" target="_blank" rel="noopener noreferrer">Открыть Mini Games World <span aria-hidden="true">→</span></a>
    </div>
  </div>
</section>
HTML;

$roadmapSection = <<<'HTML'
<section class="section roadmapV13" id="roadmap" aria-labelledby="roadmap-v13-title" data-roadmap-v13>
  <div class="wrap">
    <div class="v13Head roadmapV13Head">
      <div>
        <div class="v13Kicker">Актуальное состояние проекта</div>
        <h2 id="roadmap-v13-title">Что уже работает и куда движется Mini Games World</h2>
        <p>Roadmap больше не рассказывает историю разработки. Он показывает текущую продуктовую основу и направления следующего этапа без неподтверждённых дат запуска.</p>
      </div>
      <aside><strong>11 готово</strong><span>ключевых функций уже работают в текущей версии</span></aside>
    </div>

    <div class="roadmapV13Board">
      <div class="roadmapV13Top">
        <div class="roadmapV13State">
          <span class="roadmapV13Pulse" aria-hidden="true"></span>
          <div><small>Текущий статус</small><strong>Основное ядро запущено</strong></div>
        </div>
        <div class="roadmapV13Counters">
          <span><b>11</b> готовых функций</span>
          <span><b>6</b> направлений развития</span>
        </div>
      </div>

      <div class="roadmapV13Progress" aria-hidden="true">
        <span class="roadmapV13ProgressDone"><i></i></span>
        <span class="roadmapV13ProgressNext"></span>
        <b class="roadmapV13Marker done">Готово</b>
        <b class="roadmapV13Marker next">Следующий этап</b>
      </div>

      <div class="roadmapV13Columns">
        <article class="roadmapV13Phase roadmapV13Ready">
          <header>
            <div><span class="roadmapV13PhaseIcon">✓</span><div><small>Текущая версия</small><h3>Готово</h3></div></div>
            <b>Работает сейчас</b>
          </header>
          <p>Основные игровые, финансовые и пользовательские сценарии уже собраны в одном Telegram Mini App.</p>
          <div class="roadmapV13ReadyGrid">
            <span style="--item-delay:0ms"><i>⚔</i>Match-комната</span>
            <span style="--item-delay:45ms"><i>◆</i>Gold-комната</span>
            <span style="--item-delay:90ms"><i>🎮</i>Пять игр</span>
            <span style="--item-delay:135ms"><i>↗</i>Приглашения</span>
            <span style="--item-delay:180ms"><i>₽</i>Пополнения</span>
            <span style="--item-delay:225ms"><i>👤</i>Профиль</span>
            <span style="--item-delay:270ms"><i>▤</i>История матчей</span>
            <span style="--item-delay:315ms"><i>⇄</i>История операций</span>
            <span style="--item-delay:360ms"><i>🔔</i>Уведомления</span>
            <span style="--item-delay:405ms"><i>🎁</i>Магазин</span>
            <span style="--item-delay:450ms"><i>✓</i>Заявки на призы</span>
          </div>
        </article>

        <article class="roadmapV13Phase roadmapV13Next">
          <div class="roadmapV13Orbit" aria-hidden="true">
            <span class="roadmapV13OrbitCenter">MG</span>
            <i class="o1"></i><i class="o2"></i><i class="o3"></i>
          </div>
          <header>
            <div><span class="roadmapV13PhaseIcon">→</span><div><small>Без объявленных сроков</small><h3>Следующий этап</h3></div></div>
            <b>В развитии</b>
          </header>
          <p>Следующие направления будут запускаться по мере проектирования, тестирования и готовности инфраструктуры.</p>
          <ol class="roadmapV13NextList">
            <li style="--item-delay:0ms"><span>01</span><div><b>Расширение каталога призов</b><small>Больше стран, брендов, сертификатов и номиналов.</small></div></li>
            <li style="--item-delay:70ms"><span>02</span><div><b>Развитие уведомлений</b><small>Более точные игровые, финансовые и статусные события.</small></div></li>
            <li style="--item-delay:140ms"><span>03</span><div><b>Улучшение матчмейкинга</b><small>Быстрее поиск и точнее подбор подходящего соперника.</small></div></li>
            <li style="--item-delay:210ms"><span>04</span><div><b>Новые игры</b><small>Расширение каталога только после подтверждения и тестирования.</small></div></li>
            <li style="--item-delay:280ms"><span>05</span><div><b>Полноценная система вывода средств</b><small>Отдельный будущий этап с юридической, платёжной и технической проработкой.</small></div></li>
            <li style="--item-delay:350ms"><span>06</span><div><b>Дальнейшая полировка интерфейса</b><small>Скорость, доступность, мобильный UX и визуальная консистентность.</small></div></li>
          </ol>
          <div class="roadmapV13Warning"><i>!</i><span><b>Сейчас денежный вывод недоступен.</b> Этот пункт относится только к будущему этапу и не меняет текущие правила Gold-магазина.</span></div>
        </article>
      </div>
    </div>
  </div>
</section>
HTML;

$css = <<<'CSS'
<style id="how-roadmap-v13-styles">
:root{--v13-bg:#090c14;--v13-card:#151b2b;--v13-card2:#1b2336;--v13-line:rgba(255,255,255,.09);--v13-text:#f7f8fb;--v13-muted:#a7b0c2;--v13-purple:#7c5cff;--v13-purple-dark:#6548e6;--v13-green:#2ee6a6;--v13-gold:#ffc857;--v13-red:#ff6c78}
.startV13,.roadmapV13{position:relative;isolation:isolate;overflow:hidden}
.startV13:before,.roadmapV13:before{content:"";position:absolute;z-index:-2;width:680px;height:680px;border-radius:50%;pointer-events:none}
.startV13:before{left:-400px;top:-270px;background:radial-gradient(circle,rgba(124,92,255,.105),transparent 70%)}
.roadmapV13:before{right:-390px;bottom:-410px;background:radial-gradient(circle,rgba(46,230,166,.075),transparent 70%)}
.v13Head{display:grid;grid-template-columns:minmax(0,1fr) 230px;align-items:end;gap:30px;margin-bottom:32px}
.v13Head>div{max-width:850px}.v13Kicker{display:inline-flex;align-items:center;gap:9px;margin-bottom:13px;color:#c9c1ff;font-size:12px;font-weight:950;letter-spacing:.14em;text-transform:uppercase}
.v13Kicker:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--v13-green);box-shadow:0 0 0 5px rgba(46,230,166,.08)}
.v13Head h2{margin:0 0 15px;font-size:clamp(38px,4.8vw,64px);line-height:1.02;letter-spacing:-.055em}
.v13Head p{max-width:780px;margin:0;color:var(--v13-muted);font-size:17px;line-height:1.72}
.v13Head aside{padding:18px 20px;border:1px solid var(--v13-line);border-radius:20px;background:rgba(21,27,43,.78)}
.v13Head aside strong{display:block;color:var(--v13-green);font-size:25px;letter-spacing:-.04em}.v13Head aside span{display:block;margin-top:5px;color:#8d97aa;font-size:11px;line-height:1.48}
.startV13Flow{position:relative;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.startV13Line{position:absolute;z-index:0;left:7%;right:7%;top:41px;height:2px;background:#242b3c;overflow:hidden}
.startV13Line i{display:block;width:0;height:100%;background:var(--v13-purple);box-shadow:0 0 20px rgba(124,92,255,.45);transition:width 1.15s cubic-bezier(.2,.75,.2,1)}
.startV13Flow.is-visible .startV13Line i{width:100%}
.startV13Step{position:relative;z-index:1;display:flex;flex-direction:column;min-height:330px;padding:21px;border:1px solid var(--v13-line);border-radius:24px;background:rgba(21,27,43,.88);opacity:0;transform:translateY(24px);transition:opacity .55s ease var(--step-delay),transform .55s ease var(--step-delay),border-color .25s ease,box-shadow .25s ease}
.startV13Flow.is-visible .startV13Step{opacity:1;transform:none}.startV13Step:hover{border-color:rgba(124,92,255,.3);box-shadow:0 22px 55px rgba(0,0,0,.2)}
.startV13StepTop{display:flex;align-items:center;justify-content:space-between;gap:12px}.startV13Number{display:grid;place-items:center;width:42px;height:42px;border:1px solid rgba(124,92,255,.25);border-radius:14px;background:#111725;color:#cfc8ff;font-size:10px;font-weight:950}
.startV13Icon{width:40px;height:40px;display:grid;place-items:center;border-radius:13px;background:rgba(124,92,255,.1);font-style:normal;font-size:18px}.startV13Step:nth-child(4) .startV13Icon{background:rgba(255,200,87,.09)}.startV13Step:nth-child(5) .startV13Icon{background:rgba(46,230,166,.075)}
.startV13Step h3{margin:23px 0 9px;font-size:21px;line-height:1.2;letter-spacing:-.025em}.startV13Step p{margin:0;color:var(--v13-muted);font-size:13px;line-height:1.62}
.startV13Proof{display:flex;align-items:center;gap:8px;margin-top:auto;padding-top:20px;color:#8de9ca;font-size:10px;font-weight:850}.startV13Dot{width:7px;height:7px;border-radius:50%;background:var(--v13-green);box-shadow:0 0 12px rgba(46,230,166,.5)}
.startV13Rooms{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-top:15px;padding:4px;border:1px solid rgba(255,255,255,.075);border-radius:12px;background:#111725}.startV13Rooms b{padding:7px;text-align:center;border-radius:8px;color:#7e899d;font-size:9px}.startV13Rooms b.active{background:rgba(124,92,255,.16);color:#fff}
.startV13Games{display:grid;grid-template-columns:repeat(5,1fr);gap:5px;margin-top:9px}.startV13Games span{display:grid;place-items:center;min-height:35px;border:1px solid rgba(255,255,255,.065);border-radius:10px;background:rgba(255,255,255,.025);font-size:13px}.startV13StepChoice>small{display:block;margin-top:8px;color:#768196;font-size:7.5px;line-height:1.45}
.startV13Modes{display:grid;gap:7px;margin-top:auto;padding-top:17px}.startV13Modes span{display:flex;align-items:center;gap:8px;padding:9px;border:1px solid rgba(255,255,255,.065);border-radius:11px;background:#111725;color:#cbd2df;font-size:9.5px;font-weight:850}.startV13Modes i{width:23px;height:23px;display:grid;place-items:center;border-radius:8px;background:rgba(46,230,166,.075);font-style:normal}
.startV13Footer{display:flex;align-items:center;justify-content:space-between;gap:22px;margin-top:18px;padding:19px 21px;border:1px solid rgba(255,255,255,.075);border-radius:20px;background:rgba(255,255,255,.022)}.startV13Footer strong,.startV13Footer span{display:block}.startV13Footer strong{font-size:15px}.startV13Footer>div>span{margin-top:4px;color:#8994a7;font-size:11px}.startV13Footer a{display:inline-flex;align-items:center;justify-content:center;min-height:45px;padding:0 17px;border-radius:13px;background:var(--v13-purple);font-size:11px;font-weight:950;white-space:nowrap;transition:.22s}.startV13Footer a:hover,.startV13Footer a:focus-visible{background:var(--v13-purple-dark);transform:translateY(-2px);outline:none}
.roadmapV13Head aside strong{color:var(--v13-gold)}.roadmapV13Board{position:relative;padding:25px;border:1px solid var(--v13-line);border-radius:30px;background:#101522;box-shadow:0 34px 90px rgba(0,0,0,.22);overflow:hidden}
.roadmapV13Board:before{content:"";position:absolute;right:-190px;top:-240px;width:490px;height:490px;border-radius:50%;background:radial-gradient(circle,rgba(124,92,255,.13),transparent 68%);pointer-events:none}
.roadmapV13Top{position:relative;display:flex;align-items:center;justify-content:space-between;gap:20px}.roadmapV13State{display:flex;align-items:center;gap:12px}.roadmapV13State small,.roadmapV13State strong{display:block}.roadmapV13State small{color:#7d879a;font-size:9px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}.roadmapV13State strong{margin-top:3px;font-size:14px}
.roadmapV13Pulse{position:relative;width:13px;height:13px;border-radius:50%;background:var(--v13-green);box-shadow:0 0 0 7px rgba(46,230,166,.075)}.roadmapV13Pulse:after{content:"";position:absolute;inset:-8px;border:1px solid rgba(46,230,166,.45);border-radius:50%;animation:v13Pulse 2.1s ease-out infinite}
.roadmapV13Counters{display:flex;gap:8px}.roadmapV13Counters span{padding:9px 11px;border:1px solid rgba(255,255,255,.07);border-radius:12px;background:rgba(255,255,255,.025);color:#8d97aa;font-size:9px}.roadmapV13Counters b{color:#fff;font-size:12px}
.roadmapV13Progress{position:relative;display:grid;grid-template-columns:62% 38%;height:8px;margin:25px 0 37px;border-radius:99px;background:#242b3b}.roadmapV13ProgressDone,.roadmapV13ProgressNext{display:block;height:100%;overflow:hidden}.roadmapV13ProgressDone{border-radius:99px 0 0 99px}.roadmapV13ProgressDone:before{content:"";display:block;width:0;height:100%;border-radius:99px;background:var(--v13-green);transition:width 1.15s cubic-bezier(.2,.75,.2,1)}.roadmapV13.is-visible .roadmapV13ProgressDone:before{width:100%}
.roadmapV13ProgressDone i{position:absolute;top:50%;left:0;width:18px;height:18px;border:4px solid #101522;border-radius:50%;background:var(--v13-green);box-shadow:0 0 0 5px rgba(46,230,166,.1),0 0 18px rgba(46,230,166,.45);transform:translate(-50%,-50%);opacity:0}.roadmapV13.is-visible .roadmapV13ProgressDone i{animation:v13Travel 1.2s ease forwards}
.roadmapV13ProgressNext{border-radius:0 99px 99px 0;background:repeating-linear-gradient(90deg,rgba(124,92,255,.28) 0 10px,transparent 10px 18px);background-size:36px 100%;animation:v13Dashes 2s linear infinite}
.roadmapV13Marker{position:absolute;top:17px;color:#7d879a;font-size:9px;text-transform:uppercase;letter-spacing:.1em}.roadmapV13Marker.done{left:0;color:#82e8c4}.roadmapV13Marker.next{left:62%;transform:translateX(-50%);color:#bcb3ff}
.roadmapV13Columns{display:grid;grid-template-columns:1.05fr .95fr;gap:16px}.roadmapV13Phase{position:relative;min-width:0;padding:24px;border:1px solid rgba(255,255,255,.075);border-radius:24px;background:rgba(21,27,43,.82);overflow:hidden}.roadmapV13Phase header{position:relative;display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.roadmapV13Phase header>div{display:flex;align-items:center;gap:11px}.roadmapV13PhaseIcon{width:45px;height:45px;display:grid;place-items:center;border-radius:14px;background:rgba(46,230,166,.08);color:#91edce;font-size:17px;font-weight:950}.roadmapV13Next .roadmapV13PhaseIcon{background:rgba(124,92,255,.11);color:#d0c9ff}.roadmapV13Phase header small{display:block;color:#7c879b;font-size:8px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}.roadmapV13Phase h3{margin:4px 0 0;font-size:25px;letter-spacing:-.035em}.roadmapV13Phase header>b{padding:7px 9px;border-radius:999px;background:rgba(46,230,166,.075);color:#91edce;font-size:8px}.roadmapV13Next header>b{background:rgba(124,92,255,.1);color:#cfc8ff}.roadmapV13Phase>p{position:relative;margin:17px 0;color:#8d97aa;font-size:11.5px;line-height:1.58}
.roadmapV13ReadyGrid{display:grid;grid-template-columns:1fr 1fr;gap:7px}.roadmapV13ReadyGrid span{display:flex;align-items:center;gap:8px;min-height:40px;padding:8px 9px;border:1px solid rgba(255,255,255,.06);border-radius:11px;background:rgba(9,12,20,.29);color:#cbd2de;font-size:9.5px;font-weight:800;opacity:0;transform:translateY(10px)}.roadmapV13.is-visible .roadmapV13ReadyGrid span{animation:v13ItemIn .45s ease forwards;animation-delay:calc(250ms + var(--item-delay))}.roadmapV13ReadyGrid i{width:24px;height:24px;display:grid;place-items:center;flex:0 0 auto;border-radius:8px;background:rgba(46,230,166,.07);font-style:normal;font-size:10px}
.roadmapV13NextList{position:relative;display:grid;gap:7px;margin:0;padding:0;list-style:none}.roadmapV13NextList li{display:grid;grid-template-columns:31px 1fr;gap:9px;padding:10px;border:1px solid rgba(255,255,255,.06);border-radius:12px;background:rgba(9,12,20,.29);opacity:0;transform:translateX(14px)}.roadmapV13.is-visible .roadmapV13NextList li{animation:v13ItemIn .5s ease forwards;animation-delay:calc(350ms + var(--item-delay))}.roadmapV13NextList>li>span{width:29px;height:29px;display:grid;place-items:center;border-radius:9px;background:rgba(124,92,255,.1);color:#cfc8ff;font-size:8px;font-weight:950}.roadmapV13NextList b,.roadmapV13NextList small{display:block}.roadmapV13NextList b{font-size:10.5px}.roadmapV13NextList small{margin-top:3px;color:#778296;font-size:8.5px;line-height:1.4}
.roadmapV13Warning{display:grid;grid-template-columns:30px 1fr;gap:9px;margin-top:10px;padding:11px;border:1px solid rgba(255,200,87,.16);border-radius:13px;background:rgba(255,200,87,.05);color:#b7aa86;font-size:9px;line-height:1.45}.roadmapV13Warning i{width:28px;height:28px;display:grid;place-items:center;border-radius:9px;background:rgba(255,200,87,.09);color:#ffe09a;font-style:normal;font-weight:950}.roadmapV13Warning b{color:#e9d9ad}
.roadmapV13Orbit{position:absolute;right:-22px;top:-28px;width:124px;height:124px;border:1px solid rgba(124,92,255,.13);border-radius:50%;animation:v13OrbitSpin 18s linear infinite}.roadmapV13Orbit:before{content:"";position:absolute;inset:17px;border:1px dashed rgba(124,92,255,.17);border-radius:50%}.roadmapV13OrbitCenter{position:absolute;inset:39px;display:grid;place-items:center;border-radius:50%;background:rgba(124,92,255,.13);color:#cfc8ff;font-size:10px;font-weight:950}.roadmapV13Orbit i{position:absolute;width:8px;height:8px;border-radius:50%;background:var(--v13-purple);box-shadow:0 0 12px rgba(124,92,255,.5)}.roadmapV13Orbit .o1{left:10px;top:38px}.roadmapV13Orbit .o2{right:14px;bottom:29px;background:var(--v13-green)}.roadmapV13Orbit .o3{right:37px;top:5px;background:var(--v13-gold)}
@keyframes v13Pulse{0%{transform:scale(.5);opacity:.8}100%{transform:scale(1.8);opacity:0}}@keyframes v13Dashes{to{background-position:36px 0}}@keyframes v13Travel{0%{left:0;opacity:0}12%{opacity:1}100%{left:62%;opacity:1}}@keyframes v13ItemIn{to{opacity:1;transform:none}}@keyframes v13OrbitSpin{to{transform:rotate(360deg)}}
@media(max-width:1120px){.startV13Flow{grid-template-columns:1fr 1fr}.startV13Line{display:none}.startV13Step{min-height:300px}.roadmapV13Columns{grid-template-columns:1fr}}
@media(max-width:760px){.v13Head{grid-template-columns:1fr}.v13Head aside{max-width:470px}.roadmapV13Top{align-items:flex-start;flex-direction:column}.roadmapV13Counters{width:100%}.roadmapV13Counters span{flex:1}.startV13Footer{align-items:flex-start;flex-direction:column}.startV13Footer a{width:100%}.roadmapV13Board{padding:18px}.roadmapV13Progress{margin-top:21px}.roadmapV13Orbit{opacity:.6}}
@media(max-width:560px){.v13Head h2{font-size:38px}.v13Head p{font-size:15px}.startV13Flow{grid-template-columns:1fr}.startV13Step{min-height:auto;padding:19px;border-radius:21px}.startV13Proof,.startV13Modes{margin-top:18px}.roadmapV13ReadyGrid{grid-template-columns:1fr}.roadmapV13Phase{padding:19px}.roadmapV13Counters{display:grid;grid-template-columns:1fr 1fr}.roadmapV13Phase header>b{display:none}.roadmapV13Progress{grid-template-columns:58% 42%}.roadmapV13Marker.next{left:58%}.roadmapV13.is-visible .roadmapV13ProgressDone i{animation-name:v13TravelMobile}}
@keyframes v13TravelMobile{0%{left:0;opacity:0}12%{opacity:1}100%{left:58%;opacity:1}}
@media(prefers-reduced-motion:reduce){.startV13Line i,.startV13Step,.roadmapV13ProgressDone:before{transition:none!important}.startV13Step,.roadmapV13ReadyGrid span,.roadmapV13NextList li{opacity:1!important;transform:none!important;animation:none!important}.roadmapV13Pulse:after,.roadmapV13ProgressNext,.roadmapV13Orbit,.roadmapV13ProgressDone i{animation:none!important}.roadmapV13ProgressDone:before{width:100%!important}.roadmapV13ProgressDone i{left:62%!important;opacity:1!important}}
</style>
CSS;

$script = <<<'HTML'
<script id="how-roadmap-v13-script">
(function(){
  'use strict';
  var targets=document.querySelectorAll('[data-v13-reveal],[data-roadmap-v13]');
  if(!targets.length)return;
  if(!('IntersectionObserver' in window)){
    targets.forEach(function(el){el.classList.add('is-visible');});
    return;
  }
  var observer=new IntersectionObserver(function(entries){
    entries.forEach(function(entry){
      if(entry.isIntersecting){
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  },{threshold:.18,rootMargin:'0px 0px -8% 0px'});
  targets.forEach(function(el){observer.observe(el);});
})();
</script>
HTML;

$html = preg_replace('~<section\b[^>]*\bid="how"[^>]*>.*?</section>~s', $howSection, $html, 1, $howCount) ?? $html;
$html = preg_replace('~<section\b[^>]*\bid="roadmap"[^>]*>.*?</section>~s', $roadmapSection, $html, 1, $roadmapCount) ?? $html;

if ($howCount !== 1 || $roadmapCount !== 1) {
    http_response_code(500);
    echo 'Разделы «Как начать» и «Наши планы» не удалось обновить.';
    exit;
}

$schema = <<<'HTML'
<script type="application/ld+json" id="how-roadmap-v13-schema">{"@context":"https://schema.org","@graph":[{"@type":"HowTo","name":"Как начать играть в Mini Games World","description":"Четыре шага для запуска игры внутри Telegram Mini App.","step":[{"@type":"HowToStep","position":1,"name":"Открыть Telegram-бота","text":"Откройте Telegram-бота Mini Games World."},{"@type":"HowToStep","position":2,"name":"Запустить Mini App","text":"Запустите Mini App внутри Telegram."},{"@type":"HowToStep","position":3,"name":"Выбрать комнату и игру","text":"Выберите Match или Gold и одну из пяти доступных игр."},{"@type":"HowToStep","position":4,"name":"Найти соперника","text":"Запустите случайный поиск или пригласите друга."}]},{"@type":"ItemList","name":"Готовые возможности Mini Games World","numberOfItems":11,"itemListElement":[{"@type":"ListItem","position":1,"name":"Match-комната"},{"@type":"ListItem","position":2,"name":"Gold-комната"},{"@type":"ListItem","position":3,"name":"Пять игр"},{"@type":"ListItem","position":4,"name":"Приглашения"},{"@type":"ListItem","position":5,"name":"Пополнения"},{"@type":"ListItem","position":6,"name":"Профиль"},{"@type":"ListItem","position":7,"name":"История матчей"},{"@type":"ListItem","position":8,"name":"История операций"},{"@type":"ListItem","position":9,"name":"Уведомления"},{"@type":"ListItem","position":10,"name":"Магазин"},{"@type":"ListItem","position":11,"name":"Заявки на призы"}]},{"@type":"ItemList","name":"Следующий этап Mini Games World","numberOfItems":6,"itemListElement":[{"@type":"ListItem","position":1,"name":"Расширение каталога призов"},{"@type":"ListItem","position":2,"name":"Развитие уведомлений"},{"@type":"ListItem","position":3,"name":"Улучшение матчмейкинга"},{"@type":"ListItem","position":4,"name":"Новые игры"},{"@type":"ListItem","position":5,"name":"Полноценная система вывода средств"},{"@type":"ListItem","position":6,"name":"Дальнейшая полировка интерфейса"}]}]}</script>
HTML;

$html = str_replace('</head>', $css . $schema . '</head>', $html);
$html = str_replace('</body>', $script . '</body>', $html);

$html = str_replace(
    'Gold — отдельная комната с собственным балансом, выбором ставки и магазином сертификатов. Денежного вывода в проекте нет.',
    'Gold — отдельная комната с собственным балансом, выбором ставки и магазином сертификатов. Сейчас денежного вывода нет; полноценная система вывода рассматривается как отдельный будущий этап без объявленных сроков.',
    $html
);
$html = str_replace(
    'Gold — отдельный соревновательный режим с собственным балансом, выбором ставки, будущими пополнениями и выводом средств.',
    'Gold — отдельная комната с собственным балансом, выбором ставки и магазином сертификатов. Денежный вывод сейчас недоступен и относится только к отдельному будущему этапу.',
    $html
);

$html = preg_replace(
    '~<meta\s+name="description"[^>]*>~i',
    '<meta name="description" content="Пять игр в Telegram, комнаты Match и Gold, магазин призов и профиль. Четыре шага до первого матча и актуальный roadmap Mini Games World.">',
    $html,
    1
) ?? $html;
$html = preg_replace(
    '~<meta\s+property="og:description"[^>]*>~i',
    '<meta property="og:description" content="Пять доступных игр, Match и Gold, магазин призов, простой старт в четыре шага и актуальные планы развития Mini Games World.">',
    $html,
    1
) ?? $html;

header('Content-Type: text/html; charset=UTF-8');
header('Content-Language: ru');
echo $html;
