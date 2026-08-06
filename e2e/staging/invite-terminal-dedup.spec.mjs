import { test, expect } from '@playwright/test';
import {
  APP_ROUTE,
  INVITES_ROUTE,
  STAGING_ORIGIN,
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
  clickInviteAction,
} from './support/d3-shared-actions.mjs';

const NOTIFICATIONS_ROUTE = `${STAGING_ORIGIN}/bot/notifications.php`;

function isConsumeResponse(token) {
  return response => {
    if (response.url() !== NOTIFICATIONS_ROUTE || response.request().method() !== 'POST') return false;
    try {
      return String(response.request().postDataJSON()?.consumeInviteToken || '') === token;
    } catch {
      return false;
    }
  };
}

async function createPlayers(browser) {
  const options = {
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  };
  const contextA = await browser.newContext(options);
  const contextB = await browser.newContext(options);
  await authorizeContext(contextA, 'A');
  await authorizeContext(contextB, 'B');
  const playerA = await openPlayerPage(contextA, 'A', APP_ROUTE);
  const playerB = await openPlayerPage(contextB, 'B', APP_ROUTE);
  await cleanupPlayer(playerA.page);
  await cleanupPlayer(playerB.page);
  return { contextA, contextB, playerA, playerB };
}

async function disposePlayers(players) {
  await cleanupPlayer(players?.playerA?.page);
  await cleanupPlayer(players?.playerB?.page);
  if (players?.contextA) {
    await revokeContext(players.contextA);
    await players.contextA.close();
  }
  if (players?.contextB) {
    await revokeContext(players.contextB);
    await players.contextB.close();
  }
}

async function createDirectInvite(page) {
  const created = await expectPlayerRequest(
    page,
    '/bot/invites.php',
    {
      action: 'create_direct',
      inviteeId: 'stg_test_player_b',
      gameType: 'tictactoe',
      room: 'match',
      bet: 10,
      boardSize: 3,
    },
    'Player A creates terminal-dedup invitation',
  );
  const token = String(created?.invite?.token || '');
  expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
  return token;
}

async function syncOwnerInvite(page, token) {
  let matched = false;
  for (let attempt = 0; attempt < 4 && !matched; attempt += 1) {
    const responsePromise = page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'sync'),
      { timeout: 8_000 },
    ).catch(() => null);
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    const response = await responsePromise;
    if (!response || response.status() !== 200) continue;
    const payload = await response.json().catch(() => null);
    const invite = payload?.invite || payload?.tracked_invite || null;
    matched = String(invite?.token || '') === token;
  }
  expect(matched, 'Owner module must synchronize the created invitation').toBe(true);
  await page.waitForTimeout(120);
  await page.locator('[data-invite-friend="tictactoe"]').click();
  await expect(page.locator(`#sheet [data-invite-sheet][data-invite-token="${token}"]`)).toHaveCount(1);
  await expect(page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение отправлено');
}

async function expectTokenAbsentFromBell(page, token) {
  await page.waitForTimeout(1_200);
  await expect(page.locator('#notificationToast')).not.toHaveClass(/show/);
  await page.locator('#notificationsOpen').click();
  await expect(page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления', { timeout: 20_000 });
  await expect(page.locator(`#sheet [data-notification-invite-token="${token}"]`)).toHaveCount(0);
}

test('remote decline already visible in owner sheet is not repeated as toast or bell card', async ({ browser }) => {
  test.setTimeout(150_000);
  const players = await createPlayers(browser);
  try {
    const token = await createDirectInvite(players.playerA.page);
    await syncOwnerInvite(players.playerA.page, token);

    const consumeResponse = players.playerA.page.waitForResponse(
      isConsumeResponse(token),
      { timeout: 35_000 },
    );
    const declined = await expectPlayerRequest(
      players.playerB.page,
      '/bot/invites.php',
      { action: 'decline', token },
      'Player B declines while Player A watches the waiting sheet',
    );
    expect(String(declined?.invite?.status || '')).toBe('declined');
    const authoritativeDeclinedLabel = String(
      declined?.invite?.status_label || 'Отклонено',
    ).trim();
    expect(authoritativeDeclinedLabel).not.toBe('');
    await players.playerA.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(players.playerA.page.locator('#sheet .sheet-head h2')).toHaveText(
      authoritativeDeclinedLabel,
      { timeout: 30_000 },
    );
    const consumed = await consumeResponse;
    expect(consumed.status()).toBe(200);
    expect((await consumed.json().catch(() => null))?.ok).toBe(true);

    await players.playerA.page.locator('#sheet .btn.primary[data-close-sheet]').click();
    await expect(players.playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    await expectTokenAbsentFromBell(players.playerA.page, token);

    expect(players.playerA.diagnostics.pageErrors).toEqual([]);
    expect(players.playerB.diagnostics.pageErrors).toEqual([]);
    expect(players.playerA.diagnostics.failedRequests).toEqual([]);
    expect(players.playerB.diagnostics.failedRequests).toEqual([]);
    expect(players.playerA.diagnostics.serverErrors).toEqual([]);
    expect(players.playerB.diagnostics.serverErrors).toEqual([]);
  } finally {
    await disposePlayers(players);
  }
});

test('owner self-cancel returns directly home without terminal confirmation', async ({ browser }) => {
  test.setTimeout(150_000);
  const players = await createPlayers(browser);
  try {
    const token = await createDirectInvite(players.playerA.page);
    await syncOwnerInvite(players.playerA.page, token);

    const consumeResponse = players.playerA.page.waitForResponse(
      isConsumeResponse(token),
      { timeout: 35_000 },
    );
    const cancelled = await clickInviteAction(players.playerA.page, 'cancel', token);
    expect(String(cancelled?.invite?.status || '')).toMatch(/cancelled|canceled/);
    const consumed = await consumeResponse;
    expect(consumed.status()).toBe(200);
    expect((await consumed.json().catch(() => null))?.ok).toBe(true);

    await expect(players.playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/);
    await expect.poll(async () => players.playerA.page.evaluate(() => (
      document.querySelector('.screen.active')?.dataset.screen || ''
    )), { timeout: 10_000 }).toBe('home');
    await expect(players.playerA.page.locator('#sheet .sheet-head h2')).toHaveCount(0);
    await expect(players.playerA.page.locator(
      `#sheet [data-invite-sheet][data-invite-token="${token}"]`,
    )).toHaveCount(0);
    await expectTokenAbsentFromBell(players.playerA.page, token);

    expect(players.playerA.diagnostics.pageErrors).toEqual([]);
    expect(players.playerB.diagnostics.pageErrors).toEqual([]);
    expect(players.playerA.diagnostics.failedRequests).toEqual([]);
    expect(players.playerB.diagnostics.failedRequests).toEqual([]);
    expect(players.playerA.diagnostics.serverErrors).toEqual([]);
    expect(players.playerB.diagnostics.serverErrors).toEqual([]);
  } finally {
    await disposePlayers(players);
  }
});
