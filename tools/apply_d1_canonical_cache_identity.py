from pathlib import Path

OLD_BUILD = 'd1-canonical-owners'
NEW_BUILD = 'd1-canonical-toast-seed'
OLD_MAIN = './assets/js/main.js?v=d1'
NEW_MAIN = './assets/js/main.js?v=d1-canonical-toast-seed'
OLD_NOTIFICATIONS = './screens/notifications-screen-v99.js?v=d1'
NEW_NOTIFICATIONS = './screens/notifications-screen-v99.js?v=d1-canonical-toast-seed'


def replace_exact(path: str, replacements: list[tuple[str, str]]) -> None:
    target = Path(path)
    text = target.read_text(encoding='utf-8')
    for old, new in replacements:
        if old not in text:
            raise SystemExit(f'Missing replacement token in {path}: {old}')
        text = text.replace(old, new)
    target.write_text(text, encoding='utf-8')


replace_exact('app/assets/js/main.js', [
    (f"window.__MGW_BUILD__ = '{OLD_BUILD}';", f"window.__MGW_BUILD__ = '{NEW_BUILD}';"),
    (OLD_NOTIFICATIONS, NEW_NOTIFICATIONS),
])

replace_exact('app/v114.php', [
    (f'data-hotfix-build="{OLD_BUILD}"', f'data-hotfix-build="{NEW_BUILD}"'),
    (OLD_MAIN, NEW_MAIN),
    (f'X-MGW-Frontend-Build: {OLD_BUILD}', f'X-MGW-Frontend-Build: {NEW_BUILD}'),
])

replace_exact('bot/tests/ProductionMvp14D1CanonicalOwnersArchitectureTest.php', [
    (OLD_MAIN, NEW_MAIN),
    (f'X-MGW-Frontend-Build: {OLD_BUILD}', f'X-MGW-Frontend-Build: {NEW_BUILD}'),
    (OLD_NOTIFICATIONS, NEW_NOTIFICATIONS),
])

replace_exact('bot/tests/ProductionMvp14R13MainEntryCacheBustTest.php', [
    (OLD_MAIN, NEW_MAIN),
    (OLD_BUILD, NEW_BUILD),
])

replace_exact('e2e/staging/frontend-immutable-core.spec.mjs', [
    (f"const EXPECTED_BUILD = '{OLD_BUILD}';", f"const EXPECTED_BUILD = '{NEW_BUILD}';"),
    ("'/assets/js/main.js?v=d1'", "'/assets/js/main.js?v=d1-canonical-toast-seed'"),
    ("'/assets/js/screens/notifications-screen-v99.js?v=d1'", "'/assets/js/screens/notifications-screen-v99.js?v=d1-canonical-toast-seed'"),
])

main = Path('app/assets/js/main.js').read_text(encoding='utf-8')
entry = Path('app/v114.php').read_text(encoding='utf-8')
assert NEW_MAIN in entry and OLD_MAIN not in entry
assert NEW_NOTIFICATIONS in main and OLD_NOTIFICATIONS not in main
assert f"window.__MGW_BUILD__ = '{NEW_BUILD}';" in main
assert f'X-MGW-Frontend-Build: {NEW_BUILD}' in entry
print('Canonical cache identity updated without adding a new owner or hotfix asset.')
