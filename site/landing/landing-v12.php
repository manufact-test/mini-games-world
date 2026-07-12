<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/landing-v11.php';
$html = (string) ob_get_clean();

$finalPalette = <<<'CSS'
<style id="landing-v12-final-palette">
.proFooter{background:#0a0e19!important}.proFooter:before{display:none!important}.proFooterLogoMark,.proFooterButton,.cookieBtn.primary{background:#7c5cff!important;box-shadow:0 12px 30px rgba(124,92,255,.2)!important}.proFooterButton:hover,.proFooterButton:focus-visible,.cookieBtn.primary:hover,.cookieBtn.primary:focus-visible{background:#6548e6!important}.cookieEmoji{background:rgba(124,92,255,.1)!important;border-color:rgba(124,92,255,.2)!important}.cookieText a{color:#c8c0ff!important}.proFooterStatus{background:rgba(46,230,166,.06)!important;border-color:rgba(46,230,166,.17)!important}.proFooterCol h3{color:#f7f8fb!important}
</style>
CSS;

$html = str_replace('</head>', $finalPalette . '</head>', $html);
header('Content-Type: text/html; charset=UTF-8');
header('Content-Language: ru');
echo $html;
