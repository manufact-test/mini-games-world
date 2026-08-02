# MVP-14R13.2 — безопасное закрытие staging operations gates

Этот runbook закрывает только оставшиеся инфраструктурные доказательства R13.2.
Он не изменяет production application, production data, webhook, Cron или BotFather.
Секреты, bot token, DSN, имя/пользователь/пароль базы не отправлять в чат,
в issue, PR, Actions log или commit.

## 1. Production bot fingerprint

Fingerprint вычисляется локально или в защищённом Hostinger terminal. Сам secret
передаётся только через STDIN, чтобы он не оказался в shell history:

```bash
read -rsp 'Production bot token: ' MGW_SECRET_INPUT; echo
printf '%s' "$MGW_SECRET_INPUT" | php ops/runtime/compute-secret-sha256.php
unset MGW_SECRET_INPUT
```

Результат имеет вид `sha256:<64 hex>`. В staging external private config нужно
добавить только 64-символьное значение в:

```php
'environment_guard' => [
    'production_bot_token_sha256' => '<64 hex>',
]
```

После staging reload/Redeploy повторно открыть:

```text
https://seashell-okapi-889488.hostingersite.com/bot/staging-readiness.php
```

Принимается только ответ, где все isolation flags равны `true`, включая:

```text
production_bot_identity_protected = true
```

Если staging bot token совпадает с production fingerprint, ConfigValidator обязан
fail-closed остановить staging. Сам fingerprint также не публикуется readiness.

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

До R13.3 вручную подтвердить:

- BotFather test Mini App / Web App URL указывает на staging host;
- staging webhook URL указывает на staging `/bot/webhook.php`;
- staging Cron target и schedule используют staging deployment/config;
- production BotFather, webhook и Cron не изменялись;
- выбранный browser runner способен установить HTTPS connection к staging.

Protected TEST PLAYER A/B auth нельзя начинать, пока readiness, оба DB audit pass и
routing checks не приняты полностью.
