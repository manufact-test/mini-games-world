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
  <link rel="stylesheet" href="./assets/css/admin-shell.css?v=2&economy=15-8">
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="./assets/js/admin-shell.js?v=2&economy=15-8" defer></script>
</head>
<body>
  <main class="mgw-admin" data-admin-api="../bot/admin-read.php" data-economy-api="../bot/admin-economy.php">
    <header class="mgw-admin__header">
      <div>
        <p class="mgw-admin__eyebrow">MINI GAMES WORLD</p>
        <h1>Web Admin</h1>
        <p class="mgw-admin__subtitle">Системный обзор и версионная конфигурация экономики.</p>
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
      Веб-панель не редактирует пользовательские балансы, не удаляет данные и не переключает runtime. Economy rollback всегда создаёт новую аудируемую версию.
    </footer>
  </main>
</body>
</html>
