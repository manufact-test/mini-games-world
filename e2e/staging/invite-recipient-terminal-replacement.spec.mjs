import { test, expect } from '@playwright/test';
import {
  APP_ROUTE,
} from './support/d3-shared-config.mjs';
import {
  authorizeContext,
  openPlayerPage,
  cleanupPlayer,
  revokeContext,
  postFromPlayer,
} from './support/d3-shared-context.mjs';
import {
  expectPlayerRequest,
} from './support/d3-shared-actions.mjs';

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
    'Player A creates recipient-terminal replacement invitation',
  );
  const token = String(created?.invite?.token || '');
  expect(token).toMatch(/^[A-Za-z0-9_-]{12,80}$/);
  return token;
}

async function openNotifications(page) {
  if (await page.locator('#sheetOverlay').evaluate(element => element.classList.contains('active'))) {
    await page.locator('#sheet [data-close-sheet]').click();
    await expect(page.locator('#sheetOverlay')).not.toHaveClass(/active/);
  }
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await page.locator('#notificationsOpen').click();
  await expect(page.locator('#sheet .sheet-head h2')).toHaveText(
    'Уведомления',
    { timeout: 30_000 },
  );
}

test('recipient bell replaces active invite with one contextual cancelled terminal card', async ({ browser }) => {
  test.setTimeout(180_000);
  const players = await createPlayers(browser);
  let token = '';
  try {
    token = await createDirectInvite(players.playerA.page);

    await openNotifications(players.playerB.page);
    const activeCard = players.playerB.page.locator(
      `#sheet [data-notification-invite-token="${token}"]`,
    );
    await expect(activeCard).toHaveCount(1, { timeout: 30_000 });
    await expect(activeCard.locator('.invite-actions')).toHaveCount(1);
    const activeMessage = String(await activeCard.locator('p').textContent() || '').trim();
    const inviterName = activeMessage.split(' приглашает')[0].trim();
    expect(inviterName).not.toBe('');

    await players.playerB.page.locator('#sheet [data-close-sheet]').click();
    await expect(players.playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/);

    // A fresh owner sync deliberately no longer restores normal pending state.
    // This regression only needs the authoritative server cancellation to prove
    // that B's active notification is replaced by one contextual terminal card.
    const cancelled = await expectPlayerRequest(
      players.playerA.page,
      '/bot/invites.php',
      { action: 'cancel', token },
      'Player A cancels recipient-terminal replacement invitation',
    );
    expect(String(cancelled?.invite?.status || '')).toMatch(/cancelled|canceled/);

    await openNotifications(players.playerB.page);
    const terminalCards = players.playerB.page.locator(
      `#sheet [data-notification-invite-token="${token}"]`,
    );
    await expect(terminalCards).toHaveCount(1, { timeout: 30_000 });
    const terminalCard = terminalCards.first();
    await expect(terminalCard.locator('.notification-head strong')).toHaveText('Приглашение отменено');
    await expect(terminalCard.locator('.invite-actions')).toHaveCount(0);
    const terminalMessage = String(await terminalCard.locator('p').textContent() || '').trim();
    expect(terminalMessage).toContain(inviterName);
    expect(terminalMessage).toContain('отменил приглашение сыграть');
    expect(terminalMessage).toContain('Крестики-нолики');
    await expect(players.playerB.page.locator('#notificationToast')).not.toHaveClass(/show/);

    expect(players.playerA.diagnostics.pageErrors).toEqual([]);
    expect(players.playerB.diagnostics.pageErrors).toEqual([]);
    expect(players.playerA.diagnostics.failedRequests).toEqual([]);
    expect(players.playerB.diagnostics.failedRequests).toEqual([]);
    expect(players.playerA.diagnostics.serverErrors).toEqual([]);
    expect(players.playerB.diagnostics.serverErrors).toEqual([]);
  } finally {
    if (token && players?.playerA?.page && !players.playerA.page.isClosed()) {
      await postFromPlayer(players.playerA.page, '/bot/invites.php', {
        action: 'cancel',
        token,
      }).catch(() => null);
    }
    await disposePlayers(players);
  }
});
