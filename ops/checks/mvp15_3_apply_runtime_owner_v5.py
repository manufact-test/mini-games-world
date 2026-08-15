from pathlib import Path

source = Path('ops/checks/mvp15_3_apply_runtime_owner_v4.py').read_text()
needle = "script = script.replace(guard, extra + guard, 1)\nexec(compile(script, 'mvp15-3-runtime-owner-patch-v4.py', 'exec'))\n"
inject = r'''script = script.replace(guard, extra + guard, 1)
old_final = """if unexpected:\n    raise SystemExit('Unexpected active legacy balance references remain: ' + ', '.join(sorted(unexpected)))\n"""
new_final = """if unexpected:\n    details = []\n    for candidate_name in sorted(unexpected):\n        for line_number, line in enumerate(Path(candidate_name).read_text(errors='ignore').splitlines(), 1):\n            if 'balance_match' in line or 'balance_gold' in line:\n                details.append(f'{candidate_name}:{line_number}: {line.strip()}')\n    raise SystemExit('Unexpected active legacy balance references remain:\\n' + '\\n'.join(details))\n"""
if script.count(old_final) != 1:
    raise SystemExit('Could not locate final active-owner scan in v3 patch')
script = script.replace(old_final, new_final, 1)
exec(compile(script, 'mvp15-3-runtime-owner-patch-v5.py', 'exec'))
'''
if source.count(needle) != 1:
    raise SystemExit('Could not locate v4 execution boundary')
source = source.replace(needle, inject, 1)
exec(compile(source, 'mvp15-3-runtime-owner-wrapper-v5.py', 'exec'))
