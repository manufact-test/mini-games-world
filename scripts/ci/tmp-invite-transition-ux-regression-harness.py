from pathlib import Path

path = Path('e2e/staging/invite-transition-ux-v1137.spec.mjs')
text = path.read_text(encoding='utf-8')

old_vars = """  let earlyCreateHold;\n  let notificationIsolation;\n  let secondInviteToken = '';\n"""
new_vars = """  let earlyCreateHold;\n  let notificationIsolation;\n  let cancelHold;\n  let secondInviteToken = '';\n"""
if text.count(old_vars) != 1:
    raise SystemExit(f'Expected one test variable anchor, found {text.count(old_vars)}')
text = text.replace(old_vars, new_vars, 1)

old_block = """    // Third reported symptom: the participant who cancels their own accepted\n    // invite returns to ordinary activity immediately; no local terminal sheet.\n    const cancelHold = notificationIsolation.hold('cancel');\n    const cancelParticipation = playerB.page.locator(\n      `[data-invite-action=\"cancel\"][data-invite-token=\"${secondInviteToken}\"]`,\n    );\n    await expect(cancelParticipation).toBeVisible();\n    await cancelParticipation.click();\n    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout: 650 });\n    await expect(playerB.page.locator('#sheet')).not.toContainText('Понятно');\n\n    const cancelServer = await cancelHold.serverDone;\n    expect(cancelServer.status).toBe(200);\n    expect(cancelServer.payload?.ok).toBe(true);\n    const cancelResponse = playerB.page.waitForResponse(\n      isActionResponse(INVITES_ROUTE, 'cancel'),\n      { timeout: 35_000 },\n    );\n    cancelHold.release();\n    expect((await cancelResponse).status()).toBe(200);\n    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout: 2_000 });\n\n    await notificationIsolation.stop();\n    notificationIsolation = null;\n"""
new_block = """    // Snapshot isolation has now served its only purpose: proving that Accept\n    // can paint a complete first frame from the notification payload alone.\n    // Restore the normal authoritative sync owner before exercising self-cancel.\n    await notificationIsolation.stop();\n    notificationIsolation = null;\n\n    const acceptedSyncResponse = playerB.page.waitForResponse(\n      isActionResponse(INVITES_ROUTE, 'sync'),\n      { timeout: 35_000 },\n    );\n    await playerB.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));\n    expect((await acceptedSyncResponse).status()).toBe(200);\n\n    // Third reported symptom: the participant who cancels their own accepted\n    // invite returns to ordinary activity immediately; no local terminal sheet.\n    // Hold only the cancel response; passive sync/watch are normal again.\n    cancelHold = await holdSingleAction(playerB.page, 'cancel');\n    const cancelParticipation = playerB.page.locator(\n      `[data-invite-action=\"cancel\"][data-invite-token=\"${secondInviteToken}\"]`,\n    );\n    await expect(cancelParticipation).toBeVisible();\n    await cancelParticipation.click();\n    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout: 650 });\n    await expect(playerB.page.locator('#sheet')).not.toContainText('Понятно');\n\n    const cancelServer = await cancelHold.serverDone;\n    expect(cancelServer.status).toBe(200);\n    expect(cancelServer.payload?.ok).toBe(true);\n    const cancelResponse = playerB.page.waitForResponse(\n      isActionResponse(INVITES_ROUTE, 'cancel'),\n      { timeout: 35_000 },\n    );\n    cancelHold.release();\n    expect((await cancelResponse).status()).toBe(200);\n    await expect(playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/, { timeout: 2_000 });\n    await cancelHold.stop();\n    cancelHold = null;\n"""
if text.count(old_block) != 1:
    raise SystemExit(f'Expected one self-cancel harness block, found {text.count(old_block)}')
text = text.replace(old_block, new_block, 1)

old_finally = """    if (notificationIsolation) await notificationIsolation.stop().catch(() => null);\n    if (secondInviteToken && playerB?.page && !playerB.page.isClosed()) {\n"""
new_finally = """    if (notificationIsolation) await notificationIsolation.stop().catch(() => null);\n    if (cancelHold) {\n      cancelHold.release();\n      await cancelHold.stop().catch(() => null);\n    }\n    if (secondInviteToken && playerB?.page && !playerB.page.isClosed()) {\n"""
if text.count(old_finally) != 1:
    raise SystemExit(f'Expected one finally harness anchor, found {text.count(old_finally)}')
text = text.replace(old_finally, new_finally, 1)

path.write_text(text, encoding='utf-8')
print('Invite transition regression harness corrected.')
