from pathlib import Path

ROADMAP = Path('docs/MVP-14R-RELATIONAL-MYSQL-REBUILD-ROADMAP.md')
EXPORT = Path('MGW_MASTER_MVP_ROADMAP_V2_UPDATED_2026-08-04_ARCHITECTURE_REBUILD.md')
text = ROADMAP.read_text(encoding='utf-8')
replacements = {
    '## AUTHORITATIVE CHECKPOINT — 2026-08-04 22:40 (+03:00)': '## AUTHORITATIVE CHECKPOINT — 2026-08-04 22:50 (+03:00)',
    'LATEST STAGING E2E: RUN 30943775973 — DEPLOY/TEST IN PROGRESS AT CHECKPOINT TIME': 'LATEST STAGING E2E: RUN 30943775973 — APPLICATION FAILURE, 19/20',
    'NEXT ACTION: FINISH THE APPLICABLE STAGING RUN, THEN STOP FOR REAL TELEGRAM DESKTOP/MOBILE ACCEPTANCE': 'NEXT ACTION: FIX THE REMAINING AUTOMATABLE NOTIFICATION OWNER FAILURE; DO NOT START MANUAL GATE YET',
    '**Статус:** REPOSITORY CI COMPLETE; STAGING E2E FINAL RUN IN PROGRESS AT CHECKPOINT; MANUAL GATE PENDING.': '**Статус:** REPOSITORY CI COMPLETE; STAGING E2E FAILED 19/20; CANONICAL NOTIFICATION OWNER FIX REQUIRED; MANUAL GATE BLOCKED.',
    '| `30943775973` | in progress at checkpoint | validates generation-safe canonical toast on exact staging SHA `76e25cd…` |': '| `30943775973` | 19/20, application failure | generation guard did not resolve cached-toast actionable-card opening |',
    '1. Finish staging E2E run 30943775973 for exact SHA 76e25cd34ceb53259b27fd26689d04d0ea16ef72.\n2. If an automatable scenario fails, inspect and correct the canonical owner only; do not restore guards/wrappers.\n3. If the applicable automated scope passes, do not run more bots for Telegram-only properties.\n4. Stop for mandatory seven-step real-device acceptance on computer and phone.': '1. Investigate the remaining failed scenario from run 30943775973 inside the canonical notification owner.\n2. Correct the owner state transfer; do not restore guards/wrappers or add another owner.\n3. Run the single focused cached-toast scenario first.\n4. Run the relevant staging suite once only after that focused scenario passes.\n5. If the applicable automated scope passes, do not run more bots for Telegram-only properties.\n6. Stop for mandatory seven-step real-device acceptance on computer and phone.',
    '5. Record each manual result in this roadmap: PASS/FAIL plus exact reproduction.\n6. If any manual item fails, reopen only the owning canonical module and preserve the owner map.\n7. Mark MVP-14 D1 accepted only after all manual items pass.\n8. Continue the next roadmap block only after manual acceptance.\n9. Do not touch main/production without explicit authorization.': '7. Record each manual result in this roadmap: PASS/FAIL plus exact reproduction.\n8. If any manual item fails, reopen only the owning canonical module and preserve the owner map.\n9. Mark MVP-14 D1 accepted only after all manual items pass.\n10. Continue the next roadmap block only after manual acceptance.\n11. Do not touch main/production without explicit authorization.',
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'Missing status token: {old}')
    text = text.replace(old, new, 1)
ROADMAP.write_text(text, encoding='utf-8')
EXPORT.write_text(text, encoding='utf-8')
print('Recorded final staging run 30943775973 as 19/20 application failure')
