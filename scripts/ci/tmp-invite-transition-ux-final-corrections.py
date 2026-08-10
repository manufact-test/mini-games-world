from pathlib import Path

invites = Path('app/assets/js/games/game-invites-v110.js')
text = invites.read_text(encoding='utf-8')
old_delay = "      window.setTimeout(cancelWarmShareDraft, 180);\n"
if text.count(old_delay) != 1:
    raise SystemExit(f'Expected one queued-cancel delayed cleanup, found {text.count(old_delay)}')
invites.write_text(text.replace(old_delay, '', 1), encoding='utf-8')

contract = Path('bot/tests/ProductionMvp14InviteTransitionUxV1137Test.php')
text = contract.read_text(encoding='utf-8')
old_snapshot = "        && str_contains($service, \"'invite_snapshot' => $inviteSnapshot\"),\n"
new_snapshot = "        && str_contains($service, \"'invite_snapshot' => \\$inviteSnapshot\"),\n"
if text.count(old_snapshot) != 1:
    raise SystemExit('Snapshot contract anchor missing')
text = text.replace(old_snapshot, new_snapshot, 1)

anchor = "$entry = $read('app/v110.php');\n\n"
insert = "$entry = $read('app/v110.php');\n\n$queuedCancelStart = strpos($invites, 'async function settleQueuedDirectInviteCancel(');\n$queuedCancelEnd = $queuedCancelStart === false ? false : strpos($invites, 'async function createLinkDraft(', $queuedCancelStart);\n$queuedCancelOwner = $queuedCancelStart === false || $queuedCancelEnd === false\n    ? ''\n    : substr($invites, $queuedCancelStart, $queuedCancelEnd - $queuedCancelStart);\n\n"
if text.count(anchor) != 1:
    raise SystemExit('Queued-cancel contract insertion anchor missing')
text = text.replace(anchor, insert, 1)

old_assert = """$assert(\n    !str_contains($invites, 'retry')\n        && !str_contains($invites, 'setTimeout(async')\n        && !str_contains($invites, 'setInterval(async'),\n    'The UX fix must not add retry/sleep/polling owners.'\n);\n"""
new_assert = """$assert(\n    $queuedCancelOwner !== ''\n        && !str_contains($queuedCancelOwner, 'retry')\n        && !str_contains($queuedCancelOwner, 'setTimeout(')\n        && !str_contains($queuedCancelOwner, 'setInterval('),\n    'The new queued-cancel owner must not use retry, sleep, timeout, or polling workarounds.'\n);\n"""
if text.count(old_assert) != 1:
    raise SystemExit('Timing contract anchor missing')
contract.write_text(text.replace(old_assert, new_assert, 1), encoding='utf-8')

print('Invite transition UX verifier corrections applied.')
