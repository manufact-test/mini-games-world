#!/usr/bin/env python3
from pathlib import Path
import json
import re
import subprocess

ROOTS = (Path('bot'), Path('app'))
TOKENS = ('balance_match', 'balance_gold')
TEMPORARY_PATHS = (
    '.github/workflows/mvp15-3-apply-runtime-owner.yml',
    '.github/workflows/mvp15-3-run-runtime-patch.yml',
    '.github/workflows/mvp15-3-run-runtime-patch-v2.yml',
    '.github/workflows/mvp15-3-runtime-owner-patch-v3.yml',
    '.github/workflows/mvp15-3-runtime-owner-patch-v4.yml',
    '.github/workflows/mvp15-3-runtime-owner-runner.yml',
    '.github/workflows/mvp15-3-real-telegram-entry-patch.yml',
    'ops/checks/mvp15_3_apply_runtime_owner_v4.py',
    'ops/checks/mvp15_3_apply_runtime_owner_v5.py',
    'ops/checks/mvp15_3_apply_runtime_owner_v6.py',
    'ops/checks/mvp15_3_apply_runtime_owner_v7.py',
)
ALLOWED_PREFIXES = ('bot/ledger/','bot/economy/','bot/migration/','bot/cutover/','bot/tests/','bot/baseline/')
ALLOWED_FILES = {
    'bot/services/UserService.php','bot/services/AdminService.php','bot/services/ShopService.php',
    'bot/accounts/LegacyAccountOwnershipLinkService.php','bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php',
    'bot/runtime/RuntimePrimaryStagingSyntheticSuite.php','app/runtime/server/session/RuntimeSessionService.php',
}
TEXT_SUFFIXES = {'.php','.js','.html','.md','.sh','.py'}
violations = []
references = []

for temporary_path in TEMPORARY_PATHS:
    if Path(temporary_path).exists(): violations.append(f'{temporary_path}: construction-only MVP-15.3 patch tooling must be removed')
for root in ROOTS:
    for path in root.rglob('*'):
        if not path.is_file() or path.suffix not in TEXT_SUFFIXES: continue
        body = path.read_text(errors='ignore')
        if not any(token in body for token in TOKENS): continue
        name = path.as_posix(); references.append(name)
        if name.startswith(ALLOWED_PREFIXES) or name in ALLOWED_FILES: continue
        violations.append(f'{name}: legacy balance token exists outside the explicit audit/compatibility allowlist')

for name in ('bot/services/AdminService.php','bot/services/ShopService.php'):
    body = Path(name).read_text()
    for token in TOKENS:
        for pattern in [rf"\$user\s*\[\s*['\"]{token}['\"]\s*\]", rf"\$db\s*\[\s*['\"]users['\"]\s*\].*?\[\s*['\"]{token}['\"]\s*\]"]:
            if re.search(pattern, body, flags=re.S): violations.append(f'{name}: direct live {token} access is forbidden'); break

user_service = Path('bot/services/UserService.php').read_text()
if "'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0)" not in user_service: violations.append('bot/services/UserService.php: canonical public balance projection is missing')
if '$balance = max(0, (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0));' not in user_service: violations.append('bot/services/UserService.php: shop availability is not based on canonical balance')

session = Path('app/runtime/server/session/RuntimeSessionService.php').read_text()
if session.count('balance_match') != 1: violations.append('RuntimeSessionService.php: expected exactly one pre-15.3 balance_match migration fallback')
if 'balance_gold' in session: violations.append('RuntimeSessionService.php: balance_gold is forbidden in clean runtime')
if "'balance' => max(0, (int)($existing['balance'] ?? $existing['balance_match']" not in session: violations.append('RuntimeSessionService.php: canonical balance migration fallback is missing')

for name in ('bot/services/GameService.php','bot/services/GameSettlementService.php','bot/services/WeeklyMatchEconomyService.php','bot/services/PaymentService.php','bot/services/invites/GameInviteStorageTrait.php','bot/services/invites/GameInviteValidationTrait.php','bot/services/StagingTestPlayerStateResetService.php','app/runtime/server/match/RuntimeMatchService.php'):
    body = Path(name).read_text()
    for token in TOKENS:
        if token in body: violations.append(f'{name}: live shared writer still references {token}')

for name in ('app/assets/js/ui.js','app/assets/js/screens/home-screen.js','app/assets/js/screens/profile-screen-v110.js','app/assets/js/screens/profile-screen.js','app/assets/js/screens/store-screen.js','app/index.html'):
    body = Path(name).read_text()
    for token in TOKENS:
        if token in body: violations.append(f'{name}: visible UI still references {token}')

index_ui = Path('app/index.html').read_text()
profile_ui = Path('app/assets/js/screens/profile-screen-v110.js').read_text()
ru_catalog = json.loads(Path('app/locales/ru.json').read_text())
if '<div class="balance-note">Ваш баланс коинов.</div>' not in index_ui:
    violations.append('app/index.html: Home unified balance note must say "Ваш баланс коинов."')
if ru_catalog.get('profile', {}).get('balance_note') != 'Ваш баланс коинов.':
    violations.append('app/locales/ru.json: profile balance_note must say "Ваш баланс коинов."')
if "t('profile.balance_note')" not in profile_ui:
    violations.append('profile-screen-v110.js: profile wallet note must use localized canonical balance_note')
if 'MGW Coins</span>' in profile_ui:
    violations.append('profile-screen-v110.js: duplicate MGW Coins label must not be visible in profile wallet')

expected_launch = "private const ENTRY_PATH = '/app/v110.php?v=1127';"
launch_owner = Path('bot/helpers/WebAppLaunchUrl.php').read_text()
if expected_launch not in launch_owner: violations.append('WebAppLaunchUrl.php: canonical Telegram entry cache key is not v110.php?v=1127')

try:
    rendered = subprocess.run(['php','app/v110.php'], check=True, capture_output=True, text=True, timeout=10).stdout
except (subprocess.CalledProcessError, subprocess.TimeoutExpired, FileNotFoundError) as exc:
    violations.append(f'app/v110.php: canonical Telegram entry render failed: {exc}'); rendered = ''

required_entry_fragments = (
    './assets/js/app-bootstrap-v2.js?v=2&mvp16=version-manifest',
    './assets/js/production-clean-entry-v110.js?v=1124&clock=single-writer&release=battleship-action-quarantine',
    './assets/js/main-v110.js?v=1139&ux=1&sk=3&icons=c1efd5af&render=5&mvp15=unified-balance',
    './assets/css/main.css?v=168&sk=3&icons=c1efd5af&render=36&mvp16=profile-corrective',
    './assets/js/ui.js?v=93&mvp16=canonical-identity',
    './assets/js/state.js?v=30&mvp16=router-lifecycle',
    './assets/js/router.js?v=29&b=871cb833d99d&mvp16=route-registry',
    './assets/js/screens/home-screen.js?v=79&mvp16=settings-language',
    './assets/js/screens/profile-screen-v110.js?v=1116&mvp16=canonical-identity',
)
for fragment in required_entry_fragments:
    if fragment not in rendered: violations.append(f'app/v110.php: transformed Telegram entry is missing {fragment}')

if rendered.count('<script type="module" src="') != 1: violations.append('app/v110.php: canonical Telegram entry must expose exactly one top-level module bootstrap')
if '<script type="module" src="./assets/js/production-clean-entry-v110.js' in rendered: violations.append('app/v110.php: clean-entry must not remain an independent top-level module script')
if '<script type="module" src="./assets/js/main-v110.js' in rendered: violations.append('app/v110.php: main-v110 must not remain an independent top-level module script')
if './assets/js/main.js?v=98.4-wallet-15-3' in rendered: violations.append('app/v110.php: generic main.js survived the canonical Telegram transform')
if 'Mini Games World v110 source anchor is unavailable:' in rendered: violations.append('app/v110.php: source-anchor fail-closed response leaked into rendered entry')
if 'Mini Games World v110 transformed target is unavailable:' in rendered: violations.append('app/v110.php: transformed-target fail-closed response leaked into rendered entry')

if violations:
    print('MVP-15.3 unified balance owner check FAILED')
    for violation in violations: print(' - ' + violation)
    raise SystemExit(1)

print('MVP-15.3 unified balance owner check: PASS')
print(f'Legacy token references are confined to {len(set(references))} explicit audit/compatibility files or directories.')
print('Canonical Telegram /start entry: v110 single-bootstrap manifest successor graph PASS.')
print('Final Home/Profile unified balance copy: PASS.')
print('Temporary patch tooling: absent.')
