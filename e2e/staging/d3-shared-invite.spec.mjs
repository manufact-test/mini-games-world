import { test, expect } from '@playwright/test';
import { APP_ROUTE, INVITES_ROUTE, isActionResponse, requestAction } from './support/d3-shared-config.mjs';
import {
  authorizeContext,
  installTelegramShareMock,
  openPlayerPage,
  cleanupPlayer,
  postFromPlayer,
  revokeContext,
} from './support/d3-shared-context.mjs';
import {
  createActionCounter,
  installPreparedMessageHarness,
  expectPlayerRequest,
  clickInviteAction,
} from './support/d3-shared-actions.mjs';

async function cleanupStartedPhaseBGame(page, gameId) {
  if (!page || page.isClosed() || !gameId) return;

  // A shared-invite test may finish while the newly created Phase B game is
  // still in preparing/countdown. Product rules correctly reject surrender in
  // those phases, so cleanup must wait on the authoritative launch state rather
  // than silently leaving a live game/session behind for the next scenario.
  await expect.poll(async () => {
    const state = await postFromPlayer(page, '/bot/api.php', {
      action: 'game_state',
      gameId,
    });
    if (state.status !== 200) return `http_${state.status}`;

    const game = state.payload?.game || null;
    if (!game || String(game.status || '') !== 'active') return 'terminal';
    return String(game.launch_phase || 'active');
  }, {
    timeout: 15_000,
    intervals: [250, 500, 1000],
    message: 'D3 cleanup waits for authoritative Phase B launch or terminal state',
  }).toMatch(/^(active|terminal)$/);

  const state = await postFromPlayer(page, '/bot/api.php', {
    action: 'game_state',
    gameId,
  });
  expect(
    state.status,
    `D3 cleanup game_state; public error: ${state.publicError || 'no_public_error'}`,
  ).toBe(200);

  const game = state.payload?.game || null;
  if (!game || String(game.status || '') !== 'active') return;
  expect(String(game.launch_phase || 'active')).toBe('active');

  const left = await postFromPlayer(page, '/bot/api.php', {
    action: 'leave_game',
    gameId,
  });
  expect(
    left.status,
    `D3 cleanup leave_game; public error: ${left.publicError || 'no_public_error'}`,
  ).toBe(200);
  expect(String(left.payload?.game?.status || '')).not.toBe('active');
}


async function installHeldPreActionSync(page, action){
  let syncCapturedResolve;
  let syncReleaseResolve;
  let syncDeliveredResolve;
  let actionCapturedResolve;
  let actionReleaseResolve;
  const syncCaptured = new Promise(resolve => { syncCapturedResolve = resolve; });
  const syncRelease = new Promise(resolve => { syncReleaseResolve = resolve; });
  const syncDelivered = new Promise(resolve => { syncDeliveredResolve = resolve; });
  const actionCaptured = new Promise(resolve => { actionCapturedResolve = resolve; });
  const actionRelease = new Promise(resolve => { actionReleaseResolve = resolve; });
  let heldSync = false;
  let heldAction = false;

  const handler = async route => {
    const request = route.request();
    if (request.url() !== INVITES_ROUTE || request.method() !== 'POST') {
      await route.fallback();
      return;
    }
    const requestKind = requestAction(request);
    if (requestKind === 'sync' && !heldSync) {
      heldSync = true;
      const response = await route.fetch();
      const body = await response.body();
      const headers = response.headers();
      syncCapturedResolve({ status:response.status() });
      await syncRelease;
      await route.fulfill({ status:response.status(), headers, body });
      syncDeliveredResolve();
      return;
    }
    if (requestKind === action && !heldAction) {
      heldAction = true;
      const response = await route.fetch();
      const body = await response.body();
      const headers = response.headers();
      actionCapturedResolve({ status:response.status() });
      await actionRelease;
      await route.fulfill({ status:response.status(), headers, body });
      return;
    }
    await route.fallback();
  };

  await page.route(INVITES_ROUTE, handler);
  return {
    syncCaptured,
    syncDelivered,
    actionCaptured,
    releaseSync:() => syncReleaseResolve(),
    releaseAction:() => actionReleaseResolve(),
    stop:() => page.unroute(INVITES_ROUTE, handler),
  };
}

async function startInviteSheetTrace(page){
  await page.evaluate(() => {
    window.__MGW_INVITE_TRANSITION_TRACE__ = [];
    window.__MGW_INVITE_TRANSITION_OBSERVER__?.disconnect?.();
    const sheet = document.getElementById('sheet');
    const record = () => {
      const text = String(sheet?.innerText || '').replace(/\s+/g, ' ').trim();
      if (text) window.__MGW_INVITE_TRANSITION_TRACE__.push(text);
    };
    record();
    const observer = new MutationObserver(record);
    if (sheet) observer.observe(sheet, { childList:true, subtree:true, characterData:true, attributes:true });
    window.__MGW_INVITE_TRANSITION_OBSERVER__ = observer;
  });
}

async function readInviteSheetTrace(page){
  return page.evaluate(() => {
    window.__MGW_INVITE_TRANSITION_OBSERVER__?.disconnect?.();
    return Array.isArray(window.__MGW_INVITE_TRANSITION_TRACE__)
      ? window.__MGW_INVITE_TRANSITION_TRACE__.slice()
      : [];
  });
}

test('D3 native share cancellation is quiet and one shared link creates one match', async ({ browser }, testInfo) => {
  test.setTimeout(180_000);
  let contextA;
  let contextB;
  let playerA;
  let playerB;
  let counterA;
  let counterB;
  let preparedHarness;
  let token = '';
  let gameId = '';

  try {
    const options = {
      locale: 'ru-RU',
      timezoneId: 'Europe/Vilnius',
      viewport: { width: 390, height: 844 },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: true,
    };
    contextA = await browser.newContext(options);
    contextB = await browser.newContext(options);
    await installTelegramShareMock(contextA);
    await authorizeContext(contextA, 'A');
    await authorizeContext(contextB, 'B');

    playerA = await openPlayerPage(
      contextA,
      'A',
      APP_ROUTE,
      (_page, diagnostics) => {
        diagnostics.allowBackgroundProfileAbort = true;
        diagnostics.allowBackgroundShopHistoryAbort = true;
      },
    );
    await cleanupPlayer(playerA.page);
    counterA = createActionCounter(playerA.page);
    preparedHarness = await installPreparedMessageHarness(playerA.page);

    await playerA.page.evaluate(() => {
      window.__MGW_D3_TELEGRAM_SHARE__.mode = 'decline';
    });
    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в');
    const shareButton = playerA.page.locator('[data-create-link-invite]');
    await shareButton.click();
    await playerA.page.waitForFunction(() => (
      window.__MGW_D3_TELEGRAM_SHARE__?.results?.length === 1
    ));

    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Пригласить в');
    await expect(shareButton).toBeEnabled();
    await expect(playerA.page.locator('#toast')).not.toHaveClass(/show/);
    await expect(playerA.page.locator('#notificationToast')).not.toHaveClass(/show/);
    await expect.poll(() => counterA.count('create_link_draft')).toBe(1);

    const firstShare = await playerA.page.evaluate(() => {
      const state = window.__MGW_D3_TELEGRAM_SHARE__;
      return {
        preparedId: String(state?.calls?.[0]?.preparedId || ''),
        result: String(state?.results?.[0] || ''),
      };
    });
    expect(firstShare.preparedId).not.toBe('');
    expect(firstShare.result).toBe('declined');
    expect(preparedHarness.evidence.serverPreparedIds).toHaveLength(1);
    expect(preparedHarness.evidence.effectivePreparedIds).toEqual([firstShare.preparedId]);

    await playerA.page.evaluate(() => {
      window.__MGW_D3_TELEGRAM_SHARE__.mode = 'sent';
    });
    const confirmPromise = playerA.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'confirm_shared'),
      { timeout: 35_000 },
    );
    await shareButton.click();
    const confirmResponse = await confirmPromise;
    expect(confirmResponse.status()).toBe(200);
    expect((await confirmResponse.json())?.ok).toBe(true);

    await expect(playerA.page.locator('#sheet .sheet-head h2'))
      .toHaveText('Приглашение отправлено');
    const marker = playerA.page.locator('#sheet [data-invite-sheet]').first();
    token = String(await marker.getAttribute('data-invite-token') || '');
    expect(token).toMatch(/^[a-f0-9]{24}$/);

    const shareState = await playerA.page.evaluate(() => ({
      calls: window.__MGW_D3_TELEGRAM_SHARE__?.calls || [],
      results: window.__MGW_D3_TELEGRAM_SHARE__?.results || [],
    }));
    expect(shareState.results).toEqual(['declined', 'sent']);
    expect(shareState.calls).toHaveLength(2);
    expect(String(shareState.calls[0]?.preparedId || '')).toBe(firstShare.preparedId);
    expect(String(shareState.calls[1]?.preparedId || '')).toBe(firstShare.preparedId);
    expect(counterA.count('create_link_draft')).toBe(1);
    expect(counterA.count('confirm_shared')).toBe(1);

    playerB = await openPlayerPage(
      contextB,
      'B',
      `${APP_ROUTE}&invite=${encodeURIComponent(token)}`,
      (page, diagnostics) => {
        diagnostics.allowBackgroundProfileAbort = true;
        diagnostics.allowBackgroundShopHistoryAbort = true;
        counterB = createActionCounter(page);
      },
    );
    await expect(playerB.page.locator('#sheet .sheet-head h2'))
      .toHaveText('Вас приглашают сыграть');
    await expect(playerB.page.locator(
      `#sheet [data-invite-sheet][data-invite-token="${token}"]`,
    )).toHaveCount(1);
    await expect.poll(() => counterB.count('open_link')).toBe(1);

    const acceptRace = await installHeldPreActionSync(playerB.page, 'accept');
    try {
      const capturedSync = await acceptRace.syncCaptured;
      expect(capturedSync.status).toBe(200);
      await startInviteSheetTrace(playerB.page);

      const acceptedPromise = clickInviteAction(
        playerB.page,
        'accept',
        token,
        () => playerB.diagnostics.beginAcceptInviteSyncAbortOwnership(),
      );
      await expect(playerB.page.locator('#sheet .sheet-head h2'))
        .toHaveText('Приглашение принято', { timeout:650 });
      const capturedAccept = await acceptRace.actionCaptured;
      expect(capturedAccept.status).toBe(200);

      acceptRace.releaseSync();
      await acceptRace.syncDelivered;
      await playerB.page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение принято');
      const transitionTrace = await readInviteSheetTrace(playerB.page);
      const optimisticIndex = transitionTrace.findIndex(value => value.includes('Приглашение принято'));
      expect(optimisticIndex).toBeGreaterThanOrEqual(0);
      expect(transitionTrace.slice(optimisticIndex + 1).some(value => value.includes('Вас приглашают сыграть'))).toBe(false);

      acceptRace.releaseAction();
      const accepted = await acceptedPromise;
      expect(['accepted', 'awaiting_start'])
        .toContain(String(accepted?.invite?.status || ''));
      await expect(playerB.page.locator('#sheet .sheet-head h2'))
        .toHaveText('Приглашение принято');
    } finally {
      acceptRace.releaseSync();
      acceptRace.releaseAction();
      await acceptRace.stop();
    }

    playerA.diagnostics.allowInviteSyncAbort = true;
    playerB.diagnostics.allowInviteSyncAbort = true;
    let started;
    try {
      started = await clickInviteAction(playerA.page, 'start', token);
      gameId = String(started?.game?.id || '');
      expect(gameId).toMatch(/^[A-Za-z0-9_-]{8,120}$/);
      expect(started?.game?.status).toBe('active');
      expect(started?.game?.game_type).toBe('tictactoe');
      await expect(playerA.page.locator('#screen-game')).toHaveClass(/active/);
      await expect(playerB.page.locator('#screen-game')).toHaveClass(/active/);
    } finally {
      playerA.diagnostics.allowInviteSyncAbort = false;
      playerB.diagnostics.allowInviteSyncAbort = false;
    }

    const gameA = await expectPlayerRequest(
      playerA.page,
      '/bot/api.php',
      { action: 'game_state', gameId },
      'Player A shared game state',
    );
    const gameB = await expectPlayerRequest(
      playerB.page,
      '/bot/api.php',
      { action: 'game_state', gameId },
      'Player B shared game state',
    );
    playerA.diagnostics.allowBackgroundProfileAbort = false;
    playerB.diagnostics.allowBackgroundProfileAbort = false;
    playerA.diagnostics.allowBackgroundShopHistoryAbort = false;
    playerB.diagnostics.allowBackgroundShopHistoryAbort = false;

    expect(String(gameA?.game?.id || '')).toBe(gameId);
    expect(String(gameB?.game?.id || '')).toBe(gameId);
    expect(gameA?.game?.status).toBe('active');
    expect(gameB?.game?.status).toBe('active');
    expect(counterA.count('start')).toBe(1);
    expect(counterB.count('open_link')).toBe(1);
    expect(playerA.diagnostics.ignoredInviteSyncAborts).toBeLessThanOrEqual(1);
    expect(playerB.diagnostics.ignoredInviteSyncAborts).toBeLessThanOrEqual(1);
    expect(playerA.diagnostics.ignoredAcceptInviteSyncAborts).toBe(0);
    expect(playerB.diagnostics.ignoredAcceptInviteSyncAborts).toBeLessThanOrEqual(1);
    expect(playerA.diagnostics.ignoredBackgroundProfileAborts).toBeLessThanOrEqual(1);
    expect(playerB.diagnostics.ignoredBackgroundProfileAborts).toBeLessThanOrEqual(1);
    expect(playerA.diagnostics.ignoredBackgroundShopHistoryAborts).toBeLessThanOrEqual(1);
    expect(playerB.diagnostics.ignoredBackgroundShopHistoryAborts).toBeLessThanOrEqual(1);

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    await testInfo.attach('d3-shared-invite-report', {
      body: Buffer.from(`${JSON.stringify({
        ok: true,
        ordinaryStartRoute: new URL(APP_ROUTE).pathname + new URL(APP_ROUTE).search,
        nativeShareInvoked: true,
        nativeCancellationQuiet: true,
        cancelledDraftReused: true,
        preparedMessageSource: preparedHarness.evidence.serverPreparedIds[0]
          ? 'server'
          : 'staging_harness',
        controlledInviteSyncAborts: {
          playerA: playerA.diagnostics.ignoredInviteSyncAborts,
          playerB: playerB.diagnostics.ignoredInviteSyncAborts,
        },
        controlledBackgroundProfileAborts: {
          playerA: playerA.diagnostics.ignoredBackgroundProfileAborts,
          playerB: playerB.diagnostics.ignoredBackgroundProfileAborts,
        },
        controlledBackgroundShopHistoryAborts: {
          playerA: playerA.diagnostics.ignoredBackgroundShopHistoryAborts,
          playerB: playerB.diagnostics.ignoredBackgroundShopHistoryAborts,
        },
        createLinkDraftRequests: counterA.count('create_link_draft'),
        confirmSharedRequests: counterA.count('confirm_shared'),
        openLinkRequests: counterB.count('open_link'),
        startRequests: counterA.count('start'),
        sharedPlayersUseSameGame: true,
        productionChanged: false,
        livePaymentsUsed: false,
      }, null, 2)}\n`, 'utf8'),
      contentType: 'application/json',
    });
  } finally {
    await cleanupStartedPhaseBGame(playerA?.page, gameId);
    await preparedHarness?.stop();
    counterA?.stop();
    counterB?.stop();
    await cleanupPlayer(playerA?.page);
    await cleanupPlayer(playerB?.page);
    if (contextA) {
      await revokeContext(contextA);
      await contextA.close();
    }
    if (contextB) {
      await revokeContext(contextB);
      await contextB.close();
    }
  }
});
