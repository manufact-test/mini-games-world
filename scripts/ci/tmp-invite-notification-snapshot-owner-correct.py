from pathlib import Path

path = Path('bot/tests/ProductionMvp14InviteTransitionUxV1137Test.php')
text = path.read_text(encoding='utf-8')
old = '        && str_contains($notificationEndpoint, "$item[\'invite_snapshot\'] = $inviteViews->notificationSnapshot($invite, $userId);"),\n'
new = '        && str_contains($notificationEndpoint, "\\$item[\'invite_snapshot\'] = \\$inviteViews->notificationSnapshot(\\$invite, \\$userId);"),\n'
if text.count(old) != 1:
    raise SystemExit(f'Expected one notification endpoint contract line, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
print('Notification snapshot source contract escaping corrected.')
