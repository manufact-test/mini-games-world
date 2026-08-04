from pathlib import Path
import re

path = Path('docs/MVP-14R-RELATIONAL-MYSQL-REBUILD-ROADMAP.md')
text = path.read_text(encoding='utf-8')

checkpoint = '''## AUTHORITATIVE CHECKPOINT — 2026-08-04 23:40 (+03:00)

```text
PROJECT: Mini Games World
REPOSITORY: manufact-test/mini-games-world
PRODUCTION BRANCH: main
PRODUCTION COMMIT: e11bb4909d549c1c5262de6eaf18338388e7bcdb
STAGING BRANCH: agent/mvp-13-2-staging
CURRENT STAGING COMMIT: 2c0675451daf0f3207bd5f41b79f5bc50a4cb3e5
PRODUCTION CHANGES AUTHORIZED: NO
CURRENT BLOCK: MVP-14 D1 — canonical notifications and player picker
OWNER AUDIT: COMPLETE
HOTFIX GRAPH RETIREMENT: COMPLETE
PLAYER-PICKER AUTOMATED SCOPE: PASS
NOTIFICATION AUTOMATED SCOPE: PASS
REPOSITORY CI: SUCCESS — RUN 30947431504
DB-PRIMARY SAFETY: SUCCESS — RUN 30947431790
LATEST STAGING E2E: SUCCESS — RUN 30947927723 — 20/20
AUTOMATED D1 GATE: PASS
MANUAL ACCEPTANCE: REQUIRED AND NOT YET PERFORMED
MVP-14 D1 STATUS: AUTOMATED COMPLETE; MANUAL ACCEPTANCE PENDING
NEXT ACTION: STOP AUTOMATION AND COMPLETE THE SEVEN-STEP REAL TELEGRAM DESKTOP/MOBILE GATE
```
'''
text, count = re.subn(
    r'## AUTHORITATIVE CHECKPOINT.*?```\n(?=\n# !!! КРИТИЧЕСКОЕ ПРАВИЛО)',
    checkpoint,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace authoritative checkpoint')

acceptance = '''# CURRENT ACCEPTANCE STATUS

## Что архитектурно завершено

- Notification graph сведён к одному canonical owner: `app/assets/js/screens/notifications-screen-v99.js`.
- Player-picker graph сведён к одному canonical owner: `app/assets/js/games/game-invites.js`.
- Deep-link передаёт явные lifecycle transitions в canonical notification owner.
- Ручное открытие picker выполняет один fresh authoritative `no-store` request.
- Boot-prefetch соперников больше не является источником ручного picker.
- Notification sheet и player picker имеют явные состояния `loading / loaded / empty / error`.
- Старые notification guards/policies и opponent wrappers удалены из active graph.
- Toast передаёт уже известное actionable invitation в тот же canonical notification sheet owner до завершения delayed server response.
- Отложенный toast render имеет generation-state и не может воскресить закрытый toast.

## Установленная причина повторных 19/20

Последние красные staging-прогоны исполняли старую CDN-копию `notifications-screen-v99.js?v=d1`, хотя исправленный canonical owner уже находился в GitHub. Trace показал:

```text
cache-control: public, max-age=604800
x-hcdn-cache-status: HIT
age: около 43 минут
```

Корректирующее изменение не добавляло новую логику или owner. Оно обновило immutable cache identity существующего canonical graph:

```text
main.js?v=d1-canonical-toast-seed
notifications-screen-v99.js?v=d1-canonical-toast-seed
build marker: d1-canonical-toast-seed
```

После получения свежего owner браузером previously failing cached-toast scenario прошёл.

## Что подтверждено автоматизацией

- Единственный notification owner и единственный player-picker owner присутствуют в active graph.
- Удалённые guard/policy/wrapper assets не загружаются.
- Один manual picker open создаёт один authoritative opponents request.
- Controlled Chromium не рисует empty до authoritative ответа picker.
- Deep-link в controlled Chromium открывает decision sheet без duplicate toast.
- Cached actionable toast немедленно seed-ит notification sheet и переживает delayed false-empty response.
- Test identities A/B проходят приглашение и полный матч в staging DB-контуре.
- Полный repository CI прошёл: run `30947431504`.
- DB-primary safety прошёл: run `30947431790`.
- Staging E2E прошёл: run `30947927723`, **20/20**, `staging-playwright-e2e: success`.

## Что автоматизация не принимает

Автоматизация не доказывает работу реального Telegram Desktop/WebView, настоящих Telegram sessions/presence, физического click/tap и отсутствие краткого визуального flash на реальном экране.

Поэтому общий блок ещё нельзя помечать `ACCEPTED` до ручной проверки:

1. Короткий click колокольчика в реальном Telegram Desktop — 10/10.
2. Короткий tap колокольчика в реальном Telegram mobile WebView — 10/10.
3. Компьютер открыт первым, телефон позже: реальный телефонный аккаунт появляется в picker компьютера.
4. Обратное направление: реальный компьютерный аккаунт появляется в picker телефона.
5. На реальном экране нет старого/пустого слоя длительностью 0–500 мс.
6. Invite-link показывает только decision sheet без синего toast.
7. Обычное приглашение в уже открытое приложение показывает actionable blue toast.

Текущий статус: **AUTOMATED COMPLETE; MANUAL ACCEPTANCE PENDING**.

'''
text, count = re.subn(
    r'# CURRENT ACCEPTANCE STATUS\n.*?(?=# RETIRED HOTFIX GRAPH)',
    acceptance,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace current acceptance status')

notification_section = '''## D1.1 — Single notification owner

**Статус:** CODE COMPLETE; AUTOMATED SCOPE PASS; REAL-DEVICE ACCEPTANCE PENDING.

Canonical owner: `app/assets/js/screens/notifications-screen-v99.js`.

Целевая модель:

```text
closed
→ opening
→ loading | seeded-ready
→ ready
→ closing
→ closed
```

Отдельная event policy внутри owner:

```text
normal notification → may show actionable toast
invite deep-link being opened → consume/sync silently, show decision sheet, never show duplicate toast
```

Выполнено:

- один зарегистрированный browser `click` path для bell/toast activation;
- один owner открывает и закрывает notification sheet;
- deep-link policy перенесена в explicit state transition;
- actionable toast item передаётся как seed того же sheet owner;
- delayed empty response не заменяет уже известное actionable invitation пустым состоянием;
- generation-state инвалидирует устаревший toast animation frame;
- старые notification hotfix layers удалены;
- immutable cache identity гарантирует загрузку актуального canonical owner после deploy.

**Focused automation:** pass.

**Staging E2E:** run `30947927723`, 20/20, success.

**Real-device gate:** pending; automation больше не требуется до появления конкретного ручного failure.

'''
text, count = re.subn(
    r'## D1\.1 — Single notification owner\n.*?(?=## D1\.2 — Single player-picker owner)',
    notification_section,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace D1.1 section')

integration_section = '''## D1.3 — Integration and regression

**Статус:** REPOSITORY CI COMPLETE; DB SAFETY COMPLETE; STAGING E2E PASS 20/20; MANUAL GATE PENDING.

После D1.1 и D1.2:

- static architecture checks — complete;
- focused picker tests — pass;
- focused cached-toast test — pass;
- full repository CI — success, run `30947431504`;
- DB-primary safety — success, run `30947431790`;
- relevant staging E2E — success, run `30947927723`, 20/20;
- final commit status `staging-playwright-e2e` — success;
- `main` remains exactly `e11bb4909d549c1c5262de6eaf18338388e7bcdb`.

Автоматические проверки на этом блоке остановлены. Следующий gate — только реальный Telegram Desktop/mobile acceptance.

'''
text, count = re.subn(
    r'## D1\.3 — Integration and regression\n.*?(?=# IMPLEMENTATION RECORD)',
    integration_section,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace D1.3 section')

row_anchor = '| Generation-safe toast state | #427 | `76e25cd34ceb53259b27fd26689d04d0ea16ef72` | merged to staging only |'
row_new = row_anchor + '\n| Fresh canonical CDN identity | #432 | `2c0675451daf0f3207bd5f41b79f5bc50a4cb3e5` | merged to staging only |'
if row_anchor not in text:
    raise SystemExit('Missing implementation table anchor')
text = text.replace(row_anchor, row_new, 1)

text = text.replace(
    '- Repository CI run `30940530232`: success.\n- DB-primary safety run `30940530191`: success.',
    '- Architecture repository CI run `30940530232`: success.\n- Architecture DB-primary safety run `30940530191`: success.\n- Final cache-identity repository CI run `30947431504`: success.\n- Final cache-identity DB-primary safety run `30947431790`: success.',
    1,
)

staging_evidence = '''## Staging integration evidence

| Run | Result | Meaning |
|---:|---|---|
| `30940932239` | 16/20 | exposed three obsolete patch-mechanic tests and one cached-toast race |
| `30942161069` | 19/20 | obsolete tests corrected; old CDN object still executed |
| `30943775973` | 19/20 | generation-safe code existed in GitHub, but staging browser still received cached `?v=d1` module |
| `30947927723` | **20/20, success** | fresh immutable URLs loaded the current canonical owner; cached-toast scenario and all remaining controlled scenarios passed |

Финальный run выполнялся на exact staging SHA `2c0675451daf0f3207bd5f41b79f5bc50a4cb3e5`. Linux route прошёл readiness и выполнил все 20 сценариев. Финальный publisher job и commit status завершились success.

Green staging E2E подтверждает только controlled Chromium/DB scope и не заменяет ручной Telegram gate.

'''
text, count = re.subn(
    r'## Staging integration evidence\n.*?(?=## Rollback checkpoints)',
    staging_evidence,
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Failed to replace staging evidence')

text = text.replace(
    'CURRENT STAGING CODE: 76e25cd34ceb53259b27fd26689d04d0ea16ef72',
    'CURRENT STAGING: 2c0675451daf0f3207bd5f41b79f5bc50a4cb3e5',
    1,
)

next_execution = '''# NEXT EXECUTION ORDER

```text
1. Do not run more bots for properties that require real Telegram Desktop/mobile behavior.
2. Perform the seven-step real-device acceptance on computer and phone.
3. Record every item in this roadmap as PASS or FAIL with the exact device and reproduction.
4. If all seven items pass, mark MVP-14 D1 ACCEPTED and continue the next roadmap block.
5. If an item fails, reopen only its canonical owner and use the exact real-device reproduction.
6. Do not restore guards, wrappers, observers, retry modules or parallel owners.
7. Run focused automation again only if the manual failure maps to a property automation can reproduce.
8. Keep main/production unchanged until explicit authorization.
```
'''
text, count = re.subn(r'# NEXT EXECUTION ORDER\n.*\Z', next_execution, text, count=1, flags=re.S)
if count != 1:
    raise SystemExit('Failed to replace next execution order')

required = [
    'CURRENT STAGING COMMIT: 2c0675451daf0f3207bd5f41b79f5bc50a4cb3e5',
    'LATEST STAGING E2E: SUCCESS — RUN 30947927723 — 20/20',
    'AUTOMATED D1 GATE: PASS',
    'Fresh canonical CDN identity | #432',
    '`30947927723` | **20/20, success**',
    'AUTOMATED COMPLETE; MANUAL ACCEPTANCE PENDING',
    'main/production unchanged until explicit authorization',
]
for token in required:
    if token not in text:
        raise SystemExit(f'Missing required token: {token}')

path.write_text(text, encoding='utf-8')
print('Roadmap updated to automated PASS and manual acceptance pending.')
