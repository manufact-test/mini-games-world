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
  <link rel="stylesheet" href="./assets/css/admin-shell.css?v=1">
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="./assets/js/admin-shell.js?v=1" defer></script>
</head>
<body>
  <main class="mgw-admin" data-admin-api="../bot/admin-read.php">
    <header class="mgw-admin__header">
      <div>
        <p class="mgw-admin__eyebrow">MINI GAMES WORLD</p>
        <h1>Web Admin</h1>
        <p class="mgw-admin__subtitle">Безопасный read-only обзор текущего состояния.</p>
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
    </section>

    <footer class="mgw-admin__footer">
      В этой версии веб-панели нет финансовых операций, удаления данных или runtime-переключателей.
    </footer>
  </main>
</body>
</html>
