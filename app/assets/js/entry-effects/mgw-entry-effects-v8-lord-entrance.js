const STYLE_ID='mgw-entry-v8-lord-entrance-style';
const STYLE_HREF='/app/assets/css/entry-effects/mgw-entry-effects-v8-lord-entrance.css?v=3';
const ASSET_BASE='/app/assets/media/cosmetics/entry-effects/v8/';
const ASSETS={
  body:`${ASSET_BASE}entry-03-lord-entrance-body.webp?asset=lord-entrance-open-face-raster-v5`,
  sword:`${ASSET_BASE}entry-03-lord-entrance-sword.svg?asset=lord-entrance-v3`,
};
function ensureStyle(){
  const old=document.getElementById(STYLE_ID);
  if(old instanceof HTMLLinkElement){if(old.sheet)return Promise.resolve();return new Promise((r,j)=>{old.addEventListener('load',r,{once:true});old.addEventListener('error',j,{once:true});});}
  return new Promise((r,j)=>{const l=document.createElement('link');l.id=STYLE_ID;l.rel='stylesheet';l.href=STYLE_HREF;l.addEventListener('load',r,{once:true});l.addEventListener('error',j,{once:true});document.head.append(l);});
}
function preload(){Object.values(ASSETS).forEach(src=>{const i=new Image();i.decoding='async';i.src=src;});}
function image(cls,src){const i=document.createElement('img');i.className=cls;i.src=src;i.alt='';i.decoding='async';i.loading='eager';i.setAttribute('aria-hidden','true');return i;}
function sparks(parent,count=22){for(let n=0;n<count;n++){const i=document.createElement('i');i.className='mgw-entry-v8-le-spark';i.style.setProperty('--i',n);i.style.setProperty('--a',`${(n*47)%360}deg`);i.style.setProperty('--r',`${90+(n%7)*22}px`);parent.append(i);}}
function dust(parent,count=14){for(let n=0;n<count;n++){const i=document.createElement('i');i.className='mgw-entry-v8-le-dust';i.style.setProperty('--i',n);i.style.setProperty('--x',`${-180+(n*53)%360}px`);i.style.setProperty('--y',`${-120-(n%5)*34}px`);parent.append(i);}}
function mount(layer){
  if(!(layer instanceof HTMLElement)||!layer.classList.contains('mgw-entry-effect-layer'))return;
  const card=layer.querySelector('.mgw-entry-effect-live-card[data-entry-effect-variant="entry-03"]');
  if(!(card instanceof HTMLElement))return;
  const key=`entry-03:${String(card.dataset.playerIndex||'0')}`;
  if(layer.querySelector(`.mgw-entry-v8-lord-entrance[data-entry-v8-key="${key}"]`))return;
  const scene=document.createElement('div');scene.className='mgw-entry-v8-lord-entrance';scene.dataset.entryV8Key=key;scene.setAttribute('aria-hidden','true');
  const stage=document.createElement('div');stage.className='mgw-entry-v8-le-stage';
  for(const cls of ['mgw-entry-v8-le-veil','mgw-entry-v8-le-backglow','mgw-entry-v8-le-portal','mgw-entry-v8-le-floor']){const d=document.createElement('div');d.className=cls;stage.append(d);}
  const cloth=document.createElement('div');cloth.className='mgw-entry-v8-le-cloth';cloth.innerHTML='<i class="left"></i><i class="right"></i>';stage.append(cloth);
  const lord=document.createElement('div');lord.className='mgw-entry-v8-le-lord';
  lord.append(image('mgw-entry-v8-le-body',ASSETS.body));
  const cape=document.createElement('div');cape.className='mgw-entry-v8-le-cape';cape.innerHTML='<i class="left"></i><i class="right"></i>';lord.prepend(cape);
  stage.append(lord);
  const sword=document.createElement('div');sword.className='mgw-entry-v8-le-sword-wrap';sword.append(image('mgw-entry-v8-le-sword',ASSETS.sword));stage.append(sword);
  const slash=document.createElement('div');slash.className='mgw-entry-v8-le-slash';stage.append(slash);
  const flash=document.createElement('div');flash.className='mgw-entry-v8-le-flash';stage.append(flash);
  const shock=document.createElement('div');shock.className='mgw-entry-v8-le-shock';stage.append(shock);
  const sparkField=document.createElement('div');sparkField.className='mgw-entry-v8-le-sparks';sparks(sparkField);stage.append(sparkField);
  const dustField=document.createElement('div');dustField.className='mgw-entry-v8-le-dust-field';dust(dustField);stage.append(dustField);
  scene.append(stage);
  const grid=layer.querySelector('.mgw-entry-effect-live-grid');
  if(grid instanceof HTMLElement)layer.insertBefore(scene,grid);else layer.append(scene);
  layer.dataset.entryV8LordEntrance='1';
}
function scan(root=document){if(root instanceof HTMLElement&&root.classList.contains('mgw-entry-effect-layer'))mount(root);root.querySelectorAll?.('.mgw-entry-effect-layer').forEach(mount);}
function arm(){preload();scan(document);const o=new MutationObserver(rs=>{for(const r of rs)for(const n of r.addedNodes)if(n instanceof HTMLElement)scan(n);});o.observe(document.documentElement,{childList:true,subtree:true});}
ensureStyle().then(arm).catch(()=>{});
