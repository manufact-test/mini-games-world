<?php
ob_start();
require __DIR__ . '/landing-v7.php';
$html = ob_get_clean();

$backgroundFixCss = <<<'CSS'
<style id="section-background-edge-fix">
/* Let decorative radial glows fade naturally beyond section bounds. */
.gamesShowcase,
.experienceSection,
.quickStartSection {
  overflow: visible !important;
}

/* Keep the page protected from horizontal overflow created by the larger glows. */
html,
body {
  overflow-x: clip;
}

@supports not (overflow: clip) {
  html,
  body {
    overflow-x: hidden;
  }
}
</style>
CSS;

if (strpos($html, 'section-background-edge-fix') === false) {
    $html = str_replace('</head>', $backgroundFixCss . '</head>', $html);
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
