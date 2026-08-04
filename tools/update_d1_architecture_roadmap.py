from pathlib import Path
import re

ROADMAP = Path('docs/MVP-14R-RELATIONAL-MYSQL-REBUILD-ROADMAP.md')
EXPORT = Path('MGW_MASTER_MVP_ROADMAP_V2_UPDATED_2026-08-04_ARCHITECTURE_REBUILD.md')
text = ROADMAP.read_text(encoding='utf-8')

checkpoint = '''## AUTHORITATIVE CHECKPOINT — 2026-08-04 22:40 (+03:00)

```text
PROJECT: Mini Games World
REPOSITORY: manufact-test/mini-games-world
PRODUCTION BRANCH: main
PRODUCTION COMMIT: e11bb4909d549c1c5262de6eaf18338388e7bcdb
STAGING BRANCH: agent/mvp-13-2-staging
CURRENT STAGING COMMIT: 76e25cd34ceb53259b27fd26689d04d0ea16ef72
PRODUCTION CHANGES AUTHORIZED: NO
CURRENT BLOCK: MVP-14 D1 — canonical notifications and player picker
ARCHITECTURE IMPLEMENTATION: COMPLETE ON STAGING CODE
REPOSITORY CI: SUCCESS
DB-PRIMARY SAFETY: SUCCESS
LATEST STAGING E2E: RUN 30943775973 — DEPLOY/TEST IN PROGRESS AT CHECKPOINT TIME
MANUAL ACCEPTANCE: REQUIRED AND NOT YET PERFORMED
NEXT ACTION: FINISH THE APPLICABLE STAGING RUN, THEN STOP FOR REAL TELEGRAM DESKTOP/MOBILE ACCEPTANCE
```
'''
text, count = re.subn(
    r'## AUTHORITATIVE CHECKPOINT.*?```\n',
    checkpoint,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Authoritative checkpoint replacement failed')

acceptance = '''# CURRENT ACCEPTANCE STATUS

## Что архитектурно завершено

- Notification graph заменён одним canonical owner: `app/assets/js/screens/notifications-screen-v99.js`.
- Player-picker graph заменён одним canonical owner внутри `app/assets/js/games/game-invites.js`.
- Deep-link передаёт явные lifecycle transitions в canonical notification owner.
- Ручное открытие picker выполняет один fresh authoritative `no-store` request.
- Boot-prefetch соперников больше не является источником ручного picker.
- Notification sheet и player-picker имеют явные состояния `loading / loaded / empty / error`.
- Отложенный toast render защищён поколением состояния: устаревший `requestAnimationFrame` не может воскресить закрытый toast.

## Что подтверждено автоматизацией

- Единственный notification owner и единственный player-picker owner присутствуют в active graph.
- Удалённые guard/policy/wrapper assets не загружаются.
- Один manual picker open создаёт один authoritative opponents request.
- Controlled Chromium не рисует empty до authoritative ответа.
- Deep-link в controlled Chromium открывает decision sheet без duplicate toast.
- Test identities A/B проходят приглашение и полный матч в staging DB-контуре.
- Полный repository CI и DB-primary safety прошли.

## Что автоматизация не принимает

Следующие свойства нельзя считать исправленными до ручной проверки:

1. Короткий click колокольчика в реальном Telegram Desktop — 10/10.
2. Короткий tap колокольчика в реальном Telegram mobile WebView — 10/10.
3. Реальный телефонный аккаунт появляется в picker компьютера, если телефон открылся позже.
4. Обратное направление: компьютерный аккаунт появляется на телефоне.
5. На реальном экране нет старого/пустого слоя длительностью 0–500 мс.
6. Invite-link показывает только decision sheet без синего toast.
7. Обычное приглашение в уже открытое приложение показывает actionable blue toast.

До выполнения этих семи пунктов `MVP-14 D1` имеет статус **MANUAL ACCEPTANCE PENDING**, а не `DONE`.

'''
text, count = re.subn(
    r'# CURRENT FAILURE — ЧТО ПРИЗНАНО НЕГОТОВЫМ\n.*?(?=# CURRENT HOTFIX GRAPH TO RETIRE)',
    acceptance,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Acceptance status replacement failed')

retired = '''# RETIRED HOTFIX GRAPH — УДАЛЕНО ИЗ ACTIVE GRAPH

Следующие прежние layers удалены из загрузки и из репозитория после переноса ответственности:

```text
app/assets/js/notification-deeplink-toast-policy-v131.js
app/assets/js/notification-compat-click-guard-v127.js
app/assets/js/screens/notification-window-owner-v121.js
app/assets/js/screens/notifications-passive-v130.js
app/assets/js/screens/notifications-passive-v121.js
app/assets/js/opponents-native-fetch-v115.js
app/assets/js/opponents-empty-cache-guard-v115.js
app/assets/js/opponents-authoritative-confirm-v122.js
app/assets/js/opponents-fresh-user-action-v128.js
```

Дополнительно удалён opponent-response owner из readiness-графа, который глобально заменял `window.fetch` и мог возвращать прогретый boot-list вместо свежего ручного запроса.

Удалены 15 patch-specific PHP/contract tests, требовавших наличие прежних guards и wrappers. Три staging E2E-сценария переписаны так, чтобы проверять canonical behavior, а не механику удалённых заплаток:

- обычный browser `click`, а не искусственный `pointerup` без `click`;
- один authoritative empty response, а не серия retry до непустого результата;
- отсутствие boot opponents fetch и один fresh manual request, а не обязательный boot-prefetch.

'''
text, count = re.subn(
    r'# CURRENT HOTFIX GRAPH TO RETIRE\n.*?(?=# MVP-14 D1 ARCHITECTURE REBUILD)',
    retired,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Retired graph replacement failed')

text = text.replace(
    '## D1.0 — Read-only owner audit\n\n**Статус:** IN PROGRESS.',
    '''## D1.0 — Read-only owner audit

**Статус:** COMPLETE.

Owner-map зафиксирована до реализации. Найдены четыре пересекающихся opponents transport/cache owners и несколько notification owners/guards. Все конфликтующие owners перечислены и удалены в replacement-блоке.''',
    1,
)
text = text.replace(
    '## D1.1 — Single notification owner\n',
    '''## D1.1 — Single notification owner

**Статус:** CODE COMPLETE; AUTOMATED SCOPE COMPLETE; REAL-DEVICE ACCEPTANCE PENDING.

Canonical owner: `app/assets/js/screens/notifications-screen-v99.js`.

''',
    1,
)
text = text.replace(
    '## D1.2 — Single player-picker owner\n',
    '''## D1.2 — Single player-picker owner

**Статус:** CODE COMPLETE; AUTOMATED SCOPE COMPLETE; REAL-ACCOUNT ACCEPTANCE PENDING.

Canonical owner: `app/assets/js/games/game-invites.js`.

''',
    1,
)
text = text.replace(
    '## D1.3 — Integration and regression\n',
    '''## D1.3 — Integration and regression

**Статус:** REPOSITORY CI COMPLETE; STAGING E2E FINAL RUN IN PROGRESS AT CHECKPOINT; MANUAL GATE PENDING.

''',
    1,
)

record = '''# IMPLEMENTATION RECORD — 2026-08-04

## Authoritative staging merges

| Block | PR | Resulting staging SHA | Result |
|---|---:|---|---|
| Blocking roadmap/rules | #413 | `7264519c1dcd61b0479ee052d4855323a4deef47` | merged to staging only |
| Canonical owner rebuild | #416 | `9f815c340235b2b7c62d187d0767af017bb89b6b` | merged to staging only |
| Canonical integration alignment | #425 | `c9655c327dfd1810d1505d80af19330e7df07c43` | merged to staging only |
| Generation-safe toast state | #427 | `76e25cd34ceb53259b27fd26689d04d0ea16ef72` | merged to staging only |

## Validation-only work that was not merged

- Temporary implementation/validation PRs `#414`, `#424` and `#426` were closed unmerged.
- CI-only PR `#423` targeted `main` only to execute repository checks and was closed unmerged.
- Exact validated architecture head: `ba1c3a0cf1c6bc41f5288870bb5497ed4e412fdc`.
- Repository CI run `30940530232`: success.
- DB-primary safety run `30940530191`: success.
- `main` remained exactly `e11bb4909d549c1c5262de6eaf18338388e7bcdb`.

## Staging integration evidence

| Run | Result | Meaning |
|---:|---|---|
| `30940932239` | 16/20 | exposed three obsolete patch-mechanic tests and one real cached-toast race |
| `30942161069` | 19/20 | obsolete tests corrected; exposed stale `requestAnimationFrame` toast resurrection |
| `30943775973` | in progress at checkpoint | validates generation-safe canonical toast on exact staging SHA `76e25cd…` |

A green browser run confirms only its controlled Chromium/DB scope. It does not replace the seven-step real-device gate above.

## Rollback checkpoints

```text
PRODUCTION / MAIN: e11bb4909d549c1c5262de6eaf18338388e7bcdb
PRE-ARCHITECTURE STAGING: 7264519c1dcd61b0479ee052d4855323a4deef47
CANONICAL OWNER MERGE: 9f815c340235b2b7c62d187d0767af017bb89b6b
CURRENT STAGING: 76e25cd34ceb53259b27fd26689d04d0ea16ef72
```

'''
marker = '# SUB-MVP ROADMAP\n'
if marker not in text:
    raise SystemExit('Sub-MVP roadmap marker missing')
text = text.replace(marker, record + marker, 1)

next_order = '''# NEXT EXECUTION ORDER

```text
1. Finish staging E2E run 30943775973 for exact SHA 76e25cd34ceb53259b27fd26689d04d0ea16ef72.
2. If an automatable scenario fails, inspect and correct the canonical owner only; do not restore guards/wrappers.
3. If the applicable automated scope passes, do not run more bots for Telegram-only properties.
4. Stop for mandatory seven-step real-device acceptance on computer and phone.
5. Record each manual result in this roadmap: PASS/FAIL plus exact reproduction.
6. If any manual item fails, reopen only the owning canonical module and preserve the owner map.
7. Mark MVP-14 D1 accepted only after all manual items pass.
8. Continue the next roadmap block only after manual acceptance.
9. Do not touch main/production without explicit authorization.
```
'''
text, count = re.subn(r'# NEXT EXECUTION ORDER\n.*\Z', next_order, text, count=1, flags=re.S)
if count != 1:
    raise SystemExit('Next execution order replacement failed')

required = [
    'CURRENT STAGING COMMIT: 76e25cd34ceb53259b27fd26689d04d0ea16ef72',
    '## D1.0 — Read-only owner audit\n\n**Статус:** COMPLETE.',
    '# RETIRED HOTFIX GRAPH — УДАЛЕНО ИЗ ACTIVE GRAPH',
    '# IMPLEMENTATION RECORD — 2026-08-04',
    'MANUAL ACCEPTANCE PENDING',
    'run `30940530232`: success',
    'run 30943775973',
]
for token in required:
    if token not in text:
        raise SystemExit(f'Missing required roadmap token: {token}')

ROADMAP.write_text(text, encoding='utf-8')
EXPORT.write_text(text, encoding='utf-8')
print(f'Updated {ROADMAP} and prepared {EXPORT}')
