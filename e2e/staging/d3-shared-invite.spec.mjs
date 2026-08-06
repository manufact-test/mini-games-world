import { test, expect } from '@playwright/test';
import { APP_ROUTE, INVITES_ROUTE, isActionResponse } from './support/d3-shared-config.mjs';
import {
  authorizeContext,
  installTelegramShareMock,
  openPlayerPage,
  cleanupPlayer,
  revokeContext,
} from './support/d3-shared-context.mjs';
import {
  createActionCounter,
  installPreparedMessageHarness,
  expectPlayerRequest,
  clickInviteAction,
} from './support/d3-shared-actions.mjs';

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

    playerA = await openPlayerPage(contextA, 'A');
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
      page => { counterB = createActionCounter(page); },
    );
    await expect(playerB.page.locator('#sheet .sheet-head h2'))
      .toHaveText('Вас приглашают сыграть');
    await expect(playerB.page.locator(
      `#sheet [data-invite-sheet][data-invite-token="${token}"]`,
    )).toHaveCount(1);
    await expect.poll(() => counterB.count('open_link')).toBe(1);

    const accepted = await clickInviteAction(playerB.page, 'accept', token);
    expect(['accepted', 'awaiting_start'])
      .toContain(String(accepted?.invite?.status || ''));
    await expect(playerB.page.locator('#sheet .sheet-head h2'))
      .toHaveText('Приглашение принято');

    playerA.diagnostics.allowInviteSyncAbort = true;
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
    expect(String(gameA?.game?.id || '')).toBe(gameId);
    expect(String(gameB?.game?.id || '')).toBe(gameId);
    expect(gameA?.game?.status).toBe('active');
    expect(gameB?.game?.status).toBe('active');
    expect(counterA.count('start')).toBe(1);
    expect(counterB.count('open_link')).toBe(1);
    expect(playerA.diagnostics.ignoredInviteSyncAborts).toBeLessThanOrEqual(1);

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);

    await testInfo.attach('d3-shared-invite-report', {
      body: Buffer.from(`${JSON.stringify({
        ok: true,
        ordinaryStartRoute: '/app/v110.php?v=1123',
        nativeShareInvoked: true,
        nativeCancellationQuiet: true,
        cancelledDraftReused: true,
        preparedMessageSource: preparedHarness.evidence.serverPreparedIds[0]
          ? 'server'
          : 'staging_harness',
        controlledInviteSyncAborts: playerA.diagnostics.ignoredInviteSyncAborts,
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
