<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/landing-v13.php';
$html = (string) ob_get_clean();

$enhancement = <<<'HTML'
<script id="v13-motion-class">document.documentElement.classList.add('v13-motion');</script>
<style id="v13-progressive-enhancement">
.startV13Line i{width:100%}
.startV13Step{opacity:1;transform:none}
.roadmapV13ProgressDone:before{width:100%}
.roadmapV13ProgressDone i{left:62%;opacity:1}
.roadmapV13ReadyGrid span,.roadmapV13NextList li{opacity:1;transform:none}
html.v13-motion .startV13Line i{width:0}
html.v13-motion .startV13Flow.is-visible .startV13Line i{width:100%}
html.v13-motion .startV13Step{opacity:0;transform:translateY(24px)}
html.v13-motion .startV13Flow.is-visible .startV13Step{opacity:1;transform:none}
html.v13-motion .roadmapV13ProgressDone:before{width:0}
html.v13-motion .roadmapV13.is-visible .roadmapV13ProgressDone:before{width:100%}
html.v13-motion .roadmapV13ProgressDone i{left:0;opacity:0}
html.v13-motion .roadmapV13ReadyGrid span{opacity:0;transform:translateY(10px)}
html.v13-motion .roadmapV13NextList li{opacity:0;transform:translateX(14px)}
@media(prefers-reduced-motion:reduce){html.v13-motion .startV13Step,html.v13-motion .roadmapV13ReadyGrid span,html.v13-motion .roadmapV13NextList li{opacity:1!important;transform:none!important}html.v13-motion .startV13Line i,html.v13-motion .roadmapV13ProgressDone:before{width:100%!important}html.v13-motion .roadmapV13ProgressDone i{left:62%!important;opacity:1!important}}
</style>
HTML;

$html = str_replace('</head>', $enhancement . '</head>', $html);
header('Content-Type: text/html; charset=UTF-8');
header('Content-Language: ru');
echo $html;
