#!/usr/bin/env python3
from pathlib import Path
import re

ROOTS = (Path('bot'), Path('app'))
TOKENS = ('balance_match', 'balance_gold')

# Construction-only patch tooling must never survive into a staging PR.
TEMPORARY_PATHS = (
    '.github/workflows/mvp15-3-apply-runtime-owner.yml',
    '.github/workflows/mvp15-3-run-runtime-patch.yml',
    '.github/workflows/mvp15-3-run-runtime-patch-v2.yml',
    '.github/workflows/mvp15-3-runtime-owner-patch-v3.yml',
    '.github/workflows/mvp15-3-runtime-owner-patch-v4.yml',
    '.github/workflows/mvp15-3-runtime-owner-runner.yml',
    'ops/checks/mvp15_3_apply_runtime_owner_v4.py',
    'ops/checks/mvp15_3_apply_runtime_owner_v5.py',
    'ops/checks/mvp15_3_apply_runtime_owner_v6.py',
    'ops/checks/mvp15_3_apply_runtime_owner_v7.py',
)

# These directories intentionally preserve migration, rollback, audit fixtures,
# or tests for the removed legacy currencies. They must not become live owners.
ALLOWED_PREFIXES = (
    'bot/ledger/',
    'bot/economy/',
    'bot/migration/',
    'bot/cutover/',
    'bot/tests/',
    'bot/baseline/',
)

# Exact compatibility/history owners outside those directories.
ALLOWED_FILES = {
    'bot/services/UserService.php',  # frozen source fields only
    'bot/services/AdminService.php',  # legacy labels/audit breakdown only
    'bot/services/ShopService.php',  # temporary response alias only
    'bot/accounts/LegacyAccountOwnershipLinkService.php',
    'bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php',
    'bot/runtime/RuntimePrimaryStagingSyntheticSuite.php',
    'app/runtime/server/session/RuntimeSessionService.php',  # one migration fallback
}

TEXT_SUFFIXES = {'.php', '.js', '.html', '.md', '.sh', '.py'}
violations = []
references = []

for temporary_path in TEMPORARY_PATHS:
    if Path(temporary_path).exists():
        violations.append(f'{temporary_path}: construction-only MVP-15.3 patch tooling must be removed')

for root in ROOTS:
    for path in root.rglob('*'):
        if not path.is_file() or path.suffix not in TEXT_SUFFIXES:
            continue
        body = path.read_text(errors='ignore')
        if not any(token in body for token in TOKENS):
            continue
        name = path.as_posix()
        references.append(name)
        if name.startswith(ALLOWED_PREFIXES) or name in ALLOWED_FILES:
            continue
        violations.append(f'{name}: legacy balance token exists outside the explicit audit/compatibility allowlist')

# Live user-array writes/reads are forbidden in Admin/Shop. Those files may use
# the words only as compatibility response labels or legacy audit descriptions.
for name in ('bot/services/AdminService.php', 'bot/services/ShopService.php'):
    body = Path(name).read_text()
    for token in TOKENS:
        direct_patterns = [
            rf"\$user\s*\[\s*['\"]{token}['\"]\s*\]",
            rf"\$db\s*\[\s*['\"]users['\"]\s*\].*?\[\s*['\"]{token}['\"]\s*\]",
        ]
        for pattern in direct_patterns:
            if re.search(pattern, body, flags=re.S):
                violations.append(f'{name}: direct live {token} access is forbidden')
                break

# UserService is the explicit legacy-source capture point. It may expose the old
# fields for rollback/audit, but canonical public/runtime money must be present.
user_service = Path('bot/services/UserService.php').read_text()
if "'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0)" not in user_service:
    violations.append('bot/services/UserService.php: canonical public balance projection is missing')
if '$balance = max(0, (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0));' not in user_service:
    violations.append('bot/services/UserService.php: shop availability is not based on canonical balance')

# Clean runtime may keep exactly one old Match fallback to consume a pre-15.3
# snapshot, but it must never write/read Gold and all live state must be balance.
session = Path('app/runtime/server/session/RuntimeSessionService.php').read_text()
if session.count('balance_match') != 1:
    violations.append('RuntimeSessionService.php: expected exactly one pre-15.3 balance_match migration fallback')
if 'balance_gold' in session:
    violations.append('RuntimeSessionService.php: balance_gold is forbidden in clean runtime')
if "'balance' => max(0, (int)($existing['balance'] ?? $existing['balance_match']" not in session:
    violations.append('RuntimeSessionService.php: canonical balance migration fallback is missing')

# Shared live money writers must not mention either old field at all.
for name in (
    'bot/services/GameService.php',
    'bot/services/GameSettlementService.php',
    'bot/services/WeeklyMatchEconomyService.php',
    'bot/services/PaymentService.php',
    'bot/services/invites/GameInviteStorageTrait.php',
    'bot/services/invites/GameInviteValidationTrait.php',
    'bot/services/StagingTestPlayerStateResetService.php',
    'app/runtime/server/match/RuntimeMatchService.php',
):
    body = Path(name).read_text()
    for token in TOKENS:
        if token in body:
            violations.append(f'{name}: live shared writer still references {token}')

# Visible current UI must never read the two legacy balances.
for name in (
    'app/assets/js/ui.js',
    'app/assets/js/screens/home-screen.js',
    'app/assets/js/screens/profile-screen-v110.js',
    'app/assets/js/screens/profile-screen.js',
    'app/assets/js/screens/store-screen.js',
    'app/index.html',
):
    body = Path(name).read_text()
    for token in TOKENS:
        if token in body:
            violations.append(f'{name}: visible UI still references {token}')

if violations:
    print('MVP-15.3 unified balance owner check FAILED')
    for violation in violations:
        print(' - ' + violation)
    raise SystemExit(1)

print('MVP-15.3 unified balance owner check: PASS')
print(f'Legacy token references are confined to {len(set(references))} explicit audit/compatibility files or directories.')
print('Temporary patch tooling: absent.')
