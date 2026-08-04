from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f'Missing {label}: {old[:140]!r}')
    return text.replace(old, new, 1)


path = Path('app/assets/js/screens/notifications-screen-v99.js')
text = path.read_text()
text = replace_once(
    text,
    "let notificationToastPointer = null;\nlet suppressNotificationToastClickUntil = 0;",
    "let notificationToastPointer = null;\nlet notificationToastGeneration = 0;\nlet suppressNotificationToastClickUntil = 0;",
    'toast generation state',
)
text = replace_once(
    text,
    """  window.clearTimeout(notificationToastTimer);
  notificationToastPointer = null;
  activeToastNotification = item;
  el.className = `notification-toast ${tone}`;""",
    """  window.clearTimeout(notificationToastTimer);
  const generation = ++notificationToastGeneration;
  notificationToastPointer = null;
  activeToastNotification = item;
  el.className = `notification-toast ${tone}`;""",
    'toast generation capture',
)
text = replace_once(
    text,
    "requestAnimationFrame(() => el.classList.add('show'));",
    """requestAnimationFrame(() => {
    if (generation !== notificationToastGeneration || activeToastNotification !== item) return;
    el.classList.add('show');
  });""",
    'cancelable toast frame',
)
text = replace_once(
    text,
    """  window.clearTimeout(notificationToastTimer);
  notificationToastTimer = null;
  notificationToastPointer = null;
  activeToastNotification = null;""",
    """  window.clearTimeout(notificationToastTimer);
  notificationToastTimer = null;
  notificationToastGeneration += 1;
  notificationToastPointer = null;
  activeToastNotification = null;""",
    'toast dismissal generation invalidation',
)
for required in [
    'let notificationToastGeneration = 0;',
    'const generation = ++notificationToastGeneration;',
    'generation !== notificationToastGeneration',
    'notificationToastGeneration += 1;',
]:
    if required not in text:
        raise SystemExit(f'Missing canonical toast state token: {required}')
path.write_text(text)

contract_path = Path('bot/tests/ProductionMvp14D1CanonicalOwnersArchitectureTest.php')
contract = contract_path.read_text()
old = """    && str_contains($notifications, 'let sheetGeneration = 0')
    && str_contains($notifications, 'openNotificationsShell()')"""
new = """    && str_contains($notifications, 'let sheetGeneration = 0')
    && str_contains($notifications, 'let notificationToastGeneration = 0')
    && str_contains($notifications, 'generation !== notificationToastGeneration')
    && str_contains($notifications, 'notificationToastGeneration += 1')
    && str_contains($notifications, 'openNotificationsShell()')"""
contract = replace_once(contract, old, new, 'canonical toast generation contract')
contract_path.write_text(contract)
