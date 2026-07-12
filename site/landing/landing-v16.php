<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/landing-v14.php';
$html = (string) ob_get_clean();

$faqItems = [
 ['🎮','Какие игры доступны?',['Сейчас доступны пять игр: крестики-нолики, 4 в ряд, Морской бой, шашки и реверси.','Доступные поля: крестики-нолики — 3×3, 5×5 и 9×9; 4 в ряд — 6×5, 7×6 и 8×7; Морской бой — 10×10; шашки — 8×8; реверси — 6×6, 8×8 и 10×10.']],
 ['⚔️','В каких комнатах можно играть?',['Все пять игр доступны в комнатах Match и Gold. В обеих комнатах можно найти случайного соперника или пригласить друга.','Match использует отдельный баланс и фиксированную ставку 10 Match. Gold использует собственный баланс и позволяет выбрать размер ставки перед началом матча.']],
 ['◆','Какие ставки доступны в Gold?',['В Gold-комнате доступны ставки 10, 20, 30, 50 и 100 Gold с каждого участника.','Комната, игра, размер поля и ставка фиксируются до начала поиска соперника или отправки приглашения другу.']],
 ['₽','Как пополнить Match и Gold?',['Откройте раздел пополнения в Mini App, выберите баланс Match или Gold и укажите нужную сумму. Курс Match составляет 1 ₽ = 2 Match, курс Gold — 1 ₽ = 1 Gold.','Балансы не объединяются и не переводятся друг в друга. Сумма, количество коинов, дата и статус пополнения сохраняются в отдельной истории.']],
 ['🎁','Как работает магазин?',['В магазине нужно выбрать страну, сертификат и номинал, после чего подтвердить списание доступного для магазина Gold. Минимальная сумма заказа — 1000 Gold.','Заявка сразу появляется в разделе «Мои заявки». Повторный запрос не приводит к двойному списанию, при отклонении Gold возвращается, а ориентировочный срок ручной обработки администратором составляет до 24 часов.']],
 ['🔒','Почему весь Gold нельзя сразу потратить в магазине?',['Для заказа сертификатов используется только Gold, который прошёл через завершённые матчи в Gold-комнате. Пополненный, но ещё не использованный в завершённых матчах Gold остаётся на общем балансе, однако не входит в доступную магазину сумму.','В профиле общий баланс Gold и количество Gold, доступного для магазина, показываются отдельно.']],
 ['💳','Можно ли вывести Gold в деньги?',['Нет. В текущей версии денежный вывод на карту, банковский счёт или другим способом недоступен. Подходящий Gold можно использовать только для заказа сертификатов в магазине.','Полноценная система вывода указана в roadmap как отдельный будущий этап без объявленных сроков и не меняет действующие правила.']],
 ['🤝','Что происходит при ничьей?',['При ничьей поставленные коины возвращаются обоим участникам на соответствующие балансы Match или Gold.','Комиссия при ничьей не удерживается, поскольку победитель и выплата из общего банка отсутствуют.']],
 ['％','Как считается комиссия?',['Комиссия составляет 10% от общего банка завершённого матча. Общий банк формируется из ставок обоих игроков.','Например, при ставке 30 Gold с каждого участника банк составляет 60 Gold, комиссия — 6 Gold, а победитель получает 54 Gold. При ничьей комиссия не списывается.']],
 ['▤','Где посмотреть историю матчей?',['Откройте профиль и перейдите в историю матчей. Для каждой завершённой партии отображаются игра, комната, размер поля, ставка, соперник, результат, выигрыш и дата.','История помогает отдельно проверить результат матча и связанное с ним изменение баланса.']],
 ['⇄','Где посмотреть пополнения?',['История пополнений находится в профиле. В ней отображаются выбранный баланс, внесённая сумма, количество Match или Gold, статус операции и дата.','Если пополнение отклонено, причина также отображается в истории. Другие начисления и списания можно проверить в отдельной истории операций баланса.']],
 ['+50','Как получить еженедельные +50 Match?',['Завершите не менее трёх любых игр в течение квалификационной недели. Каждый понедельник в 12:00 подходящим игрокам начисляется 50 Match.','В Mini App показываются дата следующего бонуса, текущий прогресс и количество игр, которое осталось завершить.']],
 ['💬','Как отправить обращение?',['Откройте раздел связи с командой в Mini App и выберите подходящий вариант: обратная связь, «Предложить идею» или обращение в поддержку.','При сообщении о проблеме полезно указать игру, комнату, примерное время матча и описать, что произошло. Это поможет быстрее проверить обращение.']],
 ['🔔','Как работают уведомления?',['Игровые и сервисные события появляются в центре уведомлений. Счётчик рядом с иконкой показывает количество непрочитанных сообщений.','Уведомления помогают отслеживать матчи, изменения баланса, пополнения и статусы заявок на призы. После просмотра количество непрочитанных уменьшается.']],
];

$cards = '';
$questions = [];
foreach ($faqItems as $index => [$icon, $question, $answers]) {
    $number = $index + 1;
    $questionId = 'faq-question-' . $number;
    $answerId = 'faq-answer-' . $number;
    $paragraphs = '';
    foreach ($answers as $answer) {
        $paragraphs .= '<p>' . htmlspecialchars($answer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    $cards .= '<article class="faqProItem">'
        . '<button class="faqProButton" type="button" aria-expanded="false" aria-controls="' . $answerId . '">'
        . '<span class="faqProIcon" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
        . '<span class="faqProQuestion" id="' . $questionId . '">' . htmlspecialchars($question, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
        . '<span class="faqProPlus" aria-hidden="true">+</span></button>'
        . '<div class="faqProAnswer" id="' . $answerId . '" role="region" aria-labelledby="' . $questionId . '"><div class="faqProAnswerInner">'
        . $paragraphs . '</div></div></article>';
    $questions[] = [
        '@type' => 'Question',
        'name' => $question,
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => implode(' ', $answers)],
    ];
}

$faqSection = '<section class="section faqProSection" id="faq"><div class="wrap">'
    . '<div class="faqProHead"><div class="faqProIntro">'
    . '<div class="faqProKicker">💬 Ответы по текущей версии</div>'
    . '<h2>Частые вопросы о Mini Games World</h2>'
    . '<p>Актуальные правила пяти игр, комнат Match и Gold, ставок, пополнений, комиссии, магазина призов, историй, бонусов и уведомлений.</p>'
    . '</div><div class="faqProSummary">14 подробных ответов без устаревших обещаний. Информация в этом разделе соответствует текущей версии Mini App.</div></div>'
    . '<div class="faqTopics"><span class="faqTopic">🎮 Игры</span><span class="faqTopic">⚔️ Комнаты</span><span class="faqTopic">🪙 Балансы</span><span class="faqTopic">🎁 Магазин</span><span class="faqTopic">📊 История</span><span class="faqTopic">💬 Поддержка</span></div>'
    . '<div class="faqProGrid">' . $cards . '</div>'
    . '<div class="faqProFooter"><div class="faqProFooterText"><b>Остался вопрос по игре или операции?</b><span>Откройте Mini App и отправьте обращение через обратную связь, раздел идей или поддержку.</span></div>'
    . '<a class="faqProFooterLink" href="https://t.me/MiniGamesWorld_bot" target="_blank" rel="noopener noreferrer">Открыть Telegram-бота</a></div>'
    . '</div></section>';

$updatedHtml = preg_replace('~<section\b[^>]*\bid="faq"[^>]*>.*?</section>~s', $faqSection, $html, 1, $faqCount);
if ($updatedHtml === null || $faqCount !== 1) {
    // Never take down the whole homepage because of a content enhancement.
    $updatedHtml = $html;
}
$html = $updatedHtml;

$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => $questions,
];
$schemaJson = json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$newSchema = '<script type="application/ld+json" id="faq-current-schema">' . $schemaJson . '</script>';

// Remove every legacy FAQPage script, regardless of how many older build layers contain one.
$html = preg_replace_callback(
    '~<script\s+type="application/ld\+json"[^>]*>.*?</script>~s',
    static fn(array $match): string => strpos($match[0], 'FAQPage') !== false ? '' : $match[0],
    $html
) ?? $html;
$html = str_replace('</head>', $newSchema . '</head>', $html);

header('Content-Type: text/html; charset=UTF-8');
header('Content-Language: ru');
echo $html;
