<?php
ob_start();
require __DIR__ . '/landing-v6.php';
$html = ob_get_clean();

$fixCss = <<<'CSS'
<style id="mobile-menu-height-fix">
/* Targeted mobile navigation fix */
@media (max-width:1120px){
  .siteHeader{
    -webkit-backdrop-filter:none!important;
    backdrop-filter:none!important;
    background:rgba(8,11,21,.97)!important;
  }
  .mobileMenu{
    position:fixed!important;
    top:var(--header-height)!important;
    right:0!important;
    bottom:auto!important;
    left:0!important;
    width:100%!important;
    height:calc(100dvh - var(--header-height))!important;
    min-height:calc(100vh - var(--header-height))!important;
    max-height:calc(100dvh - var(--header-height))!important;
    overflow-x:hidden!important;
    overflow-y:auto!important;
    overscroll-behavior:contain;
    -webkit-overflow-scrolling:touch;
    padding-bottom:calc(28px + env(safe-area-inset-bottom))!important;
  }
  .mobileMenuPanel{
    min-height:max-content;
  }
}

/* Targeted hero H1 refinement */
.hero h1{
  font-size:clamp(46px,5.55vw,78px)!important;
  letter-spacing:-.02em!important;
}
@media (max-width:700px){
  .hero h1{
    font-size:clamp(40px,12vw,58px)!important;
  }
}
</style>
CSS;

if (strpos($html, 'mobile-menu-height-fix') === false) {
    $html = str_replace('</head>', $fixCss . '</head>', $html);
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
