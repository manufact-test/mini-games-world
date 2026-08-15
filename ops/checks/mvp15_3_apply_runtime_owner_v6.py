from pathlib import Path

source = Path('ops/checks/mvp15_3_apply_runtime_owner_v4.py').read_text()
needle = "script = script.replace(guard, extra + guard, 1)\nexec(compile(script, 'mvp15-3-runtime-owner-patch-v4.py', 'exec'))\n"
replacement = r'''script = script.replace(guard, extra + guard, 1)
old_allow = "if str(path) in {'bot/services/UserService.php','bot/services/PaymentService.php'}:"
new_allow = "if str(path) in {'bot/services/UserService.php','bot/services/PaymentService.php','bot/services/AdminService.php','bot/services/ShopService.php'}:"
if script.count(old_allow) != 1:
    raise SystemExit('Could not locate final active-owner allowlist in v3 patch')
script = script.replace(old_allow, new_allow, 1)
exec(compile(script, 'mvp15-3-runtime-owner-patch-v6.py', 'exec'))
'''
if source.count(needle) != 1:
    raise SystemExit('Could not locate v4 execution boundary')
source = source.replace(needle, replacement, 1)
exec(compile(source, 'mvp15-3-runtime-owner-wrapper-v6.py', 'exec'))
