from pathlib import Path

PATH = Path('e2e/staging/d3-shared-invite.spec.mjs')
text = PATH.read_text(encoding='utf-8')
if 'installHeldPreActionSync' in text:
    raise SystemExit(0)

text = text.replace(
    "import { APP_ROUTE, INVITES_ROUTE, isActionResponse } from './support/d3-shared-config.mjs';",
    "import { APP_ROUTE, INVITES_ROUTE, isActionResponse, requestAction } from './support/d3-shared-config.mjs';",
    1,
)

marker = "\ntest('D3 native share cancellation is quiet and one shared link creates one match', async ({ browser }, testInfo) => {"
if text.count(marker) != 1:
    raise SystemExit(f'test marker count={text.count(marker)}')
helper = r'''

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
'''
text = text.replace(marker, helper + marker, 1)

old = r'''    const accepted = await clickInviteAction(
      playerB.page,
      'accept',
      token,
      () => playerB.diagnostics.beginAcceptInviteSyncAbortOwnership(),
    );
    expect(['accepted', 'awaiting_start'])
      .toContain(String(accepted?.invite?.status || ''));
    await expect(playerB.page.locator('#sheet .sheet-head h2'))
      .toHaveText('Приглашение принято');
'''
new = r'''    const acceptRace = await installHeldPreActionSync(playerB.page, 'accept');
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
'''
if text.count(old) != 1:
    raise SystemExit(f'accept block count={text.count(old)}')
text = text.replace(old, new, 1)
PATH.write_text(text, encoding='utf-8')
