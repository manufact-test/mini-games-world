# MVP-14R — CLEAN RELATIONAL MYSQL REBUILD

## AUTHORITATIVE CHECKPOINT — 2026-08-04 22:50 (+03:00)

```text
PROJECT: Mini Games World
REPOSITORY: manufact-test/mini-games-world
PRODUCTION BRANCH: main
PRODUCTION COMMIT: e11bb4909d549c1c5262de6eaf18338388e7bcdb
STAGING BRANCH: agent/mvp-13-2-staging
CURRENT STAGING CODE COMMIT: 76e25cd34ceb53259b27fd26689d04d0ea16ef72
PRODUCTION CHANGES AUTHORIZED: NO
CURRENT BLOCK: MVP-14 D1 — canonical notifications and player picker
OWNER AUDIT: COMPLETE
HOTFIX GRAPH RETIREMENT: COMPLETE
PLAYER-PICKER AUTOMATED SCOPE: PASS
NOTIFICATION AUTOMATED SCOPE: FAIL — 1 SCENARIO REMAINS
LATEST STAGING E2E: RUN 30943775973 — APPLICATION FAILURE, 19/20
MANUAL ACCEPTANCE: BLOCKED; NOT YET PERFORMED
MVP-14 D1 STATUS: NOT DONE
NEXT ACTION: FIX THE REMAINING CACHED-TOAST STATE TRANSFER INSIDE THE CANONICAL NOTIFICATION OWNER
```

# !!! КРИТИЧЕСКОЕ ПРАВИЛО ПРОЕКТА: НИКАКИХ ЗАПЛАТОК !!!

Это правило является блокирующим gate, а не рекомендацией.

## ЗАПРЕЩЕНО

Нельзя начинать или продолжать реализацию, если изменение добавляет ещё один слой поверх уже конфликтующей логики:

- новый глобальный `click`, `pointerdown`, `pointerup`, `touchend` или compatibility interceptor для того же действия;
- новый `window.fetch` wrapper поверх существующего transport owner;
- новый `MutationObserver`, polling loop, timer или CSS-hider, который догоняет неправильное состояние;
- дополнительный owner модального окна, toast, bell или player picker;
- параллельный client-side cache/status, который конкурирует с authoritative response;
- новый versioned hotfix-файл вместо исправления canonical owner;
- сохранение старого owner «на всякий случай» после переноса его ответственности;
- тест, подогнанный под частный порядок событий добавленной заплатки;
- объявление задачи готовой только потому, что автоматический тест зелёный;
- переход к следующей задаче до реальной ручной приёмки пользовательского сценария.

## ОБЯЗАТЕЛЬНО ДО ПЕРВОГО ИЗМЕНЕНИЯ КОДА

Для каждого проблемного узла должна быть составлена карта:

1. Authoritative state и его источник.
2. Единственный server-side owner.
3. Единственный client-side owner.
4. Все текущие handlers, wrappers, observers, polls и caches.
5. Какие механизмы остаются.
6. Какие механизмы удаляются из active graph.
7. Какие файлы удаляются полностью после переноса.
8. Какие автоматические проверки действительно применимы.
9. Какие сценарии можно принять только вручную на реальных устройствах.
10. Rollback checkpoint.

Без этой карты implementation PR создавать нельзя.

## КРИТЕРИЙ «ЭТО АРХИТЕКТУРНОЕ ИСПРАВЛЕНИЕ»

Изменение считается корневым только если одновременно выполнены все условия:

- существует один названный authoritative owner;
- старые конфликтующие owners перечислены;
- новый механизм заменяет их обязанности, а не оборачивает их;
- конфликтующие handlers/wrappers/observers/polls удалены из active graph;
- после переноса нет зависимости от versioned hotfix-файлов;
- UI имеет явную state machine;
- тест подтверждает отсутствие двойного владельца;
- focused automation проходит в своей реальной области применимости;
- реальное устройство подтверждает пользовательское поведение, если automation его не воспроизводит.

# !!! ПРАВИЛО ИСПОЛЬЗОВАНИЯ ТЕСТОВЫХ БОТОВ !!!

Боты подключаются только тогда, когда они способны проверить требуемое свойство. Нельзя тратить полный E2E-прогон ради сценария, который тестовая среда принципиально не воспроизводит.

## БОТЫ ПРИМЕНИМЫ

Боты и Playwright используются для:

- API/state transitions;
- сохранения, чтения и синхронизации данных в staging DB;
- отсутствия duplicate requests и duplicate owners;
- deterministic race conditions, которые можно воспроизвести контролируемыми ответами;
- DOM-состояния в обычном Chromium;
- regression существующих функций после архитектурного изменения;
- контрактов `loading / loaded / empty / error`;
- проверки, что test identities A/B видят друг друга в тестовом контуре;
- проверки, что старые hotfix assets отсутствуют в active graph.

## БОТЫ НЕ ЯВЛЯЮТСЯ ДОКАЗАТЕЛЬСТВОМ

Playwright с тестовой cookie не доказывает:

- работу реального Telegram Desktop/WebView;
- работу реального Telegram mobile WebView;
- поведение настоящих Telegram-аккаунтов и их session/presence lifecycle;
- физический tap/hold и Telegram-generated compatibility events;
- отсутствие визуального flash длительностью 0–500 мс на реальном устройстве;
- поведение уже давно открытого приложения с реальным local/session cache;
- сетевую задержку между двумя реальными устройствами.

Для этих свойств автоматический тест может быть только вспомогательной диагностикой. Финальный gate — ручная проверка на компьютере и телефоне с двумя настоящими аккаунтами.

## ОБЯЗАТЕЛЬНАЯ TEST-SCOPE ЗАПИСЬ

До запуска тестов в PR должно быть указано:

```text
PROPERTY UNDER TEST:
AUTOMATION CAN PROVE:
AUTOMATION CANNOT PROVE:
FOCUSED TESTS REQUIRED:
FULL SUITE REQUIRED: YES/NO + WHY
REAL-DEVICE CHECK REQUIRED: YES/NO + EXACT STEPS
```

Нельзя писать «20/20 — исправлено», если тесты не проверяют реальный Telegram-сценарий.

## ПРАВИЛО ВРЕМЕНИ

- Во время разработки запускаются только static/architecture checks и focused tests, относящиеся к изменённому owner.
- Полный repository CI запускается один раз перед merge архитектурного блока.
- Полный staging E2E запускается только если изменения могут затронуть соответствующие сценарии.
- Ручной-only баг не должен многократно запускать полный bot suite без новой проверяемой гипотезы.
- Повторный полный прогон разрешён только после конкретного изменения, способного повлиять на ранее упавший автоматизируемый сценарий.

# CURRENT ACCEPTANCE STATUS

## Что архитектурно завершено

- Notification graph сведён к одному canonical owner: `app/assets/js/screens/notifications-screen-v99.js`.
- Player-picker graph сведён к одному canonical owner: `app/assets/js/games/game-invites.js`.
- Deep-link передаёт явные lifecycle transitions в canonical notification owner.
- Ручное открытие picker выполняет один fresh authoritative `no-store` request.
- Boot-prefetch соперников больше не является источником ручного picker.
- Notification sheet и player picker имеют явные состояния `loading / loaded / empty / error`.
- Старые notification guards/policies и opponent wrappers удалены из active graph.

## Что подтверждено автоматизацией

- Единственный notification owner и единственный player-picker owner присутствуют в active graph.
- Удалённые guard/policy/wrapper assets не загружаются.
- Один manual picker open создаёт один authoritative opponents request.
- Controlled Chromium не рисует empty до authoritative ответа picker.
- Deep-link в controlled Chromium открывает decision sheet без duplicate toast.
- Test identities A/B проходят приглашение и полный матч в staging DB-контуре.
- Полный repository CI и DB-primary safety прошли.
- В последнем staging E2E успешно прошли 19 из 20 сценариев.

## Открытая автоматизируемая ошибка

Run `30943775973` повторно упал на сценарии:

```text
e2e/staging/d1-followup-acceptance-v120.spec.mjs:245
D1 v120 acceptance: mobile notification toast paints cached invitation before delayed false-empty response
```

Фактическое проявление:

- синий toast виден;
- пользователь нажимает toast;
- canonical notification sheet открывается;
- actionable-кнопка `Принять приглашение` для известного invite token не появляется в течение 1,2 секунды;
- delayed false-empty response всё ещё способен нарушить передачу уже известного toast item в sheet state.

Следовательно:

- notification automated gate не пройден;
- исправление `requestAnimationFrame` generation не устранило корневую потерю active toast item;
- ручную Telegram-приёмку начинать рано;
- `MVP-14 D1` не завершён.

## Ручная проверка после прохождения автоматического gate

Только после устранения оставшегося 20-го сценария нужно проверить:

1. Короткий click колокольчика в реальном Telegram Desktop — 10/10.
2. Короткий tap колокольчика в реальном Telegram mobile WebView — 10/10.
3. Реальный телефонный аккаунт появляется в picker компьютера, если телефон открылся позже.
4. Обратное направление: компьютерный аккаунт появляется на телефоне.
5. На реальном экране нет старого/пустого слоя длительностью 0–500 мс.
6. Invite-link показывает только decision sheet без синего toast.
7. Обычное приглашение в уже открытое приложение показывает actionable blue toast.

До выполнения автоматического и семи ручных пунктов статус — **NOT DONE**.

# RETIRED HOTFIX GRAPH — УДАЛЕНО ИЗ ACTIVE GRAPH

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

# MVP-14 D1 ARCHITECTURE REBUILD

## D1.0 — Read-only owner audit

**Статус:** COMPLETE.

Owner-map зафиксирована до реализации.

### Notification owner map

- authoritative server source: notifications/invites endpoints and staging DB runtime;
- canonical client owner: `app/assets/js/screens/notifications-screen-v99.js`;
- owner responsibilities: badge, polling, toast, bell activation, sheet state, deep-link silent transition;
- retired responsibilities: compatibility click guard, separate window owner, passive owner, DOM/CSS deep-link policy.

### Player-picker owner map

- authoritative server source: `bot/invite-opponents.php` and DB presence data;
- canonical client owner: `app/assets/js/games/game-invites.js`;
- one manual-action request owner;
- retired responsibilities: boot-response cache, global fetch wrappers, empty guards, authoritative-confirm retry layer.

**Bots:** не требовались для owner-map. Использовались static searches и architecture contracts.

## D1.1 — Single notification owner

**Статус:** ARCHITECTURE CONSOLIDATED; AUTOMATED GATE FAILED 19/20; FIX IN PROGRESS.

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
- старые notification hotfix layers удалены;
- generation guard не позволяет устаревшему animation frame визуально воскресить закрытый toast.

Не выполнено:

- known actionable toast item ещё не гарантированно становится seed item notification sheet до delayed false-empty response;
- focused cached-toast сценарий остаётся красным.

**Следующий focused test:** только cached-toast → click → immediate actionable card → delayed empty merge.

**Real-device gate:** заблокирован до прохождения focused и relevant staging E2E.

## D1.2 — Single player-picker owner

**Статус:** CODE COMPLETE; AUTOMATED SCOPE PASS; REAL-ACCOUNT ACCEPTANCE PENDING.

Canonical owner: `app/assets/js/games/game-invites.js`.

Целевая модель:

```text
idle
→ loading
→ loaded(items)
→ confirmed-empty
→ error
```

Выполнено:

- открытие picker начинает один fresh authoritative request;
- boot prefetch не используется как final source для user action;
- stale list и empty message не рисуются до текущего ответа;
- modal shell сначала показывает loading state;
- `confirmed-empty` разрешён только после authoritative success with empty items;
- глобальные `window.fetch` wrappers удалены;
- старые opponent guards удалены.

**Focused automation:** pass.

**Real-device gate:** computer opens first, phone account opens later, computer picker shows phone account; reverse direction; no false empty/old layer.

## D1.3 — Integration and regression

**Статус:** REPOSITORY CI COMPLETE; DB SAFETY COMPLETE; STAGING E2E FAILED 19/20; MANUAL GATE BLOCKED.

После D1.1 и D1.2:

- static architecture checks — complete;
- focused picker tests — pass;
- notification cached-toast focused/integration test — fail;
- full repository CI — success;
- DB-primary safety — success;
- latest relevant staging E2E — application failure;
- `main` remains unchanged.

# IMPLEMENTATION RECORD — 2026-08-04

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
| `30942161069` | 19/20 | obsolete tests corrected; cached-toast actionable-card scenario still failed |
| `30943775973` | 19/20, application failure | generation guard passed other coverage but did not fix cached-toast state transfer |

Последний run выполнялся на exact staging SHA `76e25cd34ceb53259b27fd26689d04d0ea16ef72`. Linux route передал выполнение macOS fallback; macOS выполнил все 20 сценариев. Итоговый application result — failure.

## Rollback checkpoints

```text
PRODUCTION / MAIN: e11bb4909d549c1c5262de6eaf18338388e7bcdb
PRE-ARCHITECTURE STAGING: 7264519c1dcd61b0479ee052d4855323a4deef47
CANONICAL OWNER MERGE: 9f815c340235b2b7c62d187d0767af017bb89b6b
CURRENT STAGING CODE: 76e25cd34ceb53259b27fd26689d04d0ea16ef72
```

# SUB-MVP ROADMAP

## MVP-14R.0 — Safety checkpoint and architecture audit

**Status:** complete for the relational rebuild baseline; D1 owner audit complete.

- preserve exact code checkpoints;
- preserve historical roadmap;
- inventory entrypoints, storage, locks, bridges, projections and UI owners;
- do not change production without explicit approval.

## MVP-14R.1 — Full production snapshot and temporary JSON recovery

- guarded DB→JSON export;
- isolated restore verification;
- preserve SQL/files/private checkpoint;
- production switch only after explicit approval.

## MVP-14R.2 — Behavior and latency baseline

- request/response contracts;
- deterministic fixtures;
- cold/warm latency;
- accepted real-device scenarios.

## MVP-14R.3 — Relational foundation and parity harness

- normalized schemas/repositories;
- transaction boundaries;
- migrations/fixtures;
- dual-run parity;
- no production change.

## MVP-14R.4 — Accounts, auth, sessions and presence

- provider-neutral account/identity;
- sessions/devices;
- unique online presence;
- two-real-device manual acceptance.

## MVP-14R.5 — Invites, notifications and Telegram sharing

- direct/link invitations;
- accept/decline/cancel;
- canonical notification center/toast/bell;
- Telegram share;
- no duplicate owners.

## MVP-14R.6 — Matchmaking, search and bot fallback

- relational queues;
- human match/cancel/repeat;
- bounded gameplay bot fallback;
- no global state lock.

## MVP-14R.7 — Games, clocks, results and rematches

- relational game state/actions;
- authoritative clocks;
- results/settlement/rematch;
- all eight games.

## MVP-14R.8 — Economy, history, shop, payments and weekly bonus

- ledger-first balance;
- settlement/history/inventory;
- payments;
- weekly eligibility/grant.

## MVP-14R.9 — Concurrency, load, failure and rollback rehearsal

- idempotency/deadlock retry;
- duplicate requests;
- process interruption/recovery;
- backup/restore/load.

## MVP-14R.10 — Final migration and production cutover

- fresh import/parity;
- backup gate;
- guarded cutover;
- full production regression;
- explicit release approval.

# NEXT EXECUTION ORDER

```text
1. Inspect the remaining cached-toast failure from run 30943775973 inside notifications-screen-v99.js.
2. Identify exactly where the visible toast loses its associated notification object before sheet seeding.
3. Correct the canonical owner state transfer; do not add a guard, wrapper, observer, retry module or second owner.
4. Run only the focused cached-toast scenario first.
5. After the focused scenario passes, run the relevant staging E2E suite once.
6. If the applicable automated scope passes, do not run more bots for Telegram-only properties.
7. Stop for the mandatory seven-step real-device acceptance on computer and phone.
8. Record each manual result in this roadmap: PASS/FAIL plus exact reproduction.
9. If any manual item fails, reopen only the owning canonical module and preserve the owner map.
10. Mark MVP-14 D1 accepted only after all automated and manual items pass.
11. Continue the next roadmap block only after manual acceptance.
12. Do not touch main/production without explicit authorization.
```