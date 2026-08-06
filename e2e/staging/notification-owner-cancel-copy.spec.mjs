import { test, expect } from '@playwright/test';
import {
  APP_ROUTE,
  INVITES_ROUTE,
  isActionResponse,
} from './support/d3-shared-config.mjs';
import {
  authorizeContext,
  openPlayerPage,
  cleanupPlayer,
  revokeContext,
} from './support/d3-shared-context.mjs';
import {
  expectPlayerRequest,
} from './support/d3-shared-actions.mjs';

test('accepted invite cancelled from owner notification keeps explanatory terminal copy', async ({ browser }) => {
  test.setTimeout(120_000);
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

    const created = await expectPlayerRequest(
      playerA.page,
      '/bot/invites.php',
      {
        action: 'create_direct',
        inviteeId: 'stg_test_player_b',
        gameType: 'tictactoe',
        room: 'match',
        bet: 10,
        boardSize: 3,
      },
      'Player A creates notification-copy invitation',
    );
    token = String(created?.invite?.token || '');
    expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);

    const accepted = await expectPlayerRequest(
      playerB.page,
      '/bot/invites.php',
      { action: 'accept', token },
      'Player B accepts notification-copy invitation',
    );
    expect(['accepted', 'awaiting_start']).toContain(String(accepted?.invite?.status || ''));

    await expect.poll(async () => {
      await playerA.page.evaluate(() => {
        document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
        document.dispatchEvent(new Event('visibilitychange'));
      });
      return playerA.page.locator('#notificationsOpen.has-unread').count();
    }, {
      timeout: 25_000,
      intervals: [250, 500, 1000],
      message: 'Player A must receive the accepted invitation notification',
    }).toBe(1);

    await playerA.page.locator('#notificationsOpen').click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', {
      timeout: 20_000,
    });

    const card = playerA.page.locator(
      `#sheet [data-notification-invite-token="${token}"]`,
    );
    await expect(card).toHaveCount(1);
    await expect(card.locator('[data-invite-action="start"]')).toBeVisible({ timeout: 20_000 });
    const cancel = card.locator('[data-invite-action="cancel"]');
    await expect(cancel).toBeVisible();
    await expect(cancel).toBeEnabled();

    const cancelResponse = playerA.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'cancel'),
      { timeout: 35_000 },
    );
    await cancel.click();
    const response = await cancelResponse;
    const payload = await response.json().catch(() => null);
    expect(response.status()).toBe(200);
    expect(payload?.ok).toBe(true);
    expect(String(payload?.invite?.status || '')).toMatch(/cancelled|canceled/);

    await expect(card).toHaveCount(1);
    await expect(card.locator('.notification-head strong')).toHaveText('Отменено');
    await expect(card.locator('.notification-copy p')).toHaveText('Вы отменили своё приглашение.');
    await expect(card.locator('[data-invite-action]')).toHaveCount(0);
    await expect(playerA.page.locator('#notificationToast')).not.toHaveClass(/show/);
    await expect(playerA.page.locator('#toast')).not.toHaveClass(/show/);

    expect(playerA.diagnostics.pageErrors).toEqual([]);
    expect(playerB.diagnostics.pageErrors).toEqual([]);
    expect(playerA.diagnostics.failedRequests).toEqual([]);
    expect(playerB.diagnostics.failedRequests).toEqual([]);
    expect(playerA.diagnostics.serverErrors).toEqual([]);
    expect(playerB.diagnostics.serverErrors).toEqual([]);
  } finally {
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
