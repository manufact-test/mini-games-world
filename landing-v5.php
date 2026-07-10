<?php
ob_start();
require __DIR__ . '/landing-v4.php';
$html = ob_get_clean();

$typographyScript = <<<'HTML'
<script id="main-page-typography">
(function () {
  'use strict';

  function applyTypography(text, lang) {
    if (!text || !text.trim()) return text;

    var result = text;

    if (lang === 'ru') {
      /* Короткие русские предлоги, союзы и частицы не оставляем в конце строки. */
      result = result.replace(
        /(^|[\s(\[«„“"'—–-])((?:а|и|но|да|или|либо|в|во|на|не|ни|с|со|к|ко|у|о|об|обо|от|до|за|из|изо|под|над|при|про|для|без|через|между|по|как|что|кто|чем|тем|это)) +(?=\S)/giu,
        '$1$2\u00A0'
      );

      /* Числа не отрываем от единиц, процентов и связанных обозначений. */
      result = result.replace(
        /(\d) +(?=(?:%|сек(?:унда|унды|унд)?|мин(?:ута|уты|ут)?|час(?:а|ов)?|коин(?:а|ов|ы)?|игр(?:а|ы)?|матч(?:а|ей)?|комнат(?:а|ы)?|размер(?:а|ов)?|×))/giu,
        '$1\u00A0'
      );
    }

    if (lang === 'en') {
      /* Короткие английские служебные слова связываем со следующим словом. */
      result = result.replace(
        /(^|[\s(\[“"'—–-])((?:a|an|the|and|or|but|in|on|at|to|of|for|by|with|from|as|vs)) +(?=\S)/giu,
        '$1$2\u00A0'
      );

      /* Числа не отделяем от единиц и коротких обозначений. */
      result = result.replace(
        /(\d) +(?=(?:%|sec(?:ond)?s?|min(?:ute)?s?|hours?|coins?|games?|matches?|rooms?|steps?|players?|boards?|×))/giu,
        '$1\u00A0'
      );
    }

    return result;
  }

  function processTypography() {
    var root = document.querySelector('main');
    if (!root) return;

    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (node) {
        var parent = node.parentElement;
        if (!parent || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
        if (parent.closest('script, style, noscript, pre, code, textarea')) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      }
    });

    var nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);

    nodes.forEach(function (node) {
      var languageContainer = node.parentElement.closest('[data-lang]');
      var lang = languageContainer ? languageContainer.getAttribute('data-lang') : document.documentElement.lang;
      if (lang !== 'ru' && lang !== 'en') return;
      node.nodeValue = applyTypography(node.nodeValue, lang);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', processTypography, { once: true });
  } else {
    processTypography();
  }
})();
</script>
HTML;

if (strpos($html, 'main-page-typography') === false) {
    $html = str_replace('</body>', $typographyScript . '</body>', $html);
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
