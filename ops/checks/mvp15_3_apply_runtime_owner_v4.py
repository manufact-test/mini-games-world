from pathlib import Path
import textwrap

source = Path('.github/workflows/mvp15-3-runtime-owner-patch-v3.yml').read_text()
marker = "          python3 <<'PY'\n"
start = source.index(marker) + len(marker)
end = source.index("\n          PY\n", start)
script = textwrap.dedent(source[start:end])

guard = '''if "['balance_match']" in s or "['balance_gold']" in s:\n    raise SystemExit(p + ': direct legacy balance access remains; use audit helper instead')\n'''
if script.count(guard) != 1:
    raise SystemExit('Could not locate AdminService legacy-access guard in v3 patch')

extra = r'''# Complete the remaining AdminService transition. Old Gold command names and
# historical room labels stay until MVP-15.6, but every monetary write/read is
# now the one canonical balance; legacy source amounts are audit-only metadata.
s = once(s,
    "        $user =& $db['users'][$userId];\n\n        if (empty($order['refund_done'])) {\n",
    "        $user =& $db['users'][$userId];\n        UnifiedBalanceRuntimeState::ensureUser($user);\n\n        if (empty($order['refund_done'])) {\n",
    p + ' refund ensure')
s = s.replace("$user['balance_gold'] = (int)($user['balance_gold'] ?? 0) + $amount;",
              "$user[UnifiedBalanceRuntimeState::FIELD] = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0) + $amount;")
s = s.replace("'balance_after' => (int)($user['balance_gold'] ?? 0),",
              "'balance_after' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0),")

s = once(s,
    "        $users = array_values(array_filter($db['users'] ?? [], fn($user) => !$this->isDevUser($user)));\n        usort($users, fn($a, $b) => (int)($b['balance_gold'] ?? 0) <=> (int)($a['balance_gold'] ?? 0));\n",
    "        $users = array_values(array_filter($db['users'] ?? [], fn($user) => !$this->isDevUser($user)));\n        foreach ($users as &$candidate) UnifiedBalanceRuntimeState::ensureUser($candidate);\n        unset($candidate);\n        usort($users, fn($a, $b) => (int)($b[UnifiedBalanceRuntimeState::FIELD] ?? 0) <=> (int)($a[UnifiedBalanceRuntimeState::FIELD] ?? 0));\n",
    p + ' gold tools canonical sort')
s = s.replace("                $gold = (int)($user['balance_gold'] ?? 0);",
              "                $coins = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);")
s = s.replace("                if ($gold <= 0 && $deposited <= 0 && $shown >= 5) continue;",
              "                if ($coins <= 0 && $deposited <= 0 && $shown >= 5) continue;")
s = s.replace('. " · Gold " . $gold', '. " · MGW Coins " . $coins')
s = s.replace('"\\nИгроки с Gold-балансом:"', '"\\nИгроки с MGW Coins (legacy Gold tool):"')

s = once(s,
    "        $reason = $reason !== '' ? $reason : 'тестовое админское начисление';\n\n        $before = (int)($db['users'][$userId]['balance_gold'] ?? 0);\n",
    "        $reason = $reason !== '' ? $reason : 'тестовое админское начисление';\n\n        UnifiedBalanceRuntimeState::ensureUser($db['users'][$userId]);\n        $before = (int)($db['users'][$userId][UnifiedBalanceRuntimeState::FIELD] ?? 0);\n",
    p + ' gold add ensure')
s = s.replace("        $db['users'][$userId]['balance_gold'] = $before + $amount;",
              "        $db['users'][$userId][UnifiedBalanceRuntimeState::FIELD] = $before + $amount;")
s = s.replace('return "✅ Gold начислен\\n\\n"', 'return "✅ MGW Coins начислены (legacy Gold tool)\\n\\n"')

s = s.replace('$lines[] = "• балансы Match и Gold";',
              '$lines[] = "• единый баланс MGW Coins + legacy breakdown";')

needle = "        $users = array_values(array_filter($db['users'] ?? [], fn($user) => !$this->isDevUser($user)));\n        if (!$users) {\n"
replacement = "        $users = array_values(array_filter($db['users'] ?? [], fn($user) => !$this->isDevUser($user)));\n        foreach ($users as &$candidate) UnifiedBalanceRuntimeState::ensureUser($candidate);\n        unset($candidate);\n        if (!$users) {\n"
s = once(s, needle, replacement, p + ' users canonicalize')
s = s.replace("            if ((int)($user['balance_match'] ?? 0) < 0 || (int)($user['balance_gold'] ?? 0) < 0) {",
              "            if ((int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0) < 0) {")
s = s.replace("                . \"\\nБаланс: Match \" . (int)($user['balance_match'] ?? 0) . \" коинов · Gold \" . (int)($user['balance_gold'] ?? 0) . \" коинов\";",
              "                . \"\\nБаланс: MGW Coins \" . (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0) . \" коинов\";")

s = once(s,
    "        $id = (string)($user['id'] ?? '');\n        $stats = $this->calculatedStatsForUser($db, $id);\n",
    "        UnifiedBalanceRuntimeState::ensureUser($user);\n        $legacy = UnifiedBalanceRuntimeState::legacyBreakdown($user);\n        $id = (string)($user['id'] ?? '');\n        $stats = $this->calculatedStatsForUser($db, $id);\n",
    p + ' user details canonicalize')
old_balances = """        $lines[] = "\\n💰 Балансы";\n        $lines[] = "Match-комната: " . (int)($user['balance_match'] ?? 0) . " коинов";\n        $lines[] = "Gold-комната: " . (int)($user['balance_gold'] ?? 0) . " коинов";"""
new_balances = """        $lines[] = "\\n💰 Баланс";\n        $lines[] = "MGW Coins: " . (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0) . " коинов";\n        $lines[] = "Legacy Match snapshot: " . (int)($legacy['source_balance_match'] ?? 0) . " коинов";\n        $lines[] = "Legacy Gold snapshot: " . (int)($legacy['source_balance_gold'] ?? 0) . " коинов";"""
s = once(s, old_balances, new_balances, p + ' user detail balances')

s = once(s,
    "    private function shopAvailableForAdmin(array $user): int\n    {\n        $balance = (int)($user['balance_gold'] ?? 0);\n",
    "    private function shopAvailableForAdmin(array $user): int\n    {\n        UnifiedBalanceRuntimeState::ensureUser($user);\n        $balance = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);\n",
    p + ' shop available canonical')

'''

script = script.replace(guard, extra + guard, 1)
exec(compile(script, 'mvp15-3-runtime-owner-patch-v4.py', 'exec'))
