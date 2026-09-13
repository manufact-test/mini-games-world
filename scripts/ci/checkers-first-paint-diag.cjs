const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless:true });
  const page = await browser.newPage({ viewport:{ width:390, height:844 }, reducedMotion:'no-preference' });
  await page.goto(process.env.ORIGIN + '/app/v110.php?v=1127&diag=checkers-cascade', { waitUntil:'domcontentloaded', timeout:60000 });
  await page.waitForTimeout(1000);

  const result = await page.evaluate(async () => {
    const mod = await import('/app/assets/js/checkers-cosmetics/renderer-single-flight-v1.js?v=1&mvp19_6=single-flight-dom-v1&diag=' + Date.now());
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;left:0;top:0;width:390px;height:844px;z-index:99999;background:#0a0e18;padding:16px;box-sizing:border-box;';
    document.body.appendChild(host);
    const container = document.createElement('div');
    host.appendChild(container);

    const board = Array(64).fill(''); board[40] = 'w'; board[17] = 'b';
    const p1 = { id:'p1', name:'A', side:'white', game_cosmetics:{ slots:{ game_checkers_effect:'game-checkers-effect-move', game_checkers_elements:'game-checkers-pieces-neon' } } };
    const p2 = { id:'p2', name:'B', side:'black', game_cosmetics:{ slots:{} } };
    const base = { id:'diag-cascade', status:'active', turn:'p1', viewer_side:'white', board, players:[p1,p2], legal_moves:[{from:40,to:33,capture:false}], capture_required:false, forced_piece:null, pending_captures:[], last_captured_cells:[], last_promotion:null, white_pieces:1, black_pieces:1, white_kings:0, black_kings:0, move_timeout_sec:60 };
    let active = structuredClone(base);
    const me = { id:'p1' };
    const onAction = action => {
      const optimistic = structuredClone(active);
      optimistic.board[action.to] = optimistic.board[action.from]; optimistic.board[action.from] = '';
      optimistic.last_move = { from:action.from, to:action.to, capture:false, captured:null, player_id:'p1', promoted:false, chain_continues:false };
      optimistic.turn = 'p2'; optimistic.legal_moves = []; optimistic.__mgw_v100_pending_action = structuredClone(action);
      active = optimistic;
      mod.renderCheckersSurface({ game:optimistic, me, container, onAction });
    };

    mod.renderCheckersSurface({ game:base, me, container, onAction });
    await new Promise(resolve => setTimeout(resolve, 600));
    container.querySelector('[data-checkers-cell="40"][data-checkers-piece]').click();
    container.querySelector('[data-checkers-cell="33"][data-checkers-target]').click();
    const piece = container.querySelector('[data-checkers-cell="33"] .checkers-piece');
    if (!(piece instanceof HTMLElement)) throw new Error('moving piece missing');

    const properties = ['animation','animation-name','animation-duration','animation-delay','animation-play-state','transform','transition'];
    const cascade = [];
    let sourceOrder = 0;

    function visitRules(rules, href, activeContext=true, context=''){
      for (const rule of Array.from(rules || [])) {
        if (rule instanceof CSSMediaRule) {
          const matches = matchMedia(rule.conditionText).matches;
          visitRules(rule.cssRules, href, activeContext && matches, context + ` @media(${rule.conditionText})=${matches}`);
          continue;
        }
        if (rule instanceof CSSSupportsRule) {
          let matches = true;
          try { matches = CSS.supports(rule.conditionText); } catch (_) {}
          visitRules(rule.cssRules, href, activeContext && matches, context + ` @supports(${rule.conditionText})=${matches}`);
          continue;
        }
        if (!(rule instanceof CSSStyleRule)) continue;
        sourceOrder++;
        if (!activeContext) continue;
        let matches = false;
        try { matches = piece.matches(rule.selectorText); } catch (_) {}
        if (!matches) continue;
        const declarations = {};
        for (const prop of properties) {
          const value = rule.style.getPropertyValue(prop);
          if (value) declarations[prop] = { value, important:rule.style.getPropertyPriority(prop) === 'important' };
        }
        if (Object.keys(declarations).length) cascade.push({ href, sourceOrder, selector:rule.selectorText, context, declarations });
      }
    }

    for (const sheet of Array.from(document.styleSheets)) {
      try { visitRules(sheet.cssRules, sheet.href || 'inline'); } catch (_) {}
    }

    const cs = getComputedStyle(piece);
    return {
      reducedMotion:matchMedia('(prefers-reduced-motion: reduce)').matches,
      className:piece.className,
      inlineStyle:piece.getAttribute('style') || '',
      computed:{ animation:cs.animation, animationName:cs.animationName, animationDuration:cs.animationDuration, animationDelay:cs.animationDelay, animationPlayState:cs.animationPlayState, transform:cs.transform, transition:cs.transition },
      cascade,
    };
  });

  console.log('MGW_CHECKERS_CASCADE=' + JSON.stringify(result));
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
