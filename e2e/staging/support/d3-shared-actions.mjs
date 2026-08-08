import { expect } from '@playwright/test';
import {
  INVITES_ROUTE,
  requestAction,
  isActionResponse,
} from './d3-shared-config.mjs';
import { postFromPlayer } from './d3-shared-context.mjs';

export function createActionCounter(page) {
  const counts = new Map();
  const listener = request => {
    if (request.url() !== INVITES_ROUTE || request.method() !== 'POST') return;
    const action = requestAction(request);
    if (action) counts.set(action, Number(counts.get(action) || 0) + 1);
  };
  page.on('request', listener);
  return {
    count: action => Number(counts.get(action) || 0),
    stop: () => page.off('request', listener),
  };
}

export async function installPreparedMessageHarness(page) {
  const evidence = { serverPreparedIds: [], effectivePreparedIds: [] };
  const syntheticPreparedId = 'staging-e2e-d3-prepared-message';
  const handler = async route => {
    const request = route.request();
    if (request.url() !== INVITES_ROUTE
      || request.method() !== 'POST'
      || requestAction(request) !== 'create_link_draft') {
      await route.continue();
      return;
    }

    const response = await route.fetch();
    const payload = await response.json().catch(() => null);
    if (!response.ok() || payload?.ok !== true || !payload?.invite?.token) {
      throw new Error(`Invalid create_link_draft response: ${response.status()}`);
    }

    const serverPreparedId = String(payload.invite.prepared_message_id || '');
    const effectivePreparedId = serverPreparedId || syntheticPreparedId;
    evidence.serverPreparedIds.push(serverPreparedId);
    evidence.effectivePreparedIds.push(effectivePreparedId);
    await route.fulfill({
      response,
      json: {
        ...payload,
        invite: { ...payload.invite, prepared_message_id: effectivePreparedId },
      },
    });
  };

  await page.route(INVITES_ROUTE, handler);
  return {
    evidence,
    stop: () => page.unroute(INVITES_ROUTE, handler),
  };
}

export async function expectPlayerRequest(page, pathname, data, label) {
  const result = await postFromPlayer(page, pathname, data);
  expect(
    result.status,
    `${label}; public error: ${result.publicError || 'no_public_error'}`,
  ).toBe(200);
  expect(result.payload?.ok, `${label} payload`).toBe(true);
  return result.payload;
}

export async function clickInviteAction(page, action, token, beforePointerDown = null) {
  const button = page.locator(
    `[data-invite-action="${action}"][data-invite-token="${token}"]`,
  );
  await expect(button, `${action} action`).toBeVisible({ timeout: 30_000 });
  await expect(button).toBeEnabled();
  const responsePromise = page.waitForResponse(
    isActionResponse(INVITES_ROUTE, action),
    { timeout: 35_000 },
  );
  if (typeof beforePointerDown === 'function') beforePointerDown();
  await button.click();
  const response = await responsePromise;
  const payload = await response.json().catch(() => null);
  expect(response.status(), `${action} response`).toBe(200);
  expect(payload?.ok, `${action} payload`).toBe(true);
  return payload;
}
