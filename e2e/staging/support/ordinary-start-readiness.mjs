import { expect } from '@playwright/test';

function requestAction(response) {
  try {
    return String(response.request().postDataJSON()?.action || '');
  } catch {
    return '';
  }
}

function isBootstrapResponse(response, apiRoute) {
  return response.url() === apiRoute
    && response.request().method() === 'POST'
    && requestAction(response) === 'bootstrap';
}

export async function openOrdinaryStartReady(page, {
  appRoute,
  apiRoute,
  label = 'App',
  timeout = 35_000,
  requirePreloaderHidden = true,
} = {}) {
  if (!appRoute || !apiRoute) {
    throw new Error('Ordinary Start readiness requires appRoute and apiRoute.');
  }

  const origin = new URL(appRoute).origin;
  let bootstrapResponse = null;
  let resolveBootstrapObserved;
  const bootstrapObserved = new Promise(resolve => {
    resolveBootstrapObserved = resolve;
  });
  const pageErrors = [];
  const serverErrors = [];

  const onPageError = error => {
    pageErrors.push(String(error?.message || error).slice(0, 500));
  };
  const onResponse = response => {
    if (response.url().startsWith(origin) && response.status() >= 500) {
      serverErrors.push({
        status: response.status(),
        path: new URL(response.url()).pathname,
      });
    }
    if (!bootstrapResponse && isBootstrapResponse(response, apiRoute)) {
      bootstrapResponse = response;
      resolveBootstrapObserved?.();
    }
  };

  page.on('pageerror', onPageError);
  page.on('response', onResponse);

  try {
    const response = await page.goto(appRoute, { waitUntil: 'domcontentloaded' });
    expect(response, `${label} app response`).not.toBeNull();
    expect(response.ok(), `${label} app status`).toBe(true);
    await expect(page, `${label} title`).toHaveTitle(/Mini Games World/i);
    await expect(page.locator('#screen-home'), `${label} visible home readiness`)
      .toHaveClass(/active/, { timeout });
    if (requirePreloaderHidden) {
      await expect(page.locator('#preloader'), `${label} preloader`).toBeHidden({ timeout });
    }

    // The user-visible home is the authoritative readiness result. Give the
    // browser a short opportunity to expose the transport response as extra
    // evidence, but never fail an already ready app solely because that event
    // was hidden by the client fetch layer.
    await Promise.race([
      bootstrapObserved,
      page.waitForTimeout(250),
    ]);

    expect(pageErrors, `${label} startup page errors`).toEqual([]);
    expect(serverErrors, `${label} startup server errors`).toEqual([]);

    if (bootstrapResponse) {
      expect(bootstrapResponse.status(), `${label} bootstrap status`).toBe(200);
      const bootstrap = await bootstrapResponse.json().catch(() => null);
      expect(bootstrap?.ok, `${label} bootstrap payload`).toBe(true);
    }

    return {
      response,
      bootstrapObserved: Boolean(bootstrapResponse),
    };
  } finally {
    page.off('pageerror', onPageError);
    page.off('response', onResponse);
  }
}
