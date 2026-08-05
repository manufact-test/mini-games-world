from pathlib import Path


def replace_once(path: str, old: str, new: str, label: str) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 occurrence, found {count}')
    target.write_text(text.replace(old, new, 1), encoding='utf-8')


notifications = 'app/assets/js/screens/notifications-screen-v99.js'
replace_once(
    notifications,
    "  document.addEventListener('click', handleNotificationActivation);",
    "  document.addEventListener('click', handleNotificationBellActivation, true);\n  document.addEventListener('click', handleNotificationToastActivation);",
    'notification listener split',
)
replace_once(
    notifications,
    """function handleNotificationActivation(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const trigger = origin.closest('#notificationsOpen, #notificationToast');
  if (!(trigger instanceof HTMLElement)) return;
  if (trigger.id === 'notificationToast' && !trigger.classList.contains('show')) return;
  if (trigger.id === 'notificationToast' && Date.now() < suppressNotificationToastClickUntil) return;

  const seedItems = trigger.id === 'notificationToast' && activeToastNotification
    ? [activeToastNotification]
    : [];
  event.preventDefault();
  event.stopPropagation();
  dismissNotificationToast();
  loadNotificationsSheet({ hapticFeedback:true, keepShell:false, seedItems });
}
""",
    """function handleNotificationBellActivation(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const trigger = origin.closest('#notificationsOpen');
  if (!(trigger instanceof HTMLElement)) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  loadNotificationsSheet({ hapticFeedback:true, keepShell:false, seedItems:[] });
}

function handleNotificationToastActivation(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const trigger = origin.closest('#notificationToast');
  if (!(trigger instanceof HTMLElement) || !trigger.classList.contains('show')) return;
  if (Date.now() < suppressNotificationToastClickUntil) return;

  const seedItems = activeToastNotification ? [activeToastNotification] : [];
  event.preventDefault();
  event.stopPropagation();
  dismissNotificationToast();
  loadNotificationsSheet({ hapticFeedback:true, keepShell:false, seedItems });
}
""",
    'notification handler split',
)

replace_once(
    'app/assets/js/screens/home-screen.js',
    "    if (target.id === 'notificationsOpen') return toast('Уведомлений пока нет.');\n",
    '',
    'retired home-screen bell owner',
)

replace_once(
    'app/assets/js/main.js',
    "window.__MGW_BUILD__ = 'd1-canonical-toast-seed';",
    "window.__MGW_BUILD__ = 'd1-bell-single-owner';",
    'main build marker',
)
replace_once(
    'app/assets/js/main.js',
    "./screens/notifications-screen-v99.js?v=d1-canonical-toast-seed",
    "./screens/notifications-screen-v99.js?v=d1-bell-single-owner",
    'notification cache identity',
)

entry = Path('app/v114.php')
entry_text = entry.read_text(encoding='utf-8')
if entry_text.count('./assets/js/main.js?v=d1-canonical-toast-seed') != 2:
    raise SystemExit('entry main cache identity count mismatch')
entry_text = entry_text.replace(
    './assets/js/main.js?v=d1-canonical-toast-seed',
    './assets/js/main.js?v=d1-bell-single-owner',
)
entry_text = entry_text.replace(
    "'data-hotfix-build=\"d1-canonical-toast-seed\"'",
    "'data-hotfix-build=\"d1-bell-single-owner\"'",
)
entry_text = entry_text.replace(
    'X-MGW-Frontend-Build: d1-canonical-toast-seed',
    'X-MGW-Frontend-Build: d1-bell-single-owner',
)
entry.write_text(entry_text, encoding='utf-8')

contract = Path('bot/tests/ProductionMvp14D1CanonicalOwnersArchitectureTest.php')
contract_text = contract.read_text(encoding='utf-8')
replace_anchor = "$notifications = $read('app/assets/js/screens/notifications-screen-v99.js');"
if replace_anchor not in contract_text:
    raise SystemExit('home architecture source anchor missing')
contract_text = contract_text.replace(
    replace_anchor,
    replace_anchor + "\n$home = $read('app/assets/js/screens/home-screen.js');",
    1,
)
contract_text = contract_text.replace('d1-canonical-toast-seed', 'd1-bell-single-owner')
old_assertion = """$assert(substr_count($notifications, \"document.addEventListener('click', handleNotificationActivation)\") === 1
    && !str_contains($notifications, \"window.addEventListener('pointerdown'\")"""
new_assertion = """$assert(substr_count($notifications, \"document.addEventListener('click', handleNotificationBellActivation, true)\") === 1
    && substr_count($notifications, \"document.addEventListener('click', handleNotificationToastActivation)\") === 1
    && !str_contains($notifications, 'handleNotificationActivation')
    && !str_contains($home, \"target.id === 'notificationsOpen'\")
    && !str_contains($notifications, \"window.addEventListener('pointerdown'\")"""
if old_assertion not in contract_text:
    raise SystemExit('architecture assertion anchor missing')
contract_text = contract_text.replace(old_assertion, new_assertion, 1)
contract.write_text(contract_text, encoding='utf-8')
