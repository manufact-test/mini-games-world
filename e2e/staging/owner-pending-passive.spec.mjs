import { test, expect } from '@playwright/test';
import {
  APP_ROUTE,
  API_ROUTE,
  INVITES_ROUTE,
  isActionResponse,
  requestAction,
} from './support/d3-shared-config.mjs';
import {
  authorizeContext,
  openPlayerPage,
  cleanupPlayer,
  revokeContext,
  postFromPlayer,
} from './support/d3-shared-context.mjs';
import { expectPlayerRequest } from './support/d3-shared-actions.mjs';
import { openOrdinaryStartReady } from './support/ordinary-start-readiness.mjs';

function apiActionResponse(action) {
  return response => response.url() === API_ROUTE
    && response.request().method() === 'POST'
    && requestAction(response.request()) === action;
}

test('normal outgoing pending is passive after close/reopen and recipient may accept while sender searches', async ({ browser }) => {
  test.setTimeout(150_000);
  let contextA;
  let contextB;
  let playerA;
  let playerB;
  let token = '';

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
    await authorizeContext(contextA, 'A');
    await authorizeContext(contextB, 'B');

    playerA = await openPlayerPage(contextA, 'A', APP_ROUTE);
    playerB = await openPlayerPage(contextB, 'B', APP_ROUTE);
    await cleanupPlayer(playerA.page);
    await cleanupPlayer(playerB.page);

    await playerA.page.locator('[data-invite-friend="tictactoe"]').click();
    await expect(playerA.page.locator('[data-open-player-picker]')).toBeVisible({ timeout: 20_000 });
    await playerA.page.locator('[data-open-player-picker]').click();
    const opponent = playerA.page.locator('[data-direct-opponent="stg_test_player_b"]');
    await expect(opponent).toBeVisible({ timeout: 20_000 });

    const createResponse = playerA.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'create_direct'),
      { timeout: 35_000 },
    );
    await opponent.click();
    const createdResponse = await createResponse;
    const created = await createdResponse.json().catch(() => null);
    expect(createdResponse.status()).toBe(200);
    expect(created?.ok).toBe(true);
    token = String(created?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);

    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение отправлено', {
      timeout: 20_000,
    });
    await expect(playerA.page.locator(`#sheet [data-invite-action="cancel"][data-invite-token="${token}"]`)).toBeVisible();

    await playerA.page.locator('#sheet [data-close-sheet]').click();
    await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);

    // The local pending owner must no longer intercept an unrelated game launch.
    await playerA.page.locator('#playTicTacToe').click();
    await expect(playerA.page.locator('#startSearchBtn')).toBeVisible({ timeout: 10_000 });
    await playerA.page.locator('#sheet [data-close-sheet]').click();

    // A fresh document must not restore the old sent-pending sheet as active state.
    playerA.diagnostics.allowInviteSyncAbort = true;
    await openOrdinaryStartReady(playerA.page, {
      appRoute: APP_ROUTE,
      apiRoute: API_ROUTE,
      label: 'Player A reopen with passive outgoing pending',
    });
    playerA.diagnostics.allowInviteSyncAbort = false;
    await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    await expect(playerA.page.locator('#sheet')).not.toContainText('Приглашение отправлено');

    const freshSync = await expectPlayerRequest(
      playerA.page,
      '/bot/invites.php',
      { action: 'sync', token: '' },
      'Player A fresh sync hides normal outgoing pending from active state',
    );
    expect(freshSync?.invite ?? null).toBeNull();
    expect(freshSync?.tracked_invite ?? null).toBeNull();

    // The invitation itself must still exist for B.
    const inviteeSync = await expectPlayerRequest(
      playerB.page,
      '/bot/invites.php',
      { action: 'sync', token: '' },
      'Player B still sees the pending invitation',
    );
    const received = inviteeSync?.opened_invite || inviteeSync?.invite || inviteeSync?.tracked_invite || null;
    expect(String(received?.token || '')).toBe(token);
    expect(String(received?.status || '')).toBe('pending');

    // A can really enter ordinary matchmaking while B has not answered yet.
    await playerA.page.locator('#playTicTacToe').click();
    await expect(playerA.page.locator('#startSearchBtn')).toBeVisible({ timeout: 10_000 });
    const startResponse = playerA.page.waitForResponse(apiActionResponse('start_search'), { timeout: 35_000 });
    await playerA.page.locator('#startSearchBtn').click();
    const started = await startResponse;
    const startedPayload = await started.json().catch(() => null);
    expect(started.status()).toBe(200);
    expect(startedPayload?.ok).toBe(true);
    await expect(playerA.page.locator('#screen-search')).toHaveClass(/active/, { timeout: 15_000 });

    // B accepting must not fail just because A is already searching, and must not auto-leave that search.
    const accepted = await expectPlayerRequest(
      playerB.page,
      '/bot/invites.php',
      { action: 'accept', token },
      'Player B accepts while Player A is searching',
    );
    expect(['accepted', 'awaiting_start']).toContain(String(accepted?.invite?.status || ''));

    const stateAfterAccept = await expectPlayerRequest(
      playerA.page,
      '/bot/api.php',
      { action: 'game_state' },
      'Player A remains in ordinary search after invite acceptance',
    );
    expect(String(stateAfterAccept?.user?.status || '')).toBe('searching');

    const leaveResponse = playerA.page.waitForResponse(apiActionResponse('leave_search'), { timeout: 35_000 });
    await playerA.page.locator('#cancelSearch').click();
    const left = await leaveResponse;
    expect(left.status()).toBe(200);
    await expect(playerA.page.locator('#screen-home')).toHaveClass(/active/, { timeout: 15_000 });

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);
  } finally {
    if (token && playerA?.page && !playerA.page.isClosed()) {
      try {
        await postFromPlayer(playerA.page, '/bot/invites.php', { action: 'cancel', token });
      } catch {}
    }
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
