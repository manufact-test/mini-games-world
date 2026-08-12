import { test, expect } from '@playwright/test';
import {
  STAGING_ORIGIN,
  INVITES_ROUTE,
  requestAction,
  isActionResponse,
} from './support/d3-shared-config.mjs';
import {
  authorizeContext,
  openPlayerPage,
  cleanupPlayer,
  postFromPlayer,
  revokeContext,
} from './support/d3-shared-context.mjs';
import { expectPlayerRequest } from './support/d3-shared-actions.mjs';

const WATCH_ROUTE = `${STAGING_ORIGIN}/bot/invite-watch.php`;

async function holdSingleAction(page, action) {
  let releaseResolve;
  let serverResolve;
  let used = false;
  const releasePromise = new Promise(resolve => { releaseResolve = resolve; });
  const serverDone = new Promise(resolve => { serverResolve = resolve; });

  const handler = async route => {
    const request = route.request();
    if (used
      || request.url() !== INVITES_ROUTE
      || request.method() !== 'POST'
      || requestAction(request) !== action) {
      await route.fallback();
      return;
    }

    used = true;
    const response = await route.fetch();
    const payload = await response.json().catch(() => null);
    serverResolve({ status: response.status(), payload });
    await releasePromise;
    await route.fulfill({ response, json: payload });
  };

  await page.route(INVITES_ROUTE, handler);
  return {
    serverDone,
    release: () => releaseResolve(),
    stop: () => page.unroute(INVITES_ROUTE, handler),
  };
}

async function installNotificationSnapshotIsolation(page) {
  let pending = null;

  const watchHandler = async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, invite: null, unread_count: 0 }),
    });
  };

  const inviteHandler = async route => {
    const request = route.request();
    const action = requestAction(request);

    if (action === 'sync') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          invite: null,
          tracked_invite: null,
          events: [],
          unread_count: 1,
        }),
      });
      return;
    }

    if (pending && !pending.used && action === pending.action) {
      pending.used = true;
      const active = pending;
      const response = await route.fetch();
      const payload = await response.json().catch(() => null);
      active.serverResolve({ status: response.status(), payload });
      await active.releasePromise;
      await route.fulfill({ response, json: payload });
      if (pending === active) pending = null;
      return;
    }

    await route.continue();
  };

  await page.route(WATCH_ROUTE, watchHandler);
  await page.route(INVITES_ROUTE, inviteHandler);

  return {
    hold(action) {
      if (pending) throw new Error(`Invite action ${pending.action} is already held.`);
      let releaseResolve;
      let serverResolve;
      const releasePromise = new Promise(resolve => { releaseResolve = resolve; });
      const serverDone = new Promise(resolve => { serverResolve = resolve; });
      pending = {
        action,
        used: false,
        releasePromise,
        releaseResolve,
        serverResolve,
      };
      return {
        serverDone,
        release: () => releaseResolve(),
      };
    },
    async stop() {
      if (pending) pending.releaseResolve();
      pending = null;
      await page.unroute(INVITES_ROUTE, inviteHandler);
      await page.unroute(WATCH_ROUTE, watchHandler);
    },
  };
}

async function newPlayerContext(browser, slot) {
  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Vilnius',
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });
  await authorizeContext(context, slot);
  const player = await openPlayerPage(context, slot);
  return { context, ...player };
}

async function closeSheetIfOpen(page) {
  const overlay = page.locator('#sheetOverlay');
  if (await overlay.isVisible().catch(() => false)) {
    const close = page.locator('#sheet [data-close-sheet]').first();
    if (await close.isVisible().catch(() => false)) await close.click();
  }
}

test('v1137 direct invite, notification Accept and invitee self-cancel have complete immediate frames', async ({ browser }) => {
  let playerA;
  let playerB;
  let earlyCreateHold;
  let notificationIsolation;
  let cancelHold;
  let secondInviteToken = '';

  try {
    playerA = await newPlayerContext(browser, 'A');
    playerB = await newPlayerContext(browser, 'B');
    await cleanupPlayer(playerA.page);
    await cleanupPlayer(playerB.page);
    await closeSheetIfOpen(playerA.page);
    await closeSheetIfOpen(playerB.page);

    // First reported symptom: the sender must be able to cancel immediately,
    // even while the one authoritative create_direct response is still withheld.
    earlyCreateHold = await holdSingleAction(playerA.page, 'create_direct');
    await playerA.page.locator('[data-invite-friend="tictactoe"]').first().click();
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toContainText('Крестики-нолики');
    await playerA.page.locator('[data-open-player-picker]').click();
    const playerBCard = playerA.page.locator('[data-direct-opponent="stg_test_player_b"]');
    await expect(playerBCard).toBeVisible({ timeout: 20_000 });
    await playerBCard.click();

    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение отправлено', {
      timeout: 650,
    });
    const earlyCancel = playerA.page.locator('[data-direct-invite-cancel-reserved]');
    await expect(earlyCancel).toBeVisible({ timeout: 650 });
    await expect(earlyCancel).toBeEnabled({ timeout: 650 });
    await expect(playerA.page.locator('#sheet')).toContainText('Крестики-нолики');
    await expect(playerA.page.locator('#sheet')).toContainText('Матч-комната');
    await expect(playerA.page.locator('#sheet')).toContainText('3×3');
    await expect(playerA.page.locator('#sheet')).toContainText('10 коинов');

    await earlyCancel.click();
    await expect(playerA.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout: 650 });

    const earlyCreateServer = await earlyCreateHold.serverDone;
    expect(earlyCreateServer.status).toBe(200);
    expect(earlyCreateServer.payload?.ok).toBe(true);
    const earlyCancelResponse = playerA.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'cancel'),
      { timeout: 35_000 },
    );
    earlyCreateHold.release();
    const cancelledEarly = await earlyCancelResponse;
    expect(cancelledEarly.status()).toBe(200);
    expect((await cancelledEarly.json()).ok).toBe(true);
    await earlyCreateHold.stop();
    earlyCreateHold = null;

    await cleanupPlayer(playerA.page);
    await cleanupPlayer(playerB.page);
    await closeSheetIfOpen(playerA.page);
    await closeSheetIfOpen(playerB.page);

    // Second reported symptom: make the notification snapshot the only possible
    // immediate invite source by suppressing passive invite sync/watch hydration.
    notificationIsolation = await installNotificationSnapshotIsolation(playerB.page);
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
      'Player A v1137 direct invitation',
    );
    secondInviteToken = String(created.invite?.token || '');
    expect(secondInviteToken).toMatch(/^[a-f0-9]{24}$/);

    const notificationToast = playerB.page.locator('#notificationToast.show');
    await expect.poll(async () => {
      await playerB.page.evaluate(() => {
        // Exercise the real foreground-resume notification path. The internal
        // mgw:notifications-refresh event is intentionally silent.
        document.dispatchEvent(new Event('visibilitychange'));
      });
      return notificationToast.isVisible();
    }, {
      timeout: 20_000,
      intervals: [250, 500, 1000],
      message: 'Player B must receive the direct invitation notification toast',
    }).toBe(true);

    await notificationToast.click();
    const accept = playerB.page.locator(
      `[data-invite-action="accept"][data-invite-token="${secondInviteToken}"]`,
    );
    await expect(accept).toBeVisible({ timeout: 20_000 });
    await expect(accept).toBeEnabled();

    const acceptHold = notificationIsolation.hold('accept');
    await accept.click();

    // The first accepted frame must already be complete before the accept
    // response is released back to the browser.
    await expect(playerB.page.locator('#sheet .sheet-head h2')).toHaveText('Приглашение принято', {
      timeout: 650,
    });
    await expect(playerB.page.locator('#sheet')).toContainText('Крестики-нолики', { timeout: 650 });
    await expect(playerB.page.locator('#sheet')).toContainText('Матч-комната', { timeout: 650 });
    await expect(playerB.page.locator('#sheet')).toContainText('3×3', { timeout: 650 });
    await expect(playerB.page.locator('#sheet')).toContainText('10 коинов', { timeout: 650 });
    await expect(playerB.page.locator('#sheet .invite-status-note')).toHaveText('Ожидаем запуск матча.', {
      timeout: 650,
    });

    const acceptServer = await acceptHold.serverDone;
    expect(acceptServer.status).toBe(200);
    expect(acceptServer.payload?.ok).toBe(true);
    const acceptResponse = playerB.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'accept'),
      { timeout: 35_000 },
    );
    acceptHold.release();
    expect((await acceptResponse).status()).toBe(200);
    await expect(playerB.page.locator('#sheet .invite-status-note')).not.toHaveText('Ожидаем запуск матча.', {
      timeout: 10_000,
    });

    // Snapshot isolation has now served its only purpose: proving that Accept
    // can paint a complete first frame from the notification payload alone.
    // Restore the normal authoritative sync owner before exercising self-cancel.
    await notificationIsolation.stop();
    notificationIsolation = null;

    const acceptedSyncResponse = playerB.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'sync'),
      { timeout: 35_000 },
    );
    await playerB.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    expect((await acceptedSyncResponse).status()).toBe(200);

    // Third reported symptom: the participant who cancels their own accepted
    // invite returns to ordinary activity immediately; no local terminal sheet.
    // Hold only the cancel response; passive sync/watch are normal again.
    cancelHold = await holdSingleAction(playerB.page, 'cancel');
    const cancelParticipation = playerB.page.locator(
      `[data-invite-action="cancel"][data-invite-token="${secondInviteToken}"]`,
    );
    await expect(cancelParticipation).toBeVisible();
    await cancelParticipation.click();
    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout: 650 });
    await expect(playerB.page.locator('#sheet')).not.toContainText('Понятно');

    const cancelServer = await cancelHold.serverDone;
    expect(cancelServer.status).toBe(200);
    expect(cancelServer.payload?.ok).toBe(true);
    const cancelResponse = playerB.page.waitForResponse(
      isActionResponse(INVITES_ROUTE, 'cancel'),
      { timeout: 35_000 },
    );
    cancelHold.release();
    expect((await cancelResponse).status()).toBe(200);
    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout: 2_000 });
    await cancelHold.stop();
    cancelHold = null;

    // The other participant still receives the authoritative remote terminal event.
    await playerA.page.evaluate(() => {
      document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    });
    await playerA.page.locator('#notificationsOpen').click();
    const remoteTerminal = playerA.page.locator(
      `[data-notification-invite-token="${secondInviteToken}"]`,
    ).first();
    await expect(remoteTerminal).toBeVisible({ timeout: 20_000 });
    await expect(remoteTerminal).toContainText('Соперник отменил участие');
  } finally {
    if (earlyCreateHold) {
      earlyCreateHold.release();
      await earlyCreateHold.stop().catch(() => null);
    }
    if (notificationIsolation) await notificationIsolation.stop().catch(() => null);
    if (cancelHold) {
      cancelHold.release();
      await cancelHold.stop().catch(() => null);
    }
    if (secondInviteToken && playerB?.page && !playerB.page.isClosed()) {
      await postFromPlayer(playerB.page, '/bot/invites.php', {
        action: 'cancel',
        token: secondInviteToken,
      }).catch(() => null);
    }
    if (playerA?.page) await cleanupPlayer(playerA.page);
    if (playerB?.page) await cleanupPlayer(playerB.page);
    if (playerA?.context) await revokeContext(playerA.context);
    if (playerB?.context) await revokeContext(playerB.context);
    if (playerA?.context) await playerA.context.close();
    if (playerB?.context) await playerB.context.close();
  }
});
