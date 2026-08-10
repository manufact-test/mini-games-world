from pathlib import Path

path = Path('bot/tests/StagingTestPlayerResetStageDiagnosticContractTest.php')
text = path.read_text(encoding='utf-8')
replacements = [
    (
        '''$assert(str_contains($service, "parent::__construct('Staging test-player reset stage failed.', 0, $previous);"),''',
        '''$assert(str_contains($service, "parent::__construct('Staging test-player reset stage failed.', 0, \\$previous);"),''',
    ),
    (
        '''&& str_contains($endpoint, "'stage' => $error->stage()"),''',
        '''&& str_contains($endpoint, "'stage' => \\$error->stage()"),''',
    ),
    (
        '''$assert(str_contains($endpoint, "error_log('[MiniGamesWorld staging test reset] failed stage=' . $error->stage());"),''',
        '''$assert(str_contains($endpoint, "error_log('[MiniGamesWorld staging test reset] failed stage=' . \\$error->stage());"),''',
    ),
]
for old, new in replacements:
    if text.count(old) != 1:
        raise SystemExit(f'expected one contract literal, found {text.count(old)} for {old!r}')
    text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
