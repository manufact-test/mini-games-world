from pathlib import Path

path = Path('bot/tests/StagingTestPlayerResetStageDiagnosticContractTest.php')
text = path.read_text(encoding='utf-8')
old = '''$assert(str_contains($service, "parent::__construct('Staging test-player reset stage failed.', 0, $previous);"),'''
new = '''$assert(str_contains($service, "parent::__construct('Staging test-player reset stage failed.', 0, \\$previous);"),'''
if text.count(old) != 1:
    raise SystemExit(f'expected one contract literal, found {text.count(old)}')
path.write_text(text.replace(old, new, 1), encoding='utf-8')
