<?php
ob_start();
require __DIR__ . '/landing-v2.php';
$html = ob_get_clean();

$faqCss = <<<'CSS'
<style id="faq-targeted-styles">
.quickSteps:before{display:none!important}
.faqProSection{position:relative;overflow:hidden}
.faqProSection:before{content:"";position:absolute;right:-10%;top:0;width:560px;height:560px;border-radius:50%;background:radial-gradient(circle,rgba(139,92,246,.12),transparent 68%);pointer-events:none}
.faqProHead{display:flex;align-items:flex-end;justify-content:space-between;gap:32px;margin-bottom:28px}
.faqProIntro{max-width:780px}
.faqProKicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;color:#c68cff;font-size:12px;font-weight:950;letter-spacing:.16em;text-transform:uppercase}
.faqProHead h2{font-size:clamp(36px,4.8vw,60px);line-height:1.03;letter-spacing:-.055em;margin:0 0 14px}
.faqProHead p{max-width:760px;margin:0;color:var(--muted);font-size:17px;line-height:1.7}
.faqProSummary{max-width:350px;padding:17px 18px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:linear-gradient(145deg,rgba(139,92,246,.09),rgba(255,255,255,.022));color:#bdc6d7;font-size:13px;line-height:1.55}
.faqTopics{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:20px}
.faqTopic{display:inline-flex;align-items:center;gap:7px;min-height:34px;padding:0 12px;border:1px solid rgba(255,255,255,.075);border-radius:999px;background:rgba(255,255,255,.025);color:#aeb8ca;font-size:11px;font-weight:850}
.faqProGrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}
.faqProItem{--faqGlow:139,92,246;position:relative;border:1px solid rgba(255,255,255,.09);border-radius:21px;background:linear-gradient(145deg,rgba(255,255,255,.052),rgba(255,255,255,.016));overflow:hidden;transition:border-color .28s ease,box-shadow .28s ease,transform .28s ease}
.faqProItem:nth-child(3n+2){--faqGlow:72,200,255}.faqProItem:nth-child(3n){--faqGlow:242,45,134}
.faqProItem:hover{transform:translateY(-2px);border-color:rgba(var(--faqGlow),.34)}
.faqProItem.open{border-color:rgba(var(--faqGlow),.48);box-shadow:0 18px 50px rgba(0,0,0,.18)}
.faqProButton{width:100%;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:14px;padding:20px 21px;color:#fff;background:none;border:0;text-align:left;cursor:pointer}
.faqProIcon{width:40px;height:40px;border-radius:13px;display:grid;place-items:center;font-size:19px;background:rgba(var(--faqGlow),.12);border:1px solid rgba(var(--faqGlow),.18)}
.faqProQuestion{font-size:15px;font-weight:900;line-height:1.42}
.faqProPlus{width:32px;height:32px;border-radius:11px;display:grid;place-items:center;background:rgba(255,255,255,.045);font-size:21px;line-height:1;transition:transform .32s ease,background .32s ease}
.faqProItem.open .faqProPlus{transform:rotate(45deg);background:rgba(var(--faqGlow),.14)}
.faqProAnswer{max-height:0;overflow:hidden;transition:max-height .46s cubic-bezier(.4,0,.2,1)}
.faqProAnswerInner{padding:0 21px 22px 75px;color:var(--muted);font-size:13.5px;line-height:1.75}
.faqProAnswerInner p{margin:0 0 11px}.faqProAnswerInner p:last-child{margin-bottom:0}
.faqProAnswerInner strong{color:#e8edf6}
.faqProAnswerInner ul{margin:10px 0 0;padding-left:18px}.faqProAnswerInner li{margin:6px 0}
.faqProFooter{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-top:18px;padding:20px 22px;border:1px solid rgba(255,255,255,.08);border-radius:21px;background:radial-gradient(circle at 0 50%,rgba(72,200,255,.1),transparent 36%),rgba(255,255,255,.02)}
.faqProFooterText b{display:block;margin-bottom:4px;font-size:17px}.faqProFooterText span{color:var(--muted);font-size:13px;line-height:1.5}
.faqProFooterLink{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 18px;border:1px solid rgba(177,76,255,.3);border-radius:13px;background:rgba(139,92,246,.1);font-weight:900;white-space:nowrap;transition:.25s}
.faqProFooterLink:hover{transform:translateY(-2px);border-color:rgba(242,45,134,.55)}
@media(max-width:900px){.faqProHead{align-items:flex-start;flex-direction:column}.faqProSummary{max-width:650px}.faqProGrid{grid-template-columns:1fr}}
@media(max-width:600px){.faqProButton{grid-template-columns:auto 1fr auto;padding:17px 16px;gap:11px}.faqProIcon{width:36px;height:36px}.faqProQuestion{font-size:14px}.faqProAnswerInner{padding:0 16px 19px 63px;font-size:13px}.faqProFooter{align-items:flex-start;flex-direction:column}.faqProFooterLink{width:100%}.faqProHead p{font-size:15px}}
</style>
CSS;

if (strpos($html, 'faq-targeted-styles') === false) {
    $html = str_replace('</head>', $faqCss . '</head>', $html);
}

$faqSection = <<<'HTML'
<section class="section faqProSection" id="faq">
  <div class="wrap">
    <div class="faqProHead">
      <div class="faqProIntro">
        <div class="faqProKicker">💬 <span class="i18n" data-lang="ru">Ответы перед стартом</span><span class="i18n" data-lang="en">Answers before you start</span></div>
        <h2><span class="i18n" data-lang="ru">Частые вопросы о Mini Games World</span><span class="i18n" data-lang="en">Frequently asked questions about Mini Games World</span></h2>
        <p><span class="i18n" data-lang="ru">Собрали понятные ответы о запуске Mini App, игровых комнатах, коинах, матчах с друзьями, безопасности и будущих обновлениях.</span><span class="i18n" data-lang="en">Clear answers about launching the Mini App, game rooms, coins, friend matches, safety and upcoming updates.</span></p>
      </div>
      <div class="faqProSummary"><span class="i18n" data-lang="ru">Не нашли нужный ответ? Откройте Telegram-бота — через него можно запустить игру и получить актуальную информацию о проекте.</span><span class="i18n" data-lang="en">Could not find an answer? Open the Telegram bot to launch the game and get the latest project information.</span></div>
    </div>

    <div class="faqTopics">
      <span class="faqTopic">🚀 <span class="i18n" data-lang="ru">Запуск</span><span class="i18n" data-lang="en">Launch</span></span>
      <span class="faqTopic">🎮 <span class="i18n" data-lang="ru">Матчи</span><span class="i18n" data-lang="en">Matches</span></span>
      <span class="faqTopic">🪙 <span class="i18n" data-lang="ru">Коины</span><span class="i18n" data-lang="en">Coins</span></span>
      <span class="faqTopic">👥 <span class="i18n" data-lang="ru">Игра с друзьями</span><span class="i18n" data-lang="en">Friends</span></span>
      <span class="faqTopic">🛡️ <span class="i18n" data-lang="ru">Безопасность</span><span class="i18n" data-lang="en">Safety</span></span>
    </div>

    <div class="faqProGrid">
      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">🎯</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Что такое Mini Games World?</span><span class="i18n" data-lang="en">What is Mini Games World?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Mini Games World — игровая платформа внутри Telegram для коротких матчей один на один. Сейчас основной доступный режим — крестики-нолики, а каталог постепенно расширяется новыми играми.</span><span class="i18n" data-lang="en">Mini Games World is a gaming platform inside Telegram for short one-on-one matches. Tic-tac-toe is currently the main playable mode, while more games are being added gradually.</span></p><p><span class="i18n" data-lang="ru">Профиль, балансы, история матчей и приглашения друзей находятся внутри одного Mini App.</span><span class="i18n" data-lang="en">Your profile, balances, match history and friend invitations all stay inside one Mini App.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">📲</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Нужно ли скачивать отдельное приложение?</span><span class="i18n" data-lang="en">Do I need to download a separate app?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Нет. Игра запускается как Telegram Mini App прямо внутри Telegram. Не нужно искать приложение в магазине, устанавливать его или создавать отдельный пароль.</span><span class="i18n" data-lang="en">No. The game launches as a Telegram Mini App directly inside Telegram. There is no app-store download, installation or separate password.</span></p><p><span class="i18n" data-lang="ru">Для входа используется ваш Telegram-профиль, поэтому старт занимает всего несколько действий.</span><span class="i18n" data-lang="en">Your Telegram profile is used for access, so getting started only takes a few taps.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">🟢</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Как работает Match-комната?</span><span class="i18n" data-lang="en">How does the Match room work?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Match — обычная комната для регулярной игры. Ставка фиксирована и составляет <strong>10 коинов</strong>, поэтому оба участника заранее играют на одинаковых условиях.</span><span class="i18n" data-lang="en">Match is the standard room for regular play. The stake is fixed at <strong>10 coins</strong>, so both players enter under the same conditions.</span></p><p><span class="i18n" data-lang="ru">Можно искать случайного соперника или пригласить друга. Результат матча и изменения баланса сохраняются в истории.</span><span class="i18n" data-lang="en">You can find a random opponent or invite a friend. Match results and balance changes are saved in history.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">✨</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Чем Gold-комната отличается от Match?</span><span class="i18n" data-lang="en">How is the Gold room different from Match?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Gold — отдельный соревновательный режим с собственным балансом и выбором размера ставки. В нём планируются пополнение, история финансовых операций и вывод средств.</span><span class="i18n" data-lang="en">Gold is a separate competitive mode with its own balance and selectable stakes. Funding, financial history and withdrawals are planned.</span></p><p><span class="i18n" data-lang="ru">Комната пока готовится к запуску. Перед открытием проверяются расчёты, защита от повторных списаний и корректное завершение матчей при нестабильном соединении.</span><span class="i18n" data-lang="en">The room is still being prepared. Calculations, duplicate-charge protection and reliable match completion under unstable connections are being tested before launch.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">🪙</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Как начисляются бесплатные Match-коины?</span><span class="i18n" data-lang="en">How are free Match coins awarded?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Каждый понедельник в 12:00 активным игрокам начисляется <strong>50 Match-коинов</strong>. Новый пользователь получает право на первое начисление после входа в проект.</span><span class="i18n" data-lang="en">Every Monday at 12:00, active players receive <strong>50 Match coins</strong>. A new user qualifies for the first reward after entering the project.</span></p><p><span class="i18n" data-lang="ru">Для последующих начислений необходимо сыграть не менее трёх матчей за квалификационную неделю. Коины добавляются отдельной операцией, которую можно увидеть в истории.</span><span class="i18n" data-lang="en">To receive future rewards, play at least three matches during the qualifying week. Coins are added as a separate operation visible in history.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">👥</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Как сыграть с другом?</span><span class="i18n" data-lang="en">How do I play with a friend?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Создайте матч и нажмите «Пригласить друга». Telegram сформирует приглашение, которое можно отправить нужному человеку прямо в чате.</span><span class="i18n" data-lang="en">Create a match and choose “Invite a friend.” Telegram generates an invitation that can be sent directly in chat.</span></p><p><span class="i18n" data-lang="ru">Друг открывает приглашение, входит в тот же игровой формат и присоединяется к созданной комнате. Условия матча фиксируются до начала игры.</span><span class="i18n" data-lang="en">Your friend opens the invitation, enters the same game format and joins the created room. Match conditions are fixed before the game starts.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">🔎</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Как подбирается случайный соперник?</span><span class="i18n" data-lang="en">How is a random opponent selected?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Система ищет игрока в той же комнате, с той же игрой, размером поля и условиями ставки. Это исключает ситуацию, когда участники начинают матч с разными настройками.</span><span class="i18n" data-lang="en">The system searches for a player in the same room with the same game, board size and stake conditions. This prevents mismatched settings.</span></p><p><span class="i18n" data-lang="ru">Если поиск затягивается, можно выйти из очереди и выбрать другой формат или активную ставку.</span><span class="i18n" data-lang="en">If matchmaking takes too long, you can leave the queue and choose another format or active stake.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">⏱️</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Что произойдёт, если закрыть Mini App во время матча?</span><span class="i18n" data-lang="en">What happens if I close the Mini App during a match?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Матч хранится на сервере, а не только в открытом окне Telegram. Итог зависит от текущего состояния партии, таймера и правил выхода.</span><span class="i18n" data-lang="en">The match is stored on the server, not only in the open Telegram window. The result depends on the current game state, timer and exit rules.</span></p><p><span class="i18n" data-lang="ru">Система должна корректно завершить игру, сохранить результат и не допустить повторного списания ставки. Рекомендуется не закрывать Mini App до окончания партии.</span><span class="i18n" data-lang="en">The system is designed to complete the game correctly, save the result and prevent duplicate stake deductions. It is best to keep the Mini App open until the match ends.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">🛡️</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Как защищаются баланс и история операций?</span><span class="i18n" data-lang="en">How are balances and operation history protected?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">Match и Gold используют раздельные балансы. Пополнения, начисления и игровые изменения записываются как отдельные операции, чтобы пользователь мог проверить движение коинов.</span><span class="i18n" data-lang="en">Match and Gold use separate balances. Funding, rewards and game-related changes are recorded as separate operations so players can review coin movements.</span></p><p><span class="i18n" data-lang="ru">Также предусмотрена защита от параллельного запуска нескольких матчей и повторной обработки одного действия.</span><span class="i18n" data-lang="en">The system also includes protection against parallel matches and duplicate processing of the same action.</span></p></div></div>
      </article>

      <article class="faqProItem">
        <button class="faqProButton" type="button" aria-expanded="false"><span class="faqProIcon">🧩</span><span class="faqProQuestion"><span class="i18n" data-lang="ru">Какие игры появятся дальше?</span><span class="i18n" data-lang="en">Which games are coming next?</span></span><span class="faqProPlus">+</span></button>
        <div class="faqProAnswer"><div class="faqProAnswerInner"><p><span class="i18n" data-lang="ru">В планах — Морской бой, Connect Four, карточная игра «Дурак» и быстрые шахматы. Каждый режим должен удобно работать на телефоне, поддерживать матчи один на один и иметь однозначный результат.</span><span class="i18n" data-lang="en">Planned titles include Battleship, Connect Four, Durak and Blitz Chess. Every mode must work well on mobile, support one-on-one matches and produce a clear result.</span></p><p><span class="i18n" data-lang="ru">Точные сроки зависят от тестирования матчмейкинга, таймеров и истории матчей. Подробнее — в статье о будущих играх.</span><span class="i18n" data-lang="en">Exact timing depends on testing matchmaking, timers and match history. More details are available in the upcoming-games article.</span></p></div></div>
      </article>
    </div>

    <div class="faqProFooter">
      <div class="faqProFooterText"><b><span class="i18n" data-lang="ru">Готовы проверить всё на практике?</span><span class="i18n" data-lang="en">Ready to try it yourself?</span></b><span><span class="i18n" data-lang="ru">Запустите Mini Games World в Telegram и начните с Match-комнаты.</span><span class="i18n" data-lang="en">Launch Mini Games World in Telegram and start in the Match room.</span></span></div>
      <a class="faqProFooterLink" href="https://t.me/MiniGamesWorld_bot" target="_blank" rel="noopener noreferrer"><span class="i18n" data-lang="ru">Открыть Telegram-бота</span><span class="i18n" data-lang="en">Open Telegram bot</span></a>
    </div>
  </div>
</section>
HTML;

$pattern = '~<section class="section" id="faq">.*?</section>~s';
$updated = preg_replace($pattern, $faqSection, $html, 1, $count);
if ($updated === null || $count !== 1) {
    http_response_code(500);
    echo 'FAQ section could not be updated.';
    exit;
}

$schema = <<<'SCHEMA'
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Mini Games World","url":"https://lemonchiffon-gerbil-545102.hostingersite.com/"},{"@type":"SoftwareApplication","name":"Mini Games World","applicationCategory":"GameApplication","operatingSystem":"Telegram","url":"https://t.me/MiniGamesWorld_bot","offers":{"@type":"Offer","price":"0","priceCurrency":"RUB"}},{"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Что такое Mini Games World?","acceptedAnswer":{"@type":"Answer","text":"Mini Games World — игровая платформа внутри Telegram для коротких матчей один на один. Профиль, балансы, история матчей и приглашения друзей находятся внутри одного Mini App."}},{"@type":"Question","name":"Нужно ли скачивать отдельное приложение?","acceptedAnswer":{"@type":"Answer","text":"Нет. Игра запускается как Telegram Mini App внутри Telegram и использует Telegram-профиль для входа."}},{"@type":"Question","name":"Как работает Match-комната?","acceptedAnswer":{"@type":"Answer","text":"Match — обычная комната с фиксированной ставкой 10 коинов, поиском случайного соперника и приглашениями друзей."}},{"@type":"Question","name":"Чем Gold-комната отличается от Match?","acceptedAnswer":{"@type":"Answer","text":"Gold — отдельный соревновательный режим с собственным балансом, выбором ставки, будущими пополнениями и выводом средств."}},{"@type":"Question","name":"Как начисляются бесплатные Match-коины?","acceptedAnswer":{"@type":"Answer","text":"Каждый понедельник в 12:00 активным игрокам начисляется 50 Match-коинов. Для последующих начислений необходимо сыграть не менее трёх матчей за квалификационную неделю."}},{"@type":"Question","name":"Как сыграть с другом?","acceptedAnswer":{"@type":"Answer","text":"Создайте матч, нажмите «Пригласить друга» и отправьте сформированное Telegram-приглашение нужному человеку."}},{"@type":"Question","name":"Как подбирается случайный соперник?","acceptedAnswer":{"@type":"Answer","text":"Система ищет игрока в той же комнате, с той же игрой, размером поля и условиями ставки."}},{"@type":"Question","name":"Что произойдёт, если закрыть Mini App во время матча?","acceptedAnswer":{"@type":"Answer","text":"Матч хранится на сервере. Итог зависит от состояния партии, таймера и правил выхода, а система сохраняет результат и предотвращает повторное списание."}},{"@type":"Question","name":"Как защищаются баланс и история операций?","acceptedAnswer":{"@type":"Answer","text":"Match и Gold используют раздельные балансы, а пополнения, начисления и игровые изменения записываются отдельными операциями."}},{"@type":"Question","name":"Какие игры появятся дальше?","acceptedAnswer":{"@type":"Answer","text":"В планах Морской бой, Connect Four, Дурак и Blitz Chess. Сроки запуска зависят от тестирования."}}]}]}</script>
SCHEMA;
$updated = preg_replace('~<script type="application/ld\+json">.*?</script>~s', $schema, $updated, 1);

$faqJs = <<<'JS'
<script id="faq-pro-script">
document.querySelectorAll('.faqProButton').forEach(function(button){
  button.addEventListener('click',function(){
    var item=button.closest('.faqProItem');
    var answer=item.querySelector('.faqProAnswer');
    var isOpen=item.classList.contains('open');
    document.querySelectorAll('.faqProItem.open').forEach(function(openItem){
      if(openItem!==item){openItem.classList.remove('open');openItem.querySelector('.faqProButton').setAttribute('aria-expanded','false');openItem.querySelector('.faqProAnswer').style.maxHeight='0px';}
    });
    item.classList.toggle('open',!isOpen);
    button.setAttribute('aria-expanded',String(!isOpen));
    answer.style.maxHeight=!isOpen?answer.scrollHeight+'px':'0px';
  });
});
window.addEventListener('resize',function(){document.querySelectorAll('.faqProItem.open .faqProAnswer').forEach(function(answer){answer.style.maxHeight=answer.scrollHeight+'px';});});
</script>
JS;
$updated = str_replace('</body>', $faqJs . '</body>', $updated);

header('Content-Type: text/html; charset=UTF-8');
echo $updated;
