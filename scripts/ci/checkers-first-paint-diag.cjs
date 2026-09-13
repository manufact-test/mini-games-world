const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless:true });
  const page = await browser.newPage({ viewport:{ width:390, height:844 }, reducedMotion:'no-preference' });
  page.on('console', msg => console.log('[browser]', msg.type(), msg.text()));
  page.on('response', response => {
    const url = response.url();
    if (url.includes('runtime-handoff-mobile-v1.css')) {
      console.log('[css-response]', response.status(), response.headers()['content-type'] || '', url);
    }
  });
  await page.goto(process.env.ORIGIN + '/app/v110.php?v=1127&diag=checkers-first-paint', { waitUntil:'domcontentloaded', timeout:60000 });
  await page.waitForTimeout(1000);

  const result = await page.evaluate(async () => {
    const mod = await import('/app/assets/js/checkers-cosmetics/renderer-single-flight-v1.js?v=1&mvp19_6=single-flight-dom-v1&diag=' + Date.now());
    const host = document.createElement('div');
    host.id = 'mgw-checkers-diag-host';
    host.style.cssText = 'position:fixed;left:0;top:0;width:390px;height:844px;z-index:99999;background:#0a0e18;padding:16px;box-sizing:border-box;';
    document.body.appendChild(host);
    const container = document.createElement('div');
    container.id = 'mgw-checkers-diag-board';
    host.appendChild(container);

    const board = Array(64).fill('');
    board[40] = 'w';
    board[17] = 'b';
    const p1 = { id:'p1', name:'A', side:'white', game_cosmetics:{ slots:{ game_checkers_effect:'game-checkers-effect-move', game_checkers_elements:'game-checkers-pieces-neon' } } };
    const p2 = { id:'p2', name:'B', side:'black', game_cosmetics:{ slots:{} } };
    const base = {
      id:'diag-checkers-first-paint', status:'active', turn:'p1', viewer_side:'white',
      board, players:[p1,p2], legal_moves:[{ from:40, to:33, capture:false }],
      capture_required:false, forced_piece:null, pending_captures:[], last_captured_cells:[], last_promotion:null,
      white_pieces:1, black_pieces:1, white_kings:0, black_kings:0, move_timeout_sec:60,
    };
    let active = structuredClone(base);
    const me = { id:'p1' };
    let receivedAction = null;
    const onAction = action => {
      receivedAction = structuredClone(action);
      const optimistic = structuredClone(active);
      optimistic.board[action.to] = optimistic.board[action.from];
      optimistic.board[action.from] = '';
      optimistic.last_move = { from:action.from, to:action.to, capture:false, captured:null, player_id:'p1', promoted:false, chain_continues:false };
      optimistic.last_promotion = null;
      optimistic.turn = 'p2';
      optimistic.legal_moves = [];
      optimistic.__mgw_v100_pending_action = structuredClone(action);
      active = optimistic;
      mod.renderCheckersSurface({ game:optimistic, me, container, onAction });
    };

    mod.renderCheckersSurface({ game:base, me, container, onAction });
    await new Promise(resolve => setTimeout(resolve, 600));

    function collectRules(ruleList, bucket, ownerHref, depth = 0){
      if (!ruleList || depth > 5) return;
      for (const rule of Array.from(ruleList)) {
        const text = String(rule.cssText || '');
        if (text.includes('mgw-checkers-live-real-move-piece') || text.includes('mgw-checkers-live-real-piece-flip')) {
          bucket.push({ ownerHref, type:rule.type, text:text.slice(0, 1200) });
        }
        if (rule.cssRules) {
          try { collectRules(rule.cssRules, bucket, ownerHref, depth + 1); } catch (_) {}
        }
      }
    }

    const correctiveLink = document.querySelector('link[data-mgw-checkers-runtime-corrective]');
    const styleSheets = [];
    const matchedRules = [];
    for (const sheet of Array.from(document.styleSheets)) {
      const item = { href:sheet.href || 'inline', disabled:Boolean(sheet.disabled), rules:null, error:'' };
      try {
        item.rules = sheet.cssRules.length;
        collectRules(sheet.cssRules, matchedRules, item.href);
      } catch (error) {
        item.error = String(error?.name || error);
      }
      if ((item.href || '').includes('checkers') || matchedRules.some(rule => rule.ownerHref === item.href)) {
        styleSheets.push(item);
      }
    }

    const cssProbe = {
      reducedMotion:matchMedia('(prefers-reduced-motion: reduce)').matches,
      correctiveLink: correctiveLink ? {
        href:correctiveLink.href,
        rel:correctiveLink.rel,
        media:correctiveLink.media,
        disabled:Boolean(correctiveLink.disabled),
        sheetPresent:Boolean(correctiveLink.sheet),
        dataset:{ ...correctiveLink.dataset },
      } : null,
      styleSheets,
      matchedRules,
    };

    const sourceButton = container.querySelector('[data-checkers-cell="40"][data-checkers-piece]');
    if (!sourceButton) throw new Error('source button missing');
    sourceButton.click();
    const sourcePiece = container.querySelector('[data-checkers-cell="40"] .checkers-piece');
    const sourceRect = sourcePiece?.getBoundingClientRect();
    const targetButton = container.querySelector('[data-checkers-cell="33"][data-checkers-target]');
    if (!targetButton) throw new Error('target button missing');
    targetButton.click();

    function snap(label){
      const piece = container.querySelector('[data-checkers-cell="33"] .checkers-piece');
      const cell = container.querySelector('[data-checkers-cell="33"]');
      const cs = piece ? getComputedStyle(piece) : null;
      const rect = piece?.getBoundingClientRect();
      const cellRect = cell?.getBoundingClientRect();
      const anims = piece?.getAnimations().map(a => ({
        name:a.animationName || '', currentTime:a.currentTime, playState:a.playState, startTime:a.startTime,
      })) || [];
      return {
        label, receivedAction,
        className:piece?.className || '',
        dataMove:piece?.dataset?.mgwRealMove || '',
        dx:piece?.style?.getPropertyValue('--mgw-real-move-dx') || '',
        dy:piece?.style?.getPropertyValue('--mgw-real-move-dy') || '',
        delay:piece?.style?.getPropertyValue('--mgw-real-move-delay') || '',
        animationName:cs?.animationName || '', animationDelay:cs?.animationDelay || '', transform:cs?.transform || '',
        rect:rect ? { left:rect.left, top:rect.top, width:rect.width, height:rect.height } : null,
        cellRect:cellRect ? { left:cellRect.left, top:cellRect.top, width:cellRect.width, height:cellRect.height } : null,
        sourceRect:sourceRect ? { left:sourceRect.left, top:sourceRect.top, width:sourceRect.width, height:sourceRect.height } : null,
        animations:anims,
        surfaceRealMove:container.dataset.mgwCheckersRealMove || '',
        singleFlight:container.dataset.mgwCheckersSingleFlight || '',
        liveFx:container.dataset.mgwCheckersLiveEffect || '',
        overlayPiece:Boolean(document.querySelector('.mgw-checkers-live-fx-piece')),
      };
    }

    const samples = [snap('sync')];
    await new Promise(requestAnimationFrame); samples.push(snap('raf1'));
    await new Promise(requestAnimationFrame); samples.push(snap('raf2'));
    await new Promise(resolve => setTimeout(resolve, 80)); samples.push(snap('80ms'));
    return { cssProbe, samples };
  });

  console.log('MGW_CHECKERS_CSS_PROBE=' + JSON.stringify(result.cssProbe));
  console.log('MGW_CHECKERS_FIRST_PAINT=' + JSON.stringify(result.samples));
  await browser.close();
})().catch(error => {
  console.error(error);
  process.exit(1);
});
