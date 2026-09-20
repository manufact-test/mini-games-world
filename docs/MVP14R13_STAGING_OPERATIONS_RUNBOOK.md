# MVP-14R13.2 — безопасное закрытие staging operations gates

Этот runbook относится только к тестовой среде Mini Games World.
Он не изменяет production application, production data, рабочий Telegram-бот,
production webhook, production Cron или реальные платежи.

Токены, пароли, DSN и приватные настройки не отправлять в чат, issue, PR,
Actions log или commit.

## 1. Проверка тестового Telegram-бота

В staging уже настроены собственный bot token и ожидаемый username тестового бота.
Основной рабочий bot token и его SHA-256 для этой проверки не требуются.

При открытии readiness endpoint сервер безопасно вызывает Telegram Bot API `getMe`
с уже установленным staging token и сравнивает фактический username бота с
`staging_bot_username` из приватной staging-конфигурации.

Проверка:

```text
https://seashell-okapi-889488.hostingersite.com/bot/staging-readiness.php
```

Принимается ответ, где:

```text
production_bot_identity_protected = true
```

Поле сохранено под прежним названием для совместимости с R13.2. Теперь оно
означает, что staging token в реальном ответе Telegram принадлежит ожидаемому
тестовому боту. Если token относится к другому боту, Telegram недоступен или
username не совпадает, проверка остаётся `false`.

Ответ Telegram кешируется на короткое время. Токен, его hash, bot ID и приватные
данные в публичный readiness JSON не выводятся.

## 2. Первый read-only аудит staging DB

Команда запускается только в staging project из корня deployment:

```bash
php ops/runtime/audit-staging-outbox.php > /tmp/mgw-r13-outbox-before.json
```

Утилита самостоятельно откажется работать, если:

- environment не `staging`;
- base host не равен canonical staging host;
- database disabled;
- production DB fingerprint отсутствует;
- staging DB identity совпадает с production;
- включён live payment mode.

Все SQL выполняются внутри `START TRANSACTION READ ONLY`. Отчёт не содержит DB
host/name/user/password/DSN и показывает только fingerprint и агрегированные
метрики.

Первичный PASS требует:

- `completed.row_count <= 16`;
- неизвестные outbox statuses отсутствуют;
- outbox table меньше 128 MB;
- staging database меньше 512 MB и намного ниже Hostinger limit 3072 MB.

## 3. Повтор после staging mutations

После нескольких безопасных staging-only операций выполнить quiet-period аудит:

```bash
php ops/runtime/audit-staging-outbox.php --expect-quiet > /tmp/mgw-r13-outbox-after.json
```

Quiet PASS дополнительно требует:

```text
pending = 0
processing = 0
failed = 0
completed <= 16
```

Сравнить `before` и `after`:

- completed остаётся bounded;
- `state_json_mb` и `outbox_table.total_mb` не растут линейно без ограничения;
- `database.total_mb` остаётся далеко ниже 3072 MB;
- database identity fingerprint остаётся тем же staging fingerprint.

В чат можно передавать только JSON отчёты этих утилит: они специально не содержат
секретов и database coordinates.

## 4. Оставшиеся routing checks

До R13.3 подтвердить:

- BotFather test Mini App / Web App URL указывает на staging host;
- staging webhook URL указывает на staging `/bot/webhook.php`;
- staging Cron target и schedule используют staging deployment/config;
- production BotFather, webhook и Cron не изменялись;
- выбранный browser runner способен установить HTTPS connection к staging.

Protected TEST PLAYER A/B auth нельзя начинать, пока readiness, оба DB audit pass и
routing checks не приняты полностью.
