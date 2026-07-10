<?php
ob_start();
require __DIR__ . '/landing-v5.php';
$html = ob_get_clean();

$headerCss = <<<'CSS'
<style id="main-header-v6-styles">
:root{--header-height:78px}
html{scroll-padding-top:calc(var(--header-height) + 18px)}
body.menu-open{overflow:hidden}
.siteHeader{position:sticky;top:0;z-index:200;border-bottom:1px solid rgba(255,255,255,.08);background:rgba(8,11,21,.78);backdrop-filter:blur(22px) saturate(145%);-webkit-backdrop-filter:blur(22px) saturate(145%);transition:background .25s ease,border-color .25s ease,box-shadow .25s ease}
.siteHeader.is-scrolled{background:rgba(8,11,21,.94);border-color:rgba(255,255,255,.11);box-shadow:0 14px 42px rgba(0,0,0,.24)}
.siteHeaderInner{width:min(1380px,calc(100% - 48px));min-height:var(--header-height);margin:auto;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:26px}
.siteBrand{display:inline-flex;align-items:center;gap:12px;min-width:max-content;text-decoration:none}
.siteBrandMark{position:relative;width:44px;height:44px;display:grid;place-items:center;border-radius:15px;background:linear-gradient(135deg,var(--purple),var(--pink) 58%,var(--orange));box-shadow:0 10px 28px rgba(177,76,255,.22);font-weight:950;letter-spacing:-.05em;color:#fff}
.siteBrandMark:after{content:"";position:absolute;inset:1px;border-radius:14px;border:1px solid rgba(255,255,255,.2);pointer-events:none}
.siteBrandText{display:grid;gap:2px}.siteBrandText strong{font-size:15px;line-height:1.1}.siteBrandText small{color:#8f99ad;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.siteNav{display:flex;align-items:center;justify-content:center;gap:6px}
.siteNavLink{position:relative;display:inline-flex;align-items:center;min-height:42px;padding:0 14px;border-radius:12px;color:#aeb7ca;font-size:13px;font-weight:850;transition:color .22s ease,background .22s ease}
.siteNavLink:hover,.siteNavLink:focus-visible{color:#fff;background:rgba(255,255,255,.055);outline:none}
.siteNavLink:after{content:"";position:absolute;left:14px;right:14px;bottom:6px;height:2px;border-radius:99px;background:linear-gradient(90deg,var(--orange),var(--pink),var(--violet));transform:scaleX(0);transform-origin:center;transition:transform .22s ease}
.siteNavLink:hover:after,.siteNavLink:focus-visible:after{transform:scaleX(1)}
.siteHeaderActions{display:flex;align-items:center;gap:10px}
.siteLang{display:flex;padding:4px;border:1px solid rgba(255,255,255,.09);border-radius:13px;background:rgba(255,255,255,.025)}
.siteLang button{min-width:38px;min-height:34px;padding:0 10px;border:0;border-radius:9px;background:transparent;color:#858fa5;font-size:11px;font-weight:950;cursor:pointer;transition:.2s}
.siteLang button:hover{color:#fff}.siteLang button.active{color:#fff;background:linear-gradient(135deg,var(--pink),var(--violet));box-shadow:0 7px 18px rgba(177,76,255,.2)}
.sitePlay{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:13px;background:linear-gradient(100deg,var(--orange),var(--pink) 68%,var(--violet));color:#fff;font-size:13px;font-weight:950;box-shadow:0 12px 28px rgba(242,45,134,.16);transition:transform .22s ease,filter .22s ease,box-shadow .22s ease}
.sitePlay:hover,.sitePlay:focus-visible{transform:translateY(-2px);filter:brightness(1.08);box-shadow:0 16px 36px rgba(242,45,134,.24);outline:none}
.menuButton{display:none;width:44px;height:44px;padding:0;border:1px solid rgba(255,255,255,.1);border-radius:13px;background:rgba(255,255,255,.035);cursor:pointer;place-items:center}
.menuButtonLines,.menuButtonLines:before,.menuButtonLines:after{width:18px;height:2px;border-radius:99px;background:#fff;transition:.25s}.menuButtonLines{position:relative}.menuButtonLines:before,.menuButtonLines:after{content:"";position:absolute;left:0}.menuButtonLines:before{top:-6px}.menuButtonLines:after{top:6px}.menuButton[aria-expanded="true"] .menuButtonLines{background:transparent}.menuButton[aria-expanded="true"] .menuButtonLines:before{top:0;transform:rotate(45deg)}.menuButton[aria-expanded="true"] .menuButtonLines:after{top:0;transform:rotate(-45deg)}
.mobileMenu{display:none}
.skipLink{position:fixed;left:14px;top:10px;z-index:9999;padding:10px 14px;border-radius:10px;background:#fff;color:#080b15;font-weight:900;transform:translateY(-150%);transition:.2s}.skipLink:focus{transform:translateY(0)}
@media(max-width:1120px){.siteHeaderInner{grid-template-columns:auto auto;justify-content:space-between}.siteNav,.siteHeaderActions .siteLang,.siteHeaderActions .sitePlay{display:none}.menuButton{display:grid}.mobileMenu{position:fixed;inset:var(--header-height) 0 0;display:block;padding:16px 24px 28px;background:rgba(8,11,21,.985);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px);opacity:0;visibility:hidden;transform:translateY(-10px);transition:opacity .24s ease,visibility .24s ease,transform .24s ease;overflow-y:auto}.siteHeader.menu-open .mobileMenu{opacity:1;visibility:visible;transform:none}.mobileMenuPanel{width:min(680px,100%);margin:auto;padding:18px;border:1px solid rgba(255,255,255,.09);border-radius:24px;background:linear-gradient(145deg,rgba(255,255,255,.055),rgba(255,255,255,.018))}.mobileMenuNav{display:grid;gap:6px}.mobileMenuNav a{display:flex;align-items:center;justify-content:space-between;min-height:52px;padding:0 15px;border-radius:14px;color:#e7ebf3;font-weight:900;background:rgba(255,255,255,.025)}.mobileMenuNav a:after{content:"→";color:#7e899f}.mobileMenuActions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.mobileLang{display:flex;padding:4px;border:1px solid rgba(255,255,255,.09);border-radius:14px;background:rgba(255,255,255,.025)}.mobileLang button{flex:1;min-height:42px;border:0;border-radius:10px;background:transparent;color:#8f98ac;font-weight:950}.mobileLang button.active{color:#fff;background:linear-gradient(135deg,var(--pink),var(--violet))}.mobilePlay{display:flex;align-items:center;justify-content:center;border-radius:14px;background:linear-gradient(100deg,var(--orange),var(--pink),var(--violet));font-weight:950}.mobileMenuNote{margin:14px 2px 0;color:#7f899d;font-size:12px;line-height:1.55}}
@media(max-width:640px){:root{--header-height:68px}.siteHeaderInner{width:min(100% - 28px,1380px)}.siteBrandMark{width:40px;height:40px;border-radius:13px}.siteBrandText strong{font-size:14px}.siteBrandText small{font-size:9px}.mobileMenu{padding:14px 14px 24px}.mobileMenuPanel{padding:14px;border-radius:20px}.mobileMenuActions{grid-template-columns:1fr}.mobilePlay{min-height:48px}.siteHeader.is-scrolled{box-shadow:0 10px 30px rgba(0,0,0,.22)}}
@media(prefers-reduced-motion:reduce){.siteHeader,.siteNavLink,.siteNavLink:after,.sitePlay,.menuButtonLines,.menuButtonLines:before,.menuButtonLines:after,.mobileMenu{transition:none!important}}
</style>
CSS;

$headerHtml = <<<'HTML'
<a class="skipLink" href="#main-content"><span class="i18n" data-lang="ru">Перейти к содержанию</span><span class="i18n" data-lang="en">Skip to content</span></a>
<header class="siteHeader" id="site-header">
  <div class="siteHeaderInner">
    <a class="siteBrand" href="/" aria-label="Mini Games World — главная">
      <span class="siteBrandMark" aria-hidden="true">MG</span>
      <span class="siteBrandText"><strong>Mini Games World</strong><small>Telegram Mini App</small></span>
    </a>

    <nav class="siteNav" aria-label="Основная навигация">
      <a class="siteNavLink" href="#games"><span class="i18n" data-lang="ru">Игры</span><span class="i18n" data-lang="en">Games</span></a>
      <a class="siteNavLink" href="#rooms"><span class="i18n" data-lang="ru">Комнаты</span><span class="i18n" data-lang="en">Rooms</span></a>
      <a class="siteNavLink" href="#how"><span class="i18n" data-lang="ru">Как начать</span><span class="i18n" data-lang="en">How to start</span></a>
      <a class="siteNavLink" href="#roadmap"><span class="i18n" data-lang="ru">Наши планы</span><span class="i18n" data-lang="en">Roadmap</span></a>
      <a class="siteNavLink" href="#faq">FAQ</a>
      <a class="siteNavLink" href="/blog/"><span class="i18n" data-lang="ru">Блог</span><span class="i18n" data-lang="en">Blog</span></a>
    </nav>

    <div class="siteHeaderActions">
      <div class="siteLang" role="group" aria-label="Language switcher">
        <button class="active" type="button" data-lang-btn="ru" aria-label="Русский язык">RU</button>
        <button type="button" data-lang-btn="en" aria-label="English language">EN</button>
      </div>
      <a class="sitePlay" href="https://t.me/MiniGamesWorld_bot" target="_blank" rel="noopener noreferrer"><span class="i18n" data-lang="ru">Играть</span><span class="i18n" data-lang="en">Play now</span></a>
      <button class="menuButton" type="button" aria-controls="mobile-menu" aria-expanded="false" aria-label="Открыть меню"><span class="menuButtonLines" aria-hidden="true"></span></button>
    </div>
  </div>

  <div class="mobileMenu" id="mobile-menu" aria-hidden="true">
    <div class="mobileMenuPanel">
      <nav class="mobileMenuNav" aria-label="Мобильная навигация">
        <a href="#games"><span class="i18n" data-lang="ru">Игры</span><span class="i18n" data-lang="en">Games</span></a>
        <a href="#rooms"><span class="i18n" data-lang="ru">Игровые комнаты</span><span class="i18n" data-lang="en">Game rooms</span></a>
        <a href="#how"><span class="i18n" data-lang="ru">Как начать играть</span><span class="i18n" data-lang="en">How to start playing</span></a>
        <a href="#roadmap"><span class="i18n" data-lang="ru">Наши планы</span><span class="i18n" data-lang="en">Roadmap</span></a>
        <a href="#faq"><span class="i18n" data-lang="ru">Вопросы и ответы</span><span class="i18n" data-lang="en">Questions and answers</span></a>
        <a href="/blog/"><span class="i18n" data-lang="ru">Блог и руководства</span><span class="i18n" data-lang="en">Blog and guides</span></a>
      </nav>
      <div class="mobileMenuActions">
        <div class="mobileLang" role="group" aria-label="Language switcher">
          <button class="active" type="button" data-lang-btn="ru">RU</button>
          <button type="button" data-lang-btn="en">EN</button>
        </div>
        <a class="mobilePlay" href="https://t.me/MiniGamesWorld_bot" target="_blank" rel="noopener noreferrer"><span class="i18n" data-lang="ru">Запустить в Telegram</span><span class="i18n" data-lang="en">Launch in Telegram</span></a>
      </div>
      <p class="mobileMenuNote"><span class="i18n" data-lang="ru">Мини-игры, быстрые матчи и приглашения друзей прямо внутри Telegram.</span><span class="i18n" data-lang="en">Mini-games, quick matches and friend invitations directly inside Telegram.</span></p>
    </div>
  </div>
</header>
HTML;

$headerScript = <<<'HTML'
<script id="main-header-v6-script">
(function(){
  'use strict';
  var header=document.getElementById('site-header');
  var button=header&&header.querySelector('.menuButton');
  var menu=document.getElementById('mobile-menu');
  if(!header||!button||!menu)return;

  function closeMenu(){
    header.classList.remove('menu-open');
    document.body.classList.remove('menu-open');
    button.setAttribute('aria-expanded','false');
    button.setAttribute('aria-label',document.documentElement.lang==='en'?'Open menu':'Открыть меню');
    menu.setAttribute('aria-hidden','true');
  }
  function openMenu(){
    header.classList.add('menu-open');
    document.body.classList.add('menu-open');
    button.setAttribute('aria-expanded','true');
    button.setAttribute('aria-label',document.documentElement.lang==='en'?'Close menu':'Закрыть меню');
    menu.setAttribute('aria-hidden','false');
  }
  button.addEventListener('click',function(){button.getAttribute('aria-expanded')==='true'?closeMenu():openMenu()});
  menu.querySelectorAll('a').forEach(function(link){link.addEventListener('click',closeMenu)});
  document.addEventListener('keydown',function(event){if(event.key==='Escape')closeMenu()});
  window.addEventListener('resize',function(){if(window.innerWidth>1120)closeMenu()});
  function updateHeader(){header.classList.toggle('is-scrolled',window.scrollY>8)}
  updateHeader();
  window.addEventListener('scroll',updateHeader,{passive:true});
})();
</script>
HTML;

$navSchema = <<<'HTML'
<script type="application/ld+json" id="site-navigation-schema">
{
  "@context":"https://schema.org",
  "@type":"ItemList",
  "name":"Mini Games World main navigation",
  "itemListElement":[
    {"@type":"SiteNavigationElement","position":1,"name":"Games","url":"https://lemonchiffon-gerbil-545102.hostingersite.com/#games"},
    {"@type":"SiteNavigationElement","position":2,"name":"Game rooms","url":"https://lemonchiffon-gerbil-545102.hostingersite.com/#rooms"},
    {"@type":"SiteNavigationElement","position":3,"name":"How to start","url":"https://lemonchiffon-gerbil-545102.hostingersite.com/#how"},
    {"@type":"SiteNavigationElement","position":4,"name":"Roadmap","url":"https://lemonchiffon-gerbil-545102.hostingersite.com/#roadmap"},
    {"@type":"SiteNavigationElement","position":5,"name":"FAQ","url":"https://lemonchiffon-gerbil-545102.hostingersite.com/#faq"},
    {"@type":"SiteNavigationElement","position":6,"name":"Blog","url":"https://lemonchiffon-gerbil-545102.hostingersite.com/blog/"}
  ]
}
</script>
HTML;

$html = str_replace('</head>', $headerCss . $navSchema . '</head>', $html);
$html = preg_replace('~<header class="nav">.*?</header>~s', $headerHtml, $html, 1, $count);
if ($html === null || $count !== 1) {
    http_response_code(500);
    echo 'Header could not be updated.';
    exit;
}
$html = preg_replace('~<main\b~', '<main id="main-content"', $html, 1);
$html = str_replace('</body>', $headerScript . '</body>', $html);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
