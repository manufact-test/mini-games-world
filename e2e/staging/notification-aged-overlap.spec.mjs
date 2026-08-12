import { test, expect } from '@playwright/test';
import { STAGING_ORIGIN, APP_ROUTE } from './support/d3-shared-config.mjs';
import {
  authorizeContext,
  openPlayerPage,
  revokeContext,
} from './support/d3-shared-context.mjs';

const NOTIFICATIONS_ROUTE = `${STAGING_ORIGIN}/bot/notifications.php`;

function payload(item = null) {
  return {
    ok: true,
    items: item ? [item] : [],
    unread_count: item ? 1 : 0,
  };
}

test('one notification request owner keeps the bell loading state honest', async ({ browser }) => {
  test.setTimeout(90_000);
  let context;
  let player;
  let mode = 'initial';
  let initialReads = 0;
  let raceReads = 0;
  const pending = [];
  const item = {
    id: 'staging_notification_generation_item',
    type: 'invite_cancelled',
    title: 'Приглашение отменено',
    message: 'Матч не начался.',
    tone: 'warning',
    invite_token: 'generation_test_token',
    invite_status: 'cancelled',
    invite_is_owner: false,
    actions: [],
    created_at: new Date().toISOString(),
    read: false,
  };

  try {
    context = await browser.newContext({
      locale: 'ru-RU',
      timezoneId: 'Europe/Vilnius',
      viewport: { width: 390, height: 844 },
      deviceScaleFactor: 1,
      isMobile: true,
      hasTouch: true,
    });
    await authorizeContext(context, 'A');

    await context.route(NOTIFICATIONS_ROUTE, async route => {
      let markRead = false;
      try {
        markRead = Boolean(route.request().postDataJSON()?.markRead);
      } catch {}

      if (mode === 'initial') {
        initialReads += 1;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(payload()),
        });
        return;
      }

      if (markRead) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(payload(item)),
        });
        return;
      }

      raceReads += 1;
      if (pending.length === 0) {
        pending.push(route);
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(payload(item)),
      });
    });

    player = await openPlayerPage(context, 'A', APP_ROUTE);
    await expect.poll(() => initialReads, {
      timeout: 20_000,
      message: 'Initial empty notification baseline must load',
    }).toBeGreaterThan(0);
    await player.page.waitForTimeout(500);
    mode = 'race';

    await player.page.evaluate(() => {
      document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
    });
    await expect.poll(() => pending.length, {
      timeout: 10_000,
      message: 'The single notification owner must have one pending read',
    }).toBe(1);

    await player.page.evaluate(() => {
      const frames = [];
      const sheet = document.getElementById('sheet');
      const capture = () => frames.push({
        active: document.getElementById('sheetOverlay')?.classList.contains('active') === true,
        empty: String(sheet?.querySelector('.notifications-empty strong')?.textContent || '').trim(),
        loading: sheet?.querySelector('.notifications-loading') !== null,
        ids: Array.from(sheet?.querySelectorAll('[data-notification-id]') || [])
          .map(node => String(node.getAttribute('data-notification-id') || '')),
      });
      const observer = new MutationObserver(capture);
      observer.observe(sheet, { childList:true, subtree:true, characterData:true });
      window.__MGW_NOTIFICATION_GENERATION_FRAMES__ = frames;
      window.__MGW_NOTIFICATION_GENERATION_OBSERVER__ = observer;
      capture();
    });

    await player.page.locator('#notificationsOpen').click();
    await expect(player.page.locator('#sheet .sheet-head h2')).toHaveText('Уведомления');
    await expect(player.page.locator('#sheet .notifications-loading')).toBeVisible();
    await player.page.waitForTimeout(500);
    expect(raceReads).toBe(1);

    await pending[0].fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(payload(item)),
    });
    await expect(player.page.locator(
      `[data-notification-id="${item.id}"]`,
    )).toBeVisible({ timeout: 15_000 });

    const frames = await player.page.evaluate(() => {
      window.__MGW_NOTIFICATION_GENERATION_OBSERVER__?.disconnect?.();
      return window.__MGW_NOTIFICATION_GENERATION_FRAMES__ || [];
    });
    const visibleFrames = frames.filter(frame => frame.active);
    expect(visibleFrames.some(frame => /Пока уведомлений нет/i.test(frame.empty))).toBe(false);
    expect(visibleFrames.some(frame => frame.ids.includes(item.id))).toBe(true);

    expect(player.diagnostics.pageErrors).toEqual([]);
    expect(player.diagnostics.failedRequests).toEqual([]);
    expect(player.diagnostics.serverErrors).toEqual([]);
  } finally {
    if (context) {
      await revokeContext(context);
      await context.close();
    }
  }
});
