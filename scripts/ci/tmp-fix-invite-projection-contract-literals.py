from pathlib import Path

path = Path('bot/tests/StagingTestOnlyInviteOrphanRecoveryContractTest.php')
text = path.read_text(encoding='utf-8')
replacements = [
    (
        'str_contains($service, "if ($candidates === [])")',
        'str_contains($service, "if (\\$candidates === [])")',
    ),
    (
        'str_contains($service, "\'invites\' => ($inviteAudit[\'ok\'] ?? false) === true")',
        'str_contains($service, "\'invites\' => (\\$inviteAudit[\'ok\'] ?? false) === true")',
    ),
]
for old, new in replacements:
    if text.count(old) != 1:
        raise SystemExit(f'expected one contract literal, found {text.count(old)} for {old!r}')
    text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
