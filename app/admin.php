<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header("Content-Security-Policy: default-src 'none'; script-src 'self' https://telegram.org; style-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; object-src 'none'");
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Mini Games World · Admin</title>
  <link rel="stylesheet" href="./assets/css/admin-shell.css?v=3&replay=17-6">
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="./assets/js/admin-shell.js?v=3&replay=17-6" defer></script>
  <script src="./assets/js/admin-reports.js?v=1&mvp18=reports" defer></script>
  <script src="./assets/js/admin-notifications.js?v=1&mvp18=bell-pipeline" defer></script>
</head>
<body>
  <main class="mgw-admin" data-admin-api="../bot/admin-read.php" data-economy-api="../bot/admin-economy.php" data-replay-api="../bot/admin-replay.php" data-reports-api="../bot/admin-reports.php" data-notifications-api="../bot/admin-notifications.php">
    <header class="mgw-admin__header">
      <div>
        <p class="mgw-admin__eyebrow">MINI GAMES WORLD</p>
        <h1>Web Admin</h1>
        <p class="mgw-admin__subtitle">Системный обзор, bell events, экономика, replay storage и очередь жалоб.</p>
      </div>
      <button class="mgw-admin__refresh" type="button" data-admin-refresh>Обновить</button>
    </header>

    <section class="mgw-admin__status" aria-live="polite" data-admin-status>
      Подключение к Telegram…
    </section>

    <section class="mgw-admin__meta" data-admin-meta hidden>
      <div><span>Среда</span><strong data-admin-environment>—</strong></div>
      <div><span>Build</span><strong data-admin-build>—</strong></div>
      <div><span>Обновлено</span><strong data-admin-generated>—</strong></div>
    </section>

    <section class="mgw-admin__grid" data-admin-content hidden>
      <article class="mgw-admin__card">
        <div class="mgw-admin__card-head">
          <h2>Обзор</h2>
          <span>read-only</span>
        </div>
        <pre data-admin-dashboard>—</pre>
      </article>

      <article class="mgw-admin__card">
        <div class="mgw-admin__card-head">
          <h2>Проверка системы</h2>
          <span>read-only</span>
        </div>
        <pre data-admin-system-check>—</pre>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-notifications>
        <div class="mgw-admin__card-head">
          <h2>Bell events</h2>
          <span>MVP-18.6 · one pipeline</span>
        </div>
        <div class="mgw-admin__economy">
          <div class="mgw-admin__economy-meta">
            <label class="mgw-admin__field">
              <span>Источник</span>
              <select data-notification-source-type>
                <option value="admin">admin</option>
                <option value="system">system</option>
                <option value="support">support</option>
              </select>
            </label>
            <label class="mgw-admin__field">
              <span>Аудитория</span>
              <select data-notification-audience-type>
                <option value="all">all</option>
                <option value="one">one</option>
                <option value="segment">segment</option>
                <option value="platform">platform</option>
                <option value="tournament">tournament</option>
                <option value="support">support</option>
              </select>
            </label>
          </div>

          <small data-notification-audience-hint>Все текущие MGW-аккаунты.</small>

          <label class="mgw-admin__field" hidden>
            <span>Target MGW-ID</span>
            <input data-notification-target-mgw-id type="text" maxlength="24" autocomplete="off" placeholder="MGW-ID">
          </label>
          <label class="mgw-admin__field" hidden>
            <span>Platform</span>
            <input data-notification-platform type="text" maxlength="32" autocomplete="off" placeholder="telegram">
          </label>
          <label class="mgw-admin__field" hidden>
            <span>Audience ref</span>
            <input data-notification-audience-ref type="text" maxlength="191" autocomplete="off" placeholder="segment / tournament / case ID">
          </label>
          <label class="mgw-admin__field" hidden>
            <span>Recipient MGW-IDs</span>
            <input data-notification-recipient-mgw-ids type="text" autocomplete="off" placeholder="MGW-ID, MGW-ID, ...">
          </label>

          <label class="mgw-admin__field">
            <span>Заголовок</span>
            <input data-notification-title type="text" maxlength="160" autocomplete="off" placeholder="Заголовок уведомления">
          </label>
          <label class="mgw-admin__field">
            <span>Текст</span>
            <input data-notification-text type="text" maxlength="4000" autocomplete="off" placeholder="Текст уведомления">
          </label>
          <label class="mgw-admin__field">
            <span>Deep link</span>
            <select data-notification-deep-link>
              <option value="">без перехода</option>
              <option value="home">home</option>
              <option value="profile">profile</option>
              <option value="store">store</option>
              <option value="store:orders">store:orders</option>
            </select>
          </label>

          <div class="mgw-admin__economy-meta">
            <label class="mgw-admin__field">
              <span>Schedule</span>
              <input data-notification-scheduled-at type="datetime-local">
            </label>
            <label class="mgw-admin__field">
              <span>Expiry</span>
              <input data-notification-expires-at type="datetime-local">
            </label>
          </div>

          <div class="mgw-admin__economy-actions">
            <button type="button" data-notification-event-send>Создать bell event</button>
            <button type="button" data-notification-event-refresh>Обновить историю</button>
            <small>Schedule/expiry исполняются в существующем bell pipeline. Android push не используется.</small>
          </div>
          <div class="mgw-admin__replay-status" data-notification-event-status>Bell events ещё не загружены.</div>
          <div class="mgw-admin__history" data-notification-event-list></div>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-reports>
        <div class="mgw-admin__card-head">
          <h2>Жалобы игроков</h2>
          <span>MVP-18.5 · manual moderation</span>
        </div>
        <div class="mgw-admin__economy-actions">
          <button type="button" data-report-queue-refresh>Обновить очередь</button>
          <small>Статус меняется вручную. Auto-ban и автоматические ограничения отсутствуют.</small>
        </div>
        <div class="mgw-admin__replay-status" data-report-queue-status>Очередь ещё не загружена.</div>
        <div class="mgw-admin__history" data-report-queue-list></div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-replay-card>
        <div class="mgw-admin__card-head">
          <h2>Replay матча</h2>
          <span>MVP-17.6 · read-only</span>
        </div>
        <div class="mgw-admin__replay">
          <div class="mgw-admin__replay-search">
            <label class="mgw-admin__field">
              <span>Match ID</span>
              <input data-replay-match-id type="text" maxlength="191" autocomplete="off" placeholder="Введите ID матча">
            </label>
            <button type="button" data-replay-load>Загрузить replay</button>
          </div>
          <div class="mgw-admin__replay-status" data-replay-status>Укажите Match ID. Данные не изменяются.</div>
          <div data-replay-output hidden>
            <div class="mgw-admin__replay-summary" data-replay-summary></div>
            <h3>События</h3>
            <div class="mgw-admin__replay-list" data-replay-timeline></div>
            <h3>Снимки состояния</h3>
            <div class="mgw-admin__replay-list" data-replay-frames></div>
          </div>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-economy-card>
        <div class="mgw-admin__card-head">
          <h2>Экономика</h2>
          <span>versioned config</span>
        </div>
        <div class="mgw-admin__economy">
          <div class="mgw-admin__economy-meta">
            <div><span>Версия</span><strong data-economy-version>—</strong></div>
            <div><span>SHA-256</span><strong data-economy-sha>—</strong></div>
          </div>

          <label class="mgw-admin__field">
            <span>Конфигурация JSON</span>
            <textarea data-economy-config rows="22" spellcheck="false" autocomplete="off"></textarea>
          </label>

          <label class="mgw-admin__field">
            <span>Причина изменения / rollback</span>
            <input data-economy-reason type="text" maxlength="500" autocomplete="off" placeholder="Обязательная причина">
          </label>

          <div class="mgw-admin__economy-actions">
            <button type="button" data-economy-save>Сохранить новую версию</button>
            <small>Изменение конфигурации не меняет балансы пользователей.</small>
          </div>

          <div class="mgw-admin__simulation">
            <h3>Детерминированная проверка</h3>
            <pre data-economy-simulation>—</pre>
          </div>

          <div class="mgw-admin__history">
            <h3>История версий</h3>
            <div data-economy-history></div>
          </div>
        </div>
      </article>
    </section>

    <footer class="mgw-admin__footer">
      Bell events используют существующий Notification Center и не создают второй notification store. Replay viewer читает только durable events/snapshots. Очередь жалоб не применяет автоматических санкций. Web Admin не переключает runtime; Android push остаётся вне MVP-18.6.
    </footer>
  </main>
</body>
</html>
