import { test, expect } from '@playwright/test';

const MODULE='/app/assets/js/entry-effects/mgw-entry-effects-v8-lord-entrance.js?v=1';

async function mountFixture(page,variant='entry-03'){
  await page.goto('/app/index.html',{waitUntil:'domcontentloaded'});
  await page.setContent(`<!doctype html><html><head></head><body style="margin:0;background:#050508"><div class="mgw-entry-effect-layer" id="fixture" style="position:fixed;inset:0;overflow:hidden;background:#050508"><button class="mgw-entry-effect-skip" id="skip" type="button">Пропустить</button><img class="mgw-entry-effect-live-art" data-entry-effect-variant="${variant}" alt="" src="/app/assets/media/cosmetics/entry-effects/entry-effect-03-knight-strike.webp?asset=premium-art-v7"><div class="mgw-entry-effect-live-fx" data-entry-effect-variant="${variant}"></div><div class="mgw-entry-effect-live-grid"><div class="mgw-entry-effect-live-card" data-entry-effect-variant="${variant}" data-player-index="0"><strong>Илья</strong><small>вступает в игру</small></div></div></div></body></html>`,{waitUntil:'load'});
  await page.evaluate(async p=>{await import(p);},MODULE);
}

test('Entry 03 Lord Entrance mounts premium portal, open-face lord, actual sword motion and slash layers',async({page},testInfo)=>{
  await mountFixture(page,'entry-03');
  await page.waitForSelector('.mgw-entry-v8-lord-entrance');
  await page.waitForFunction(()=>document.getElementById('mgw-entry-v8-lord-entrance-style')?.sheet,null,{timeout:10000});
  await page.waitForFunction(()=>{const body=document.querySelector('.mgw-entry-v8-le-body');const sword=document.querySelector('.mgw-entry-v8-le-sword');return body instanceof HTMLImageElement&&sword instanceof HTMLImageElement&&body.complete&&sword.complete&&body.naturalWidth>0&&sword.naturalWidth>0;});
  await expect(page.locator('#fixture')).toHaveAttribute('data-entry-v8-lord-entrance','1');
  for(const selector of ['.mgw-entry-v8-le-portal','.mgw-entry-v8-le-lord','.mgw-entry-v8-le-body','.mgw-entry-v8-le-sword-wrap','.mgw-entry-v8-le-slash','.mgw-entry-v8-le-shock','.mgw-entry-v8-le-cloth','.mgw-entry-v8-le-cape']) await expect(page.locator(selector)).toHaveCount(1);
  expect(await page.locator('.mgw-entry-v8-le-spark').count()).toBeGreaterThanOrEqual(20);
  const state=await page.evaluate(()=>({
    oldArt:getComputedStyle(document.querySelector('.mgw-entry-effect-live-art[data-entry-effect-variant="entry-03"]')).display,
    oldFx:getComputedStyle(document.querySelector('.mgw-entry-effect-live-fx[data-entry-effect-variant="entry-03"]')).display,
    lord:getComputedStyle(document.querySelector('.mgw-entry-v8-le-lord')).animationName,
    sword:getComputedStyle(document.querySelector('.mgw-entry-v8-le-sword-wrap')).animationName,
    slash:getComputedStyle(document.querySelector('.mgw-entry-v8-le-slash')).animationName,
    stage:getComputedStyle(document.querySelector('.mgw-entry-v8-le-stage')).animationName,
    skip:document.getElementById('skip')?.textContent,
  }));
  expect(state.oldArt).toBe('none');expect(state.oldFx).toBe('none');
  expect(state.lord).toContain('mgwLeLord');expect(state.sword).toContain('mgwLeSword');expect(state.slash).toContain('mgwLeSlash');expect(state.stage).toContain('mgwLeStage');expect(state.skip).toBe('Пропустить');
  await page.waitForTimeout(1900);
  const p=testInfo.outputPath('entry-03-lord-entrance-slash.png');await page.screenshot({path:p,fullPage:true});await testInfo.attach('entry-03-lord-entrance-slash.png',{path:p,contentType:'image/png'});
});

test('Lord Entrance does not mount on Entry 01 or Entry 02',async({page})=>{for(const v of ['entry-01','entry-02']){await mountFixture(page,v);await page.waitForTimeout(250);await expect(page.locator('.mgw-entry-v8-lord-entrance')).toHaveCount(0);}});

test('Lord Entrance reduced motion keeps coherent lord and portal but removes slash/shake',async({browser})=>{
  const context=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});try{const page=await context.newPage();await mountFixture(page,'entry-03');await page.waitForSelector('.mgw-entry-v8-lord-entrance');const s=await page.evaluate(()=>({lord:getComputedStyle(document.querySelector('.mgw-entry-v8-le-lord')).opacity,portal:getComputedStyle(document.querySelector('.mgw-entry-v8-le-portal')).opacity,stageAnim:getComputedStyle(document.querySelector('.mgw-entry-v8-le-stage')).animationName,slash:getComputedStyle(document.querySelector('.mgw-entry-v8-le-slash')).display}));expect(s.lord).toBe('1');expect(Number(s.portal)).toBeGreaterThan(0);expect(s.stageAnim).toBe('none');expect(s.slash).toBe('none');}finally{await context.close();}
});
