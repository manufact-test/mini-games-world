<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/landing-v16.php';
$html = (string) ob_get_clean();

/* 1. Updated and more compact hero heading. */
$heroTitle = '<h1 id="hero-v10-title">Заходи, играй, побеждай.<br><span class="heroV10TitleAccent">Быстрые онлайн-матчи без регистрации.</span></h1>';
$html = preg_replace('~<h1\s+id="hero-v10-title"[^>]*>.*?</h1>~su', $heroTitle, $html, 1) ?? $html;

/* 2. Remove decorative symbols from the two hero buttons. */
$html = preg_replace('~<span\s+class="heroV10PrimaryIcon"[^>]*>.*?</span>~su', '', $html, 1) ?? $html;
$html = preg_replace(
    '~(<a\s+class="heroV10Secondary"[^>]*>.*?)<span\s+aria-hidden="true">.*?</span>(\s*</a>)~su',
    '$1$2',
    $html,
    1
) ?? $html;

/* 3. Remove redundant catalogue summary, card capability rows and footer note. */
$html = preg_replace(
    '~<div\s+class="v12Summary"><strong>5</strong>.*?</div>(?=</div><div\s+class="gamesV12Grid">)~su',
    '',
    $html,
    1
) ?? $html;
$html = preg_replace(
    '~<div\s+class="gameV12Capabilities">.*?</div></article>~su',
    '</article>',
    $html
) ?? $html;
$html = preg_replace(
    '~<div\s+class="gamesV12Foot">.*?</div>(?=</div></section>)~su',
    '',
    $html,
    1
) ?? $html;

/* 4. Simplify the start section and replace placeholder glyphs with proper SVG icons. */
$html = preg_replace(
    '~<aside><strong>5 игр</strong><span>.*?</span></aside>~su',
    '',
    $html,
    1
) ?? $html;

$telegramIcon = <<<'HTML'
<i class="startV13Icon" aria-hidden="true"><svg viewBox="0 0 24 24" role="img" focusable="false"><path d="M21.35 3.23 18.2 19.02c-.24 1.12-.87 1.39-1.77.87l-4.8-3.54-2.32 2.23c-.26.26-.47.47-.97.47l.35-4.88 8.88-8.03c.39-.35-.08-.55-.6-.2L5.99 12.86l-4.73-1.48c-1.03-.32-1.05-1.03.21-1.52L19.96 2.73c.86-.31 1.61.2 1.39.5Z" fill="currentColor"/></svg></i>
HTML;
$appIcon = <<<'HTML'
<i class="startV13Icon" aria-hidden="true"><svg viewBox="0 0 24 24" role="img" focusable="false"><rect x="4" y="3" width="16" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 7.5h3v3H8zm5 0h3v3h-3zm-5 5h3v3H8zm5 0h3v3h-3z" fill="currentColor"/><path d="M9.5 18h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></i>
HTML;
$html = preg_replace('~<i\s+class="startV13Icon"[^>]*>✈</i>~u', $telegramIcon, $html, 1) ?? $html;
$html = preg_replace('~<i\s+class="startV13Icon"[^>]*>▣</i>~u', $appIcon, $html, 1) ?? $html;

/* 5. Remove redundant roadmap copy and labels while preserving its animation. */
$html = preg_replace(
    '~<aside><strong>11 готово</strong><span>.*?</span></aside>~su',
    '',
    $html,
    1
) ?? $html;
$html = str_replace(
    '<p>Roadmap больше не рассказывает историю разработки. Он показывает текущую продуктовую основу и направления следующего этапа без неподтверждённых дат запуска.</p>',
    '',
    $html
);
$html = str_replace(
    [
        '<small>Текущая версия</small>',
        '<small>Без объявленных сроков</small>',
        '<b>В развитии</b>',
        '<b>В разработке</b>',
    ],
    '',
    $html
);

/* 6. Final visual refinements and unclipped decorative background glows. */
$css = <<<'CSS'
<style id="homepage-point-fixes-v17">
/* New H1 remains prominent, but no longer dominates the whole first screen. */
.heroV10 h1{
  max-width:960px!important;
  font-size:clamp(38px,4.1vw,62px)!important;
  line-height:1.06!important;
  letter-spacing:-.047em!important;
}
.heroV10Primary,.heroV10Secondary{gap:0!important}

/* Catalogue after removing repeated service labels. */
.catalogV12 .v12SectionHead{grid-template-columns:minmax(0,1fr)!important}
.catalogV12 .v12SectionCopy{max-width:900px!important}
.catalogV12 .gameV12{min-height:0!important}
.catalogV12 .gameV12Board{margin-top:auto!important}

/* One-column headings after removing small statistic cards. */
.startV13 .v13Head,.roadmapV13 .v13Head{grid-template-columns:minmax(0,1fr)!important}
.startV13 .v13Head>div,.roadmapV13 .v13Head>div{max-width:940px!important}
.startV13Icon svg{display:block;width:21px;height:21px}
.startV13Step:nth-of-type(2) .startV13Icon{color:#d9d4ff}
.roadmapV13 .v13Head h2{margin-bottom:0!important}
.roadmapV13Phase header small:empty{display:none!important}
.roadmapV13Phase header div div h3{margin-top:0!important}

/* Let large decorative glows continue naturally between adjacent sections. */
.heroV10,
.catalogV12,
.roomsV12,
.prizeV11,
.featuresV11,
.startV13,
.roadmapV13,
.faqProSection{
  overflow:visible!important;
}
body{overflow-x:hidden!important}

@media(max-width:1120px){
  .heroV10 h1{font-size:clamp(38px,5vw,56px)!important}
}
@media(max-width:640px){
  .heroV10 h1{font-size:clamp(33px,9.6vw,45px)!important;line-height:1.08!important}
}
</style>
CSS;
$html = str_replace('</head>', $css . '</head>', $html);

header('Content-Type: text/html; charset=UTF-8');
header('Content-Language: ru');
echo $html;
