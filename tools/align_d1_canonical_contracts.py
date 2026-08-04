from pathlib import Path

OLD_BUILD = 'd1-canonical-owners'
NEW_BUILD = 'd1-canonical-toast-seed'


def replace_once(path: str, old: str, new: str, label: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'Missing {label} in {path}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


for path in [
    'bot/tests/ProductionAvatarInviteRegressionHotfixTest.php',
    'bot/tests/ProductionHotPathLatencyFixContractTest.php',
    'bot/tests/ProductionV96RootCauseStabilizationTest.php',
]:
    replace_once(path, OLD_BUILD, NEW_BUILD, 'canonical build marker')

for path in [
    'bot/tests/ProductionMvp14DeferredNotificationFirstFrameE2ETest.php',
    'bot/tests/ProductionMvp14R13R11LiveAcceptanceTest.php',
]:
    replace_once(
        path,
        "&& str_contains($notifications, 'loadNotificationsSheet({ hapticFeedback:true, keepShell:false });'),",
        "&& str_contains($notifications, \"const seedItems = trigger.id === 'notificationToast' && activeToastNotification\")\n"
        "    && str_contains($notifications, 'loadNotificationsSheet({ hapticFeedback:true, keepShell:false, seedItems });'),",
        'canonical toast seed contract',
    )

replace_once(
    'bot/tests/ProductionMvp14D1FollowupStressE2ETest.php',
    '''$assert(substr_count($spec, "test('D1 follow-up:") === 4,
    'The live suite must contain four focused follow-up stress scenarios.');''',
    '''$assert(substr_count($spec, "test('D1 follow-up:") === 3
        && str_contains($spec, "test('canonical desktop picker renders empty only after one authoritative response'"),
    'The live suite must contain three notification follow-ups and one canonical picker scenario.');''',
    'follow-up scenario count',
)
replace_once(
    'bot/tests/ProductionMvp14D1FollowupStressE2ETest.php',
    '''$assert(str_contains($spec, 'if (stressCalls <= 4)')
        && str_contains($spec, 'expect(stressCalls).toBeGreaterThanOrEqual(5)')
        && str_contains($spec, 'Недавних соперников пока нет'),
    'Opponent bots must inject multiple transient empty snapshots before the real list.');''',
    '''$assert(str_contains($spec, "test('canonical desktop picker renders empty only after one authoritative response'")
        && str_contains($spec, 'expect(opponentCalls).toBe(0)')
        && str_contains($spec, 'data-player-picker-state="loading"')
        && str_contains($spec, 'data-player-picker-state="empty"')
        && str_contains($spec, 'expect(opponentCalls).toBe(1)'),
    'Opponent automation must prove no boot fetch and one authoritative manual empty response.');''',
    'canonical picker stress contract',
)

replace_once(
    'bot/tests/ProductionMvp14D1StressObserverV118Test.php',
    r'''$assert(str_contains($stress, "frame.includes('Недавних соперников пока нет')")
        && str_contains($stress, 'expect(stressCalls).toBeGreaterThanOrEqual(5)')
        && str_contains($stress, "data-direct-opponent=\"stg_test_player_b\""),
    'The opponent stress scenario must reject false empty state, survive five responses and render the real player.');''',
    '''$assert(str_contains($stress, "test('canonical desktop picker renders empty only after one authoritative response'")
        && str_contains($stress, 'expect(opponentCalls).toBe(0)')
        && str_contains($stress, 'data-player-picker-state="loading"')
        && str_contains($stress, 'data-player-picker-state="empty"')
        && str_contains($stress, 'expect(opponentCalls).toBe(1)'),
    'The canonical opponent scenario must prove loading before one authoritative empty response and no boot request.');''',
    'canonical opponent observer contract',
)

checks = {
    'bot/tests/ProductionAvatarInviteRegressionHotfixTest.php': NEW_BUILD,
    'bot/tests/ProductionHotPathLatencyFixContractTest.php': NEW_BUILD,
    'bot/tests/ProductionV96RootCauseStabilizationTest.php': NEW_BUILD,
    'bot/tests/ProductionMvp14DeferredNotificationFirstFrameE2ETest.php': 'keepShell:false, seedItems',
    'bot/tests/ProductionMvp14R13R11LiveAcceptanceTest.php': 'keepShell:false, seedItems',
    'bot/tests/ProductionMvp14D1FollowupStressE2ETest.php': 'one authoritative manual empty response',
    'bot/tests/ProductionMvp14D1StressObserverV118Test.php': 'no boot request',
}
for path, token in checks.items():
    if token not in Path(path).read_text(encoding='utf-8'):
        raise SystemExit(f'Contract alignment missing {token!r} in {path}')
print('Aligned seven historical contracts with the canonical D1 graph.')
