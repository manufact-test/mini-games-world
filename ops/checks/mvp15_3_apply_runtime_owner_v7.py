from pathlib import Path

source = Path('ops/checks/mvp15_3_apply_runtime_owner_v4.py').read_text()
needle = "script = script.replace(guard, extra + guard, 1)\nexec(compile(script, 'mvp15-3-runtime-owner-patch-v4.py', 'exec'))\n"
replacement = r'''script = script.replace(guard, extra + guard, 1)
old_allowed = """allowed = {\n    'bot/services/UserService.php',\n    'bot/accounts/LegacyAccountOwnershipLinkService.php',\n    'bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php',\n    'bot/runtime/RuntimePrimaryStagingSyntheticSuite.php',\n}\n"""
new_allowed = """allowed = {\n    'bot/services/UserService.php',\n    'bot/services/AdminService.php',\n    'bot/services/ShopService.php',\n    'bot/accounts/LegacyAccountOwnershipLinkService.php',\n    'bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php',\n    'bot/runtime/RuntimePrimaryStagingSyntheticSuite.php',\n}\n"""
if script.count(old_allowed) != 1:
    raise SystemExit('Could not locate final active-owner allowed set in v3 patch')
script = script.replace(old_allowed, new_allowed, 1)
exec(compile(script, 'mvp15-3-runtime-owner-patch-v7.py', 'exec'))
'''
if source.count(needle) != 1:
    raise SystemExit('Could not locate v4 execution boundary')
source = source.replace(needle, replacement, 1)
exec(compile(source, 'mvp15-3-runtime-owner-wrapper-v7.py', 'exec'))
