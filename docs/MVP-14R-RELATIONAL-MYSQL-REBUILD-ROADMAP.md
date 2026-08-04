# MVP-14R — CLEAN RELATIONAL MYSQL REBUILD

## AUTHORITATIVE CHECKPOINT — 2026-08-04 20:52 (+03:00)

```text
PROJECT: Mini Games World
REPOSITORY: manufact-test/mini-games-world
PRODUCTION BRANCH: main
PRODUCTION COMMIT: e11bb4909d549c1c5262de6eaf18338388e7bcdb
STAGING BRANCH: agent/mvp-13-2-staging
FAILED STAGING COMMIT: 10142d903c0f608c291530c738f79b4a9865c245
PRODUCTION CHANGES AUTHORIZED: NO
CURRENT BLOCK: MVP-14 D1 — notifications, player picker and real-device UI lifecycle
MANUAL ACCEPTANCE: FAILED
CONFIRMED WORKING CHANGE: deep-link invitation opens without duplicate blue toast
CONFIRMED NOT FIXED: bell reliability, real-account visibility, false empty/loading flash
NEXT ACTION: REMOVE HOTFIX GRAPH AND REBUILD BOTH UI OWNERS ARCHITECTURALLY
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
- контрактов loading/loaded/empty/error;
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
- Ручной-only баг не должен многократно запускать четырёхчасовой bot suite без новой проверяемой гипотезы.
- Повторный полный прогон разрешён только после конкретного изменения, способного повлиять на ранее упавший автоматизируемый сценарий.

# CURRENT FAILURE — ЧТО ПРИЗНАНО НЕГОТОВЫМ

## 1. Notification bell

На реальном компьютере и телефоне короткий tap/click срабатывает нестабильно. Удержание также не является надёжным обходом.

Текущий active graph содержит несколько пересекающихся owners и guards. Это архитектурно неприемлемо.

## 2. Player picker / real accounts

Тестовые `stg_test_player_a/b` видны в controlled E2E, но реальный аккаунт, открытый позже на телефоне, не появляется у пользователя на компьютере.

Следовательно, тест test-player visibility не является доказательством real-account presence/picker behavior.

## 3. False empty/loading flash

На обоих устройствах пользователь видит промежуточный пустой/старый слой приблизительно 0–500 мс до появления списка.

Предыдущий E2E начинал запись только после `sheetOverlay.active`, поэтому не мог видеть кадр до активации overlay. Такой тест не принимает этот баг.

## 4. Deep-link duplicate toast

Единственный подтверждённый исправленный сценарий: при входе по invitation link появляется decision sheet без дополнительного синего toast.

При архитектурной переработке это поведение должно быть сохранено внутри нового canonical notification owner без отдельного policy/hider layer.

# CURRENT HOTFIX GRAPH TO RETIRE

Следующие active layers не должны наращиваться и должны быть удалены из active graph после переноса ответственности:

```text
app/assets/js/notification-deeplink-toast-policy-v131.js
app/assets/js/notification-compat-click-guard-v127.js
app/assets/js/screens/notification-window-owner-v121.js
app/assets/js/screens/notifications-passive-v130.js
app/assets/js/opponents-native-fetch-v115.js
app/assets/js/opponents-empty-cache-guard-v115.js
app/assets/js/opponents-authoritative-confirm-v122.js
app/assets/js/opponents-fresh-user-action-v128.js
```

Также удаляются или переписываются тесты, которые проверяют наличие именно этих hotfix layers вместо canonical behavior.

Удаление выполняется не отдельным слепым commit, который ломает staging, а внутри replacement branch: новый owner сначала принимает обязанности, затем old graph удаляется в том же архитектурном блоке.

# MVP-14 D1 ARCHITECTURE REBUILD

## D1.0 — Read-only owner audit

**Статус:** IN PROGRESS.

Нужно определить:

### Notifications

- canonical notification data source;
- один reader/sync owner;
- один bell input owner;
- один modal render owner;
- один toast decision owner;
- deep-link suppression как параметр canonical transition, а не отдельный DOM watcher;
- точный список старых listeners/owners для удаления.

### Player picker

- canonical presence source;
- canonical opponents endpoint;
- кто выполняет boot prefetch;
- кто инициирует user-request refresh;
- кто хранит cache;
- кто рисует loading/loaded/confirmed-empty/error;
- точный список fetch wrappers/cache guards для удаления.

**Done when:** в репозитории находится документированная owner map и retirement list. Код поведения ещё не меняется.

**Bots:** не нужны для составления карты. Допустимы только static searches/contracts.

## D1.1 — Single notification owner

Целевая модель:

```text
closed
→ opening
→ loading
→ ready
→ closing
→ closed
```

Отдельная event policy внутри owner:

```text
normal notification → may show toast
invite deep-link being opened → consume/sync silently, show decision sheet, never show duplicate toast
```

Требования:

- один зарегистрированный handler на bell action;
- handler использует обычный `click`/accessible activation без pointerup+compatibility competition;
- один owner открывает и закрывает sheet;
- bell не зависит от hold;
- deep-link policy является входным параметром/state, не отдельным module-level observer/poller;
- старые notification hotfix layers удалены из active graph;
- duplicate toast remains fixed.

**Focused automation:** DOM state machine, exactly-one-handler contract, normal invitation toast, silent deep-link transition.

**Real-device gate:** desktop Telegram short click 10/10; mobile Telegram tap 10/10; close/reopen; deep-link without duplicate toast.

## D1.2 — Single player-picker owner

Целевая модель:

```text
idle
→ loading
→ loaded(items)
→ confirmed-empty
→ error
```

Требования:

- открытие picker всегда начинает один fresh authoritative request;
- boot prefetch не является final source for a later user action;
- stale list and empty message never paint before current request resolves;
- modal shell paints once in `loading`, without old content underneath;
- `confirmed-empty` разрешён только после authoritative success with empty items;
- одна request cancellation policy;
- одна cache policy;
- no `window.fetch` wrappers;
- old opponent guards removed from active graph;
- real account presence lifecycle investigated separately from test identities.

**Focused automation:** state machine transitions, no stale cache on manual open, no empty state before authoritative result, exactly one opponents request owner.

**Real-device gate:** computer opens first, phone account opens later, computer picker shows phone account; reverse direction; no false empty/old layer on either device.

## D1.3 — Integration and regression

После D1.1 и D1.2:

- run static architecture checks;
- run focused notification and picker tests;
- run full repository CI once;
- run only relevant staging E2E regression once;
- do not call the block accepted before the real-device gate;
- `main` remains unchanged until explicit production authorization.

# SUB-MVP ROADMAP

## MVP-14R.0 — Safety checkpoint and architecture audit

**Status:** complete for the relational rebuild baseline; D1 owner audit reopened because manual acceptance exposed a prohibited hotfix graph.

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
1. Merge this roadmap into staging only.
2. Create D1 architecture audit branch from the merged staging commit.
3. Produce exact notification and player-picker owner map.
4. Do not add v132 or any new compatibility/fetch/cache guard.
5. Replace notification graph with one canonical owner and remove old layers.
6. Replace player-picker graph with one canonical owner and remove old layers.
7. Run focused applicable automation.
8. Run full CI once at integration gate.
9. Deploy staging only.
10. Stop for mandatory real-device acceptance.
11. Do not touch main/production without explicit authorization.
```
