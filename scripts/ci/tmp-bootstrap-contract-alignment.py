from pathlib import Path

notification = Path('e2e/staging/deferred-notification-first-frame.spec.mjs')
text = notification.read_text(encoding='utf-8')
old = """  await page.waitForFunction(() => window.__MGW_FIRST_INTERACTION_READY__ !== undefined, null, {\n    timeout: 25_000,\n  });\n"""
new = """  await expect(page.locator('#preloader')).toBeHidden({ timeout: 20_000 });\n  await page.waitForFunction(() => (\n    String(localStorage.getItem('mgw_device_session_id') || '').length > 0\n      && String(localStorage.getItem('mgw_device_id') || '').length > 0\n  ), null, { timeout: 20_000 });\n"""
if text.count(old) != 1:
    raise SystemExit(f'Expected exactly one historical readiness marker wait, found {text.count(old)}')
notification.write_text(text.replace(old, new, 1), encoding='utf-8')

immutable = Path('e2e/staging/frontend-immutable-core.spec.mjs')
text = immutable.read_text(encoding='utf-8')
replacements = {
    "const EXPECTED_BUILD = 'd1-bell-single-owner';": "const EXPECTED_BUILD = 'd1-bootstrap-authoritative-owner';",
    "'/assets/js/main.js?v=d1-bell-single-owner',": "'/assets/js/main.js?v=d1-bootstrap-authoritative-owner',",
}
for old_value, new_value in replacements.items():
    if text.count(old_value) != 1:
        raise SystemExit(f'Expected exactly one immutable contract anchor: {old_value}')
    text = text.replace(old_value, new_value, 1)
immutable.write_text(text, encoding='utf-8')

print('Bootstrap E2E contract alignment applied.')
