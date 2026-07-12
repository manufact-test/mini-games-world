<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/landing-v17.php';
$html = (string) ob_get_clean();

/* Replace font-dependent emoji pairs with stable CSS-drawn game pieces. */
$fourPieces = '<span class="mgwStablePieces mgwStableFour" aria-hidden="true"><i></i><i></i></span>';
$checkersPieces = '<span class="mgwStablePieces mgwStableCheckers" aria-hidden="true"><i></i><i></i></span>';

$html = str_replace('🔴🟡', $fourPieces, $html);
$html = str_replace('⚪⚫', $checkersPieces, $html);

/* Some compact previews used a single emoji for these games. */
$html = str_replace(
    [
        '<span class="mgwHeroGameIcon">⚪</span>',
        '<span class="mgwHeroGamePick">⚪</span>',
        '<span title="Шашки">⚪</span>',
        '<span title="4 в ряд">🔴</span>',
    ],
    [
        '<span class="mgwHeroGameIcon">' . $checkersPieces . '</span>',
        '<span class="mgwHeroGamePick">' . $checkersPieces . '</span>',
        '<span title="Шашки">' . $checkersPieces . '</span>',
        '<span title="4 в ряд">' . $fourPieces . '</span>',
    ],
    $html
);

$iconCss = <<<'CSS'
<style id="stable-game-icons-v18">
.mgwStablePieces{
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  gap:.14em!important;
  width:1.48em!important;
  max-width:100%!important;
  min-width:0!important;
  height:1em!important;
  overflow:hidden!important;
  line-height:1!important;
  vertical-align:middle!important;
  white-space:nowrap!important;
}
.mgwStablePieces>i{
  display:block!important;
  flex:0 0 .59em!important;
  width:.59em!important;
  height:.59em!important;
  margin:0!important;
  padding:0!important;
  border-radius:50%!important;
}
.mgwStableFour>i:first-child{
  background:linear-gradient(145deg,#ff6b7d,#e52f50)!important;
  box-shadow:inset 0 1px 2px rgba(255,255,255,.42),0 2px 5px rgba(229,47,80,.22)!important;
}
.mgwStableFour>i:last-child{
  background:linear-gradient(145deg,#ffe58a,#f0b928)!important;
  box-shadow:inset 0 1px 2px rgba(255,255,255,.48),0 2px 5px rgba(240,185,40,.2)!important;
}
.mgwStableCheckers>i:first-child{
  background:linear-gradient(145deg,#faf8ff,#d7cff6)!important;
  box-shadow:inset 0 1px 2px rgba(255,255,255,.75),0 2px 5px rgba(160,145,220,.18)!important;
}
.mgwStableCheckers>i:last-child{
  background:linear-gradient(145deg,#625d73,#312f3b)!important;
  box-shadow:inset 0 1px 2px rgba(255,255,255,.17),0 2px 5px rgba(0,0,0,.25)!important;
}
.gameV12Icon,
.mgwHeroGameIcon,
.mgwHeroGamePick,
.historyGameV11>i,
.startV13Games>span{
  overflow:hidden!important;
}
.gameV12Icon .mgwStablePieces{font-size:27px!important}
.mgwHeroGameIcon .mgwStablePieces{font-size:16px!important}
.mgwHeroSelectedGame .mgwHeroGameIcon .mgwStablePieces{font-size:21px!important}
.historyGameV11>i .mgwStablePieces{font-size:18px!important}
.startV13Games>span .mgwStablePieces{font-size:14px!important}
</style>
CSS;
$html = str_replace('</head>', $iconCss . '</head>', $html);

/* The legacy slider script was removed together with the former language switcher.
   Remove any leftover copy and initialize a Russian-only independent slider. */
$html = preg_replace('~<script\s+id="hero-v10-script"[^>]*>.*?</script>~su', '', $html) ?? $html;

$sliderScript = <<<'JS'
<script id="hero-slider-v18">
(function(){
  'use strict';

  var root=document.querySelector('[data-mgw-hero-slider]');
  if(!root||root.getAttribute('data-slider-ready')==='true')return;

  var track=root.querySelector('.mgwHeroTrack');
  var slides=Array.prototype.slice.call(root.querySelectorAll('.mgwHeroSlide'));
  var dots=Array.prototype.slice.call(root.querySelectorAll('[data-mgw-dot]'));
  var previous=root.querySelector('[data-mgw-prev]');
  var next=root.querySelector('[data-mgw-next]');
  var number=root.querySelector('[data-mgw-caption-number]');
  var caption=root.querySelector('[data-mgw-caption-text]');

  if(!track||slides.length<1)return;
  root.setAttribute('data-slider-ready','true');

  var current=0;
  var timer=null;
  var pointerStart=null;
  var reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function updateCaption(slide){
    if(!caption)return;
    caption.textContent=slide.getAttribute('data-slide-title-ru')||'';
  }

  function render(index,fromUser){
    current=(index+slides.length)%slides.length;
    track.style.transform='translate3d(-'+(current*100)+'%,0,0)';

    slides.forEach(function(slide,slideIndex){
      slide.setAttribute('aria-hidden',slideIndex===current?'false':'true');
    });

    dots.forEach(function(dot,dotIndex){
      var active=dotIndex===current;
      dot.classList.toggle('active',active);
      dot.setAttribute('aria-selected',active?'true':'false');
      dot.setAttribute('tabindex',active?'0':'-1');
    });

    if(number){
      number.textContent=String(current+1).padStart(2,'0')+' / '+String(slides.length).padStart(2,'0');
    }
    updateCaption(slides[current]);

    if(fromUser)restart();
  }

  function stop(){
    if(timer){window.clearInterval(timer);timer=null;}
  }

  function start(){
    if(reduced||slides.length<2||timer||document.hidden)return;
    timer=window.setInterval(function(){render(current+1,false);},6200);
  }

  function restart(){stop();start();}

  if(previous){previous.addEventListener('click',function(){render(current-1,true);});}
  if(next){next.addEventListener('click',function(){render(current+1,true);});}

  dots.forEach(function(dot){
    dot.addEventListener('click',function(){
      render(Number(dot.getAttribute('data-mgw-dot')||0),true);
    });
  });

  root.addEventListener('mouseenter',stop);
  root.addEventListener('mouseleave',start);
  root.addEventListener('focusin',stop);
  root.addEventListener('focusout',function(event){
    if(!root.contains(event.relatedTarget))start();
  });
  root.addEventListener('keydown',function(event){
    if(event.key==='ArrowLeft'){event.preventDefault();render(current-1,true);}
    if(event.key==='ArrowRight'){event.preventDefault();render(current+1,true);}
  });

  root.addEventListener('pointerdown',function(event){
    if(event.pointerType==='mouse'&&event.button!==0)return;
    pointerStart={id:event.pointerId,x:event.clientX,y:event.clientY};
  });
  root.addEventListener('pointerup',function(event){
    if(!pointerStart||pointerStart.id!==event.pointerId)return;
    var deltaX=event.clientX-pointerStart.x;
    var deltaY=event.clientY-pointerStart.y;
    pointerStart=null;
    if(Math.abs(deltaX)>52&&Math.abs(deltaX)>Math.abs(deltaY)){
      render(current+(deltaX<0?1:-1),true);
    }
  });
  root.addEventListener('pointercancel',function(){pointerStart=null;});

  document.addEventListener('visibilitychange',function(){
    if(document.hidden)stop();else start();
  });

  render(0,false);
  start();
})();
</script>
JS;
$html = str_replace('</body>', $sliderScript . '</body>', $html);

header('Content-Type: text/html; charset=UTF-8');
header('Content-Language: ru');
echo $html;
