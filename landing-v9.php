<?php
ob_start();
require __DIR__ . '/landing-v8.php';
$html = ob_get_clean();

$gamesGlowFixCss = <<<'CSS'
<style id="games-showcase-glow-fix">
/* Replace the clipped rectangular glow in the Games section with a large soft ellipse. */
.gamesShowcase::before {
  inset: auto !important;
  top: -110px !important;
  right: -190px !important;
  width: min(1080px, 78vw) !important;
  height: 780px !important;
  border-radius: 50% !important;
  background: radial-gradient(
    ellipse at center,
    rgba(242, 45, 134, .14) 0%,
    rgba(242, 45, 134, .09) 30%,
    rgba(177, 76, 255, .045) 54%,
    transparent 76%
  ) !important;
  filter: blur(18px);
  transform: translateZ(0);
  pointer-events: none;
}

@media (max-width: 900px) {
  .gamesShowcase::before {
    top: -40px !important;
    right: -300px !important;
    width: 900px !important;
    height: 700px !important;
    opacity: .82;
  }
}
</style>
CSS;

if (strpos($html, 'games-showcase-glow-fix') === false) {
    $html = str_replace('</head>', $gamesGlowFixCss . '</head>', $html);
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
