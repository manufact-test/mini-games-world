from pathlib import Path


def replace_once(path: str | Path, old: str, new: str) -> None:
    path = Path(path)
    text = path.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one occurrence, found {count}: {old[:180]!r}')
    path.write_text(text.replace(old, new, 1), encoding='utf-8')


spec = Path('e2e/staging/d2-d3-d5-integration.spec.mjs')
replace_once(
    spec,
    "test('D2-D3-D5 integration: Share, picker and cancellation keep terminal card in place'",
    "test('D2-D3-D5 integration: Share, picker and owner self-cancel return home while participant history stays terminal'",
)
replace_once(
    spec,
    """    const overlay = playerA.page.locator('#sheetOverlay');
    await expect(overlay).toHaveClass(/active/, { timeout: 15_000 });
    const authoritativeCancelledLabel = String(cancelled?.invite?.status_label || '').trim();
    expect(authoritativeCancelledLabel).toBe('Отменено');
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText(authoritativeCancelledLabel, {
      timeout: 15_000,
    });
    await expect(playerA.page.locator(
      `#sheet [data-invite-sheet][data-invite-token="${directToken}"]`,
    )).toHaveCount(1);
    await expect(playerA.page.locator('#sheet [data-invite-action]')).toHaveCount(0);
    await expect(playerA.page.locator('#sheet')).toContainText('Это приглашение больше нельзя использовать.');
    await expect(playerA.page.locator('#notificationToast')).not.toHaveClass(/show/);
""",
    """    const overlay = playerA.page.locator('#sheetOverlay');
    await expect(overlay).not.toHaveClass(/active/, { timeout: 15_000 });
    await expect.poll(async () => playerA.page.evaluate(() => (
      document.querySelector('.screen.active')?.dataset.screen || ''
    )), { timeout: 10_000 }).toBe('home');
    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveCount(0);
    await expect(playerA.page.locator(
      `#sheet [data-invite-sheet][data-invite-token="${directToken}"]`,
    )).toHaveCount(0);
    await expect(playerA.page.locator('#notificationToast')).not.toHaveClass(/show/);
""",
)
replace_once(
    spec,
    """        terminalCardStayedOpen: true,
        terminalCardActionsRemoved: true,
        actorSelfToastAbsent: true,""",
    """        ownerSelfCancelReturnedHome: true,
        ownerTerminalConfirmationAbsent: true,
        actorSelfToastAbsent: true,""",
)

contract = Path('bot/tests/ProductionMvp14D2D3D5IntegrationContractTest.php')
replace_once(
    contract,
    """$assert(str_contains($e2e, 'const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;')
    && str_contains($e2e, "test('D2-D3-D5 integration: Share, picker and cancellation keep terminal card in place'")
    && str_contains($e2e, "const authoritativeCancelledLabel = String(cancelled?.invite?.status_label || '').trim();")
    && str_contains($e2e, "expect(authoritativeCancelledLabel).toBe('Отменено');")
    && str_contains($e2e, "toHaveText(authoritativeCancelledLabel")
    && !str_contains($e2e, "toHaveText('Приглашение отменено'")
    && str_contains($e2e, "#sheet [data-invite-action]')).toHaveCount(0")
    && str_contains($e2e, "#notificationToast')).not.toHaveClass(/show/")
    && str_contains($e2e, 'async function notificationByInviteToken(page, inviteToken)')
    && str_contains($e2e, 'items.find(candidate =>')
    && str_contains($e2e, 'const bNotification = await notificationByInviteToken(playerB.page, directToken);')
    && str_contains($e2e, 'otherParticipantTerminalStatusPresent: true'),
    'The live E2E must use the authoritative label and return one exact-token terminal notification from the browser context.');
""",
    """$assert(str_contains($e2e, 'const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;')
    && str_contains($e2e, "test('D2-D3-D5 integration: Share, picker and owner self-cancel return home while participant history stays terminal'")
    && str_contains($e2e, "await expect(overlay).not.toHaveClass(/active/")
    && str_contains($e2e, "document.querySelector('.screen.active')?.dataset.screen")
    && str_contains($e2e, "#sheet .sheet-head h2')).toHaveCount(0")
    && str_contains($e2e, "#notificationToast')).not.toHaveClass(/show/")
    && str_contains($e2e, 'async function notificationByInviteToken(page, inviteToken)')
    && str_contains($e2e, 'items.find(candidate =>')
    && str_contains($e2e, 'const bNotification = await notificationByInviteToken(playerB.page, directToken);')
    && str_contains($e2e, 'ownerSelfCancelReturnedHome: true')
    && str_contains($e2e, 'ownerTerminalConfirmationAbsent: true')
    && str_contains($e2e, 'otherParticipantTerminalStatusPresent: true'),
    'The live E2E must prove direct owner return home while the other participant keeps exact-token terminal history.');
""",
)
replace_once(
    contract,
    """$assert(!str_contains($e2e, "not.toHaveClass(/active/")
    && str_contains($e2e, "await expect(overlay).toHaveClass(/active/"),
    'The replacement scenario must forbid the superseded close-sheet acceptance.');
""",
    """$assert(str_contains($e2e, "await expect(overlay).not.toHaveClass(/active/")
    && !str_contains($e2e, "await expect(overlay).toHaveClass(/active/"),
    'The replacement scenario must require the accepted direct-home owner self-cancel behavior.');
""",
)

focused = Path('bot/tests/ProductionMvp14D2TerminalDedupSelfCancelContractTest.php')
replace_once(
    focused,
    """$assert(
    str_contains($e2e, 'remote decline already visible in owner sheet is not repeated as toast or bell card')
        && str_contains($e2e, 'owner self-cancel returns directly home without terminal confirmation'),
    'Live staging coverage must prove both exact user scenarios.'
);""",
    """$assert(
    str_contains($e2e, 'remote decline already visible in owner sheet is not repeated as toast or bell card')
        && str_contains($e2e, 'authoritativeDeclinedLabel')
        && str_contains($e2e, 'owner self-cancel returns directly home without terminal confirmation')
        && str_contains($e2e, "#sheet .sheet-head h2')).toHaveCount(0"),
    'Live staging coverage must prove both exact user scenarios without assuming a non-existent terminal heading.'
);""",
)
