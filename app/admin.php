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
  <title>Mini Games World · Панель администратора</title>
  <link rel="stylesheet" href="./assets/css/admin-shell.css?v=17&mvp22_7=tasks-compact-ru-v2&mvp22_6=product-economy-analytics-v1&mvp22_5=system-status-ru-ux-v2&replay=17-6&mvp22_4=admin-ux-scale-v1&manual_acceptance=v7-support-lifecycle-v3&mvp22_1=support-tickets&mvp22_2=compensation-ux-v4&economy_ui=collapsed-technical-v1&mvp22_3=moderation-v2-report-cards&mvp20_8=rating-admin&mvp21_1=tournament-registration&mvp21_10=prize-review-v1&mvp21_manual=admin-ui-v1">\n  <link rel="stylesheet" href="./assets/css/admin-incident.css?v=1&mvp22_9=incident-recovery-v1">
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="./assets/js/admin-shell.js?v=11&mvp22_9=incident-nav-v1&mvp22_7=operations-nav-v1&mvp22_6=analytics-nav-v1&mvp22_4=admin-ux-scale-v1&test-coins=staging" defer></script>
  <script src="./assets/js/admin-system.js?v=2&mvp22_5=system-status-ru-ux-v2" defer></script>\n  <script src="./assets/js/admin-incident.js?v=1&mvp22_9=incident-recovery-v1" defer></script>
  <script src="./assets/js/admin-analytics.js?v=1&mvp22_6=product-economy-analytics-v1" defer></script>
  <script src="./assets/js/admin-operations.js?v=3&mvp22_7=task-reminders-v1" defer></script>
  <script src="./assets/js/admin-antifraud.js?v=1&mvp22_4=case-nav-v4" defer></script>
  <script src="./assets/js/admin-compensation.js?v=5&mvp22_2=admin-ux-scale-v1" defer></script>
  <script src="./assets/js/admin-reports.js?v=3&mvp18=reports&mvp22_3=admin-ux-scale-v1" defer></script>
  <script src="./assets/js/admin-notifications.js?v=2&mvp18=admin-ux-scale-v1" defer></script>
  <script src="./assets/js/admin-support.js?v=8&mvp22_1=admin-ux-scale-v1&support_focus=ticket-detail-v1&attachment_viewer=inline-v2&reply_files=managed-v1&reply_delivery=bell-verified-v2" defer></script>
  <script src="./assets/js/admin-rating.js?v=3&mvp20_8=admin-ux-scale-v1" defer></script>
  <script src="./assets/js/admin-tournaments.js?v=18&mvp21_3=admin-ux-scale-v1&mvp21_4=staging-reset-reseed-v2&mvp21_5=manual-acceptance-fixes-v3&mvp21_5=corrective-v5&mvp21_6=fixture-progression-helper-v3&mvp21_8=corrective-v12&mvp21_8_cancel=cancellation-emergency-v1&mvp21_10=prize-review-v1&mvp21_manual=admin-ui-v2" defer></script>
</head>
<body>
  <main class="mgw-admin" data-admin-api="../bot/admin-read.php" data-economy-api="../bot/admin-economy.php" data-compensation-api="../bot/admin-compensation.php" data-test-coins-api="../bot/admin-test-coins.php" data-replay-api="../bot/admin-replay.php" data-reports-api="../bot/admin-reports.php" data-support-api="../bot/admin-support.php" data-notifications-api="../bot/admin-notifications.php" data-rating-api="../bot/admin-rating.php" data-tournament-api="../bot/admin-tournaments.php" data-system-api="../bot/admin-system.php" data-incident-api="../bot/admin-incident.php" data-analytics-api="../bot/admin-analytics.php" data-operations-api="../bot/admin-operations.php">
    <header class="mgw-admin__header">
      <div class="mgw-admin__title">
        <p class="mgw-admin__eyebrow">MINI GAMES WORLD</p>
        <h1>Панель администратора</h1>
        <p class="mgw-admin__subtitle">Рабочая панель администратора</p>
      </div>
      <div class="mgw-admin__header-actions">
        <span class="mgw-admin__environment-badge" data-admin-environment-badge>ПРОВЕРКА СРЕДЫ</span>
        <button class="mgw-admin__refresh" type="button" data-admin-refresh>Обновить данные</button>
      </div>
    </header>

    <section class="mgw-admin__status" aria-live="polite" data-admin-status>
      Подключение к Telegram…
    </section>

    <nav class="mgw-admin__nav" aria-label="Разделы панели администратора" data-admin-nav>
      <button type="button" data-admin-nav-target="overview">Обзор</button>
      <button type="button" data-admin-nav-target="analytics">Аналитика</button>
      <button type="button" data-admin-nav-target="operations">Задачи и релизы</button>
      <button type="button" data-admin-nav-target="users">Пользователи</button>
      <button type="button" data-admin-nav-target="antifraud">Проверка игр</button>
      <button type="button" data-admin-nav-target="support">Поддержка</button>
      <button type="button" data-admin-nav-target="tournaments">Турниры и сезоны</button>
      <button type="button" data-admin-nav-target="economy">Экономика</button>
      <button type="button" data-admin-nav-target="notifications">Уведомления</button>
      <button type="button" data-admin-nav-target="system">Система</button>
      <button type="button" data-admin-nav-target="incident">Инциденты</button>
      <button type="button" data-admin-nav-target="tests">Тесты</button>
    </nav>

    <div class="mgw-admin__section-intro" data-admin-section-intro>
      <button class="mgw-admin__section-back" type="button" data-admin-back-overview hidden>← К обзору</button>
      <div class="mgw-admin__section-copy">
        <strong data-admin-section-title>Обзор</strong>
        <span data-admin-section-description>Ключевое состояние продукта и быстрый контроль.</span>
      </div>
    </div>

    <section class="mgw-admin__meta" data-admin-meta hidden>
      <div><span>Среда</span><strong data-admin-environment>—</strong></div>
      <div><span>Сборка</span><strong data-admin-build>—</strong></div>
      <div><span>Обновлено</span><strong data-admin-generated>—</strong></div>
    </section>

    <section class="mgw-admin__grid" data-admin-content hidden>
      <article class="mgw-admin__card" data-admin-section="overview">
        <div class="mgw-admin__card-head">
          <h2>Операционная сводка</h2>
          <span>Только просмотр</span>
        </div>
        <div class="mgw-admin__overview-live">
          <button type="button" data-admin-shortcut="system">
            <span>Система</span>
            <strong data-overview-system>Проверка…</strong>
            <small data-overview-system-note>Состояние системы</small>
          </button>
          <button type="button" data-admin-shortcut="support">
            <span>Поддержка</span>
            <strong data-overview-support>—</strong>
            <small data-overview-support-note>Открытых обращений</small>
          </button>
          <button type="button" data-admin-shortcut="tournaments">
            <span>Турнир</span>
            <strong data-overview-tournament>—</strong>
            <small data-overview-tournament-note>Текущий статус</small>
          </button>
          <button type="button" data-admin-shortcut="tournaments">
            <span>Сезон</span>
            <strong data-overview-season>—</strong>
            <small data-overview-season-note>Состояние соревнований</small>
          </button>
        </div>
        <div class="mgw-admin__overview" data-admin-dashboard>—</div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="analytics" data-admin-analytics>
        <div class="mgw-admin__card-head">
          <h2>Аналитика</h2>
          <span>Продукт и экономика</span>
        </div>

        <div class="mgw-admin__analytics-status" data-analytics-status>Загружаю показатели…</div>

        <div class="mgw-admin__analytics-kpis">
          <div><span>Реальных аккаунтов</span><strong data-analytics-kpi="users">—</strong><small>без тестовых участников</small></div>
          <div><span>Активны за 30 дней</span><strong data-analytics-kpi="active30">—</strong><small>по последней активности</small></div>
          <div><span>Матчей за 30 дней</span><strong data-analytics-kpi="games30">—</strong><small>с реальным игроком</small></div>
          <div><span>Баланс экономики за 30 дней</span><strong data-analytics-kpi="net30">—</strong><small>источники минус сжигание</small></div>
        </div>

        <div class="mgw-admin__analytics-grid">
          <section class="mgw-admin__analytics-panel">
            <div class="mgw-admin__analytics-head">
              <h3>Пользователи</h3>
              <span>Регистрации и активность</span>
            </div>
            <div class="mgw-admin__analytics-metrics" data-analytics-users></div>
          </section>

          <section class="mgw-admin__analytics-panel">
            <div class="mgw-admin__analytics-head">
              <h3>Возврат пользователей</h3>
              <span>По доступной истории</span>
            </div>
            <div class="mgw-admin__analytics-metrics" data-analytics-retention></div>
            <p class="mgw-admin__analytics-note" data-analytics-retention-note></p>
          </section>

          <section class="mgw-admin__analytics-panel mgw-admin__analytics-panel--wide">
            <div class="mgw-admin__analytics-head">
              <h3>Игры</h3>
              <span>Матчи с реальными игроками</span>
            </div>
            <div class="mgw-admin__analytics-metrics mgw-admin__analytics-metrics--4" data-analytics-games-summary></div>
            <div class="mgw-admin__analytics-bars" data-analytics-games></div>
          </section>

          <section class="mgw-admin__analytics-panel">
            <div class="mgw-admin__analytics-head">
              <h3>Подбор соперников</h3>
              <span>Существующая телеметрия</span>
            </div>
            <div class="mgw-admin__analytics-metrics" data-analytics-matchmaking></div>
            <p class="mgw-admin__analytics-note" data-analytics-matchmaking-note></p>
          </section>

          <section class="mgw-admin__analytics-panel">
            <div class="mgw-admin__analytics-head">
              <h3>Покупки</h3>
              <span>Косметика за коины</span>
            </div>
            <div class="mgw-admin__analytics-metrics" data-analytics-purchases></div>
            <div class="mgw-admin__analytics-list" data-analytics-offers></div>
          </section>

          <section class="mgw-admin__analytics-panel">
            <div class="mgw-admin__analytics-head">
              <h3>Реклама</h3>
              <span>Показы и доход</span>
            </div>
            <div class="mgw-admin__analytics-empty" data-analytics-ads></div>
          </section>

          <section class="mgw-admin__analytics-panel">
            <div class="mgw-admin__analytics-head">
              <h3>Турниры</h3>
              <span>Только реальные участники в продуктовых счётчиках</span>
            </div>
            <div class="mgw-admin__analytics-metrics" data-analytics-tournaments></div>
            <div class="mgw-admin__analytics-current" data-analytics-current-tournament></div>
          </section>

          <section class="mgw-admin__analytics-panel mgw-admin__analytics-panel--wide">
            <div class="mgw-admin__analytics-head">
              <h3>Коины: источники и сжигание</h3>
              <span>Чистое изменение доступных + зарезервированных коинов</span>
            </div>
            <div class="mgw-admin__analytics-metrics mgw-admin__analytics-metrics--4" data-analytics-coins></div>
            <div class="mgw-admin__analytics-list" data-analytics-coin-categories></div>
          </section>

          <section class="mgw-admin__analytics-panel mgw-admin__analytics-panel--wide">
            <div class="mgw-admin__analytics-head">
              <h3>Согласованность экономики</h3>
              <span>Только чтение</span>
            </div>
            <div class="mgw-admin__analytics-reconciliation" data-analytics-reconciliation></div>
          </section>
        </div>

        <details class="mgw-admin__technical-disclosure mgw-admin__analytics-coverage">
          <summary>Что именно умеет и не умеет эта статистика</summary>
          <div class="mgw-admin__analytics-coverage-body" data-analytics-coverage></div>
        </details>

        <div class="mgw-admin__analytics-footer">
          <span data-analytics-updated>—</span>
          <button type="button" data-analytics-refresh>Обновить аналитику</button>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="operations" data-admin-operations>
        <div class="mgw-admin__card-head">
          <h2>Задачи, планы и релизы</h2>
          <span>Операционная работа</span>
        </div>

        <div class="mgw-admin__operations-status" data-operations-status>Загружаю операционные данные…</div>

        <div class="mgw-admin__operations-kpis">
          <div><span>Активных задач</span><strong data-operations-kpi="tasks">—</strong></div>
          <div><span>Просрочено</span><strong data-operations-kpi="overdue">—</strong></div>
          <div><span>Планов в работе</span><strong data-operations-kpi="plans">—</strong></div>
          <div><span>Релизов в журнале</span><strong data-operations-kpi="releases">—</strong></div>
        </div>

        <details class="mgw-admin__technical-disclosure mgw-admin__operations-panel" open>
          <summary>Регулярные задачи</summary>
          <div class="mgw-admin__operations-body">
            <p class="mgw-admin__operations-help">Здесь хранится рабочий список с ответственным, сроком, статусом и результатом. Срок — это момент напоминания: создатель задачи один раз получает уведомление в колокольчике и сообщение от бота. По просроченной задаче повторного спама нет. Для регулярной задачи следующая итерация создаётся после завершения текущей.</p>

            <div class="mgw-admin__operations-create-grid">
              <label class="mgw-admin__field">
                <span>Задача</span>
                <input data-operations-task-title type="text" maxlength="240" required autocomplete="off" placeholder="Например: проверить отчёт по экономике">
              </label>
              <label class="mgw-admin__field">
                <span>Категория</span>
                <select data-operations-task-category>
                  <option value="operations">Операции</option>
                  <option value="product">Продукт</option>
                  <option value="engineering">Разработка</option>
                  <option value="support">Поддержка</option>
                  <option value="content">Контент</option>
                  <option value="other">Другое</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Повтор</span>
                <select data-operations-task-recurrence>
                  <option value="once">Один раз</option>
                  <option value="daily">Каждый день</option>
                  <option value="weekly">Каждую неделю</option>
                  <option value="monthly">Каждый месяц</option>
                  <option value="quarterly">Каждый квартал</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Ближайший срок</span>
                <input data-operations-task-due type="datetime-local">
              </label>
              <label class="mgw-admin__field">
                <span>Ответственный</span>
                <input data-operations-task-owner type="text" maxlength="191" autocomplete="off" placeholder="Имя или роль">
              </label>
            </div>
            <div class="mgw-admin__operations-actions">
              <button type="button" data-operations-create-task>Добавить задачу</button>
              <button type="button" data-operations-refresh>Обновить</button>
            </div>
            <div class="mgw-admin__operations-feedback" data-operations-task-feedback aria-live="polite" hidden></div>

            <div class="mgw-admin__operations-list" data-operations-tasks></div>

            <details class="mgw-admin__list-disclosure">
              <summary><span><strong>Недавно завершённые</strong><small>Результат и история выполнения</small></span><i aria-hidden="true"></i></summary>
              <div class="mgw-admin__operations-list" data-operations-closed-tasks></div>
            </details>
          </div>
        </details>

        <details class="mgw-admin__technical-disclosure mgw-admin__operations-panel">
          <summary>Подготовка следующего рейтингового сезона</summary>
          <div class="mgw-admin__operations-body">
            <div class="mgw-admin__season-preparation-status" data-operations-season-status></div>
            <div class="mgw-admin__season-preparation-meta" data-operations-season-meta></div>
            <div class="mgw-admin__season-reminders" data-operations-season-reminders></div>

            <div class="mgw-admin__season-checklist" data-operations-season-checklist hidden>
              <label>
                <span>Сезонные награды</span>
                <select data-season-ready="seasonal_awards_state">
                  <option value="pending">Ожидает</option>
                  <option value="ready">Готово</option>
                </select>
              </label>
              <label>
                <span>Рамки топ-3</span>
                <select data-season-ready="top3_frames_state">
                  <option value="pending">Ожидает</option>
                  <option value="ready">Готово</option>
                  <option value="not_required">Не требуется</option>
                </select>
              </label>
              <label>
                <span>Годовая медаль / фрагмент квартала</span>
                <select data-season-ready="yearly_medal_state">
                  <option value="pending">Ожидает</option>
                  <option value="ready">Готово</option>
                  <option value="not_required">Не требуется</option>
                </select>
              </label>
              <label>
                <span>Названия, описания и тексты уведомлений</span>
                <select data-season-ready="localization_state">
                  <option value="pending">Ожидает</option>
                  <option value="ready">Готово</option>
                </select>
              </label>
              <label>
                <span>Предпросмотр и проверка материалов</span>
                <select data-season-ready="preview_validation_state">
                  <option value="pending">Ожидает</option>
                  <option value="ready">Готово</option>
                </select>
              </label>
            </div>
            <div class="mgw-admin__operations-actions" data-operations-season-actions hidden>
              <button type="button" data-operations-save-season>Сохранить готовность пакета</button>
            </div>
            <p class="mgw-admin__operations-help">Финальный статус <strong>«Готово»</strong> вычисляется существующим владельцем сезонного жизненного цикла. Контрольные точки T‑21, T‑14 и T‑7 также берутся оттуда — этот раздел не создаёт второй календарь напоминаний.</p>
          </div>
        </details>

        <details class="mgw-admin__technical-disclosure mgw-admin__operations-panel">
          <summary>Планы на будущее</summary>
          <div class="mgw-admin__operations-body">
            <div class="mgw-admin__operations-create-grid">
              <label class="mgw-admin__field">
                <span>План</span>
                <input data-operations-plan-title type="text" maxlength="240" required autocomplete="off" placeholder="Что хотим сделать позже">
              </label>
              <label class="mgw-admin__field">
                <span>Категория</span>
                <select data-operations-plan-category>
                  <option value="product">Продукт</option>
                  <option value="engineering">Разработка</option>
                  <option value="operations">Операции</option>
                  <option value="content">Контент</option>
                  <option value="growth">Рост</option>
                  <option value="other">Другое</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Статус</span>
                <select data-operations-plan-status>
                  <option value="idea">Идея</option>
                  <option value="planned">Запланировано</option>
                  <option value="in_progress">В работе</option>
                  <option value="blocked">Заблокировано</option>
                  <option value="done">Готово</option>
                  <option value="cancelled">Отменено</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Период</span>
                <input data-operations-plan-period type="text" maxlength="120" autocomplete="off" placeholder="Например: после MVP-22 или Q1 2027">
              </label>
              <label class="mgw-admin__field">
                <span>Ответственный</span>
                <input data-operations-plan-owner type="text" maxlength="191" autocomplete="off" placeholder="Имя или роль">
              </label>
              <label class="mgw-admin__field mgw-admin__field--wide">
                <span>Заметки</span>
                <textarea data-operations-plan-notes rows="3" maxlength="5000" placeholder="Контекст, зависимости, что считать готовым"></textarea>
              </label>
            </div>
            <div class="mgw-admin__operations-actions">
              <button type="button" data-operations-create-plan>Добавить в планы</button>
            </div>
            <div class="mgw-admin__operations-feedback" data-operations-plan-feedback aria-live="polite" hidden></div>
            <div class="mgw-admin__operations-list" data-operations-plans></div>
          </div>
        </details>

        <details class="mgw-admin__technical-disclosure mgw-admin__operations-panel">
          <summary>Журнал релизов</summary>
          <div class="mgw-admin__operations-body">
            <div class="mgw-admin__release-current">
              <span>Текущая сборка</span>
              <strong data-operations-runtime-build>—</strong>
            </div>
            <p class="mgw-admin__operations-help" data-operations-release-coverage>Журнал начинается с MVP-22.7. Старые релизы задним числом не придумываются.</p>

            <div class="mgw-admin__operations-create-grid">
              <label class="mgw-admin__field">
                <span>Версия</span>
                <input data-operations-release-version type="text" maxlength="80" required autocomplete="off" placeholder="Например: 1.22.7">
              </label>
              <label class="mgw-admin__field">
                <span>Среда</span>
                <select data-operations-release-environment>
                  <option value="staging">Тестовая</option>
                  <option value="production">Рабочая</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Полный SHA</span>
                <input data-operations-release-sha type="text" maxlength="40" required autocomplete="off" placeholder="40 символов">
              </label>
              <label class="mgw-admin__field">
                <span>Дата релиза</span>
                <input data-operations-release-date type="datetime-local" required>
              </label>
              <label class="mgw-admin__field mgw-admin__field--wide">
                <span>Что вошло</span>
                <textarea data-operations-release-summary rows="3" maxlength="5000" required placeholder="Короткое описание релиза"></textarea>
              </label>
              <label class="mgw-admin__field mgw-admin__field--wide">
                <span>Известные проблемы</span>
                <textarea data-operations-release-issues rows="3" maxlength="5000" placeholder="Оставьте пустым, если известных проблем нет"></textarea>
              </label>
              <label class="mgw-admin__field mgw-admin__field--wide">
                <span>Ссылка на инструкцию / точку отката</span>
                <input data-operations-release-rollback type="url" maxlength="500" autocomplete="off" placeholder="https://github.com/manufact-test/mini-games-world/...">
              </label>
            </div>
            <div class="mgw-admin__operations-actions">
              <button type="button" data-operations-create-release>Добавить запись релиза</button>
            </div>
            <div class="mgw-admin__operations-feedback" data-operations-release-feedback aria-live="polite" hidden></div>
            <div class="mgw-admin__operations-list" data-operations-releases></div>
          </div>
        </details>

        <details class="mgw-admin__technical-disclosure mgw-admin__operations-panel">
          <summary>Журнал изменений раздела</summary>
          <div class="mgw-admin__history" data-operations-audit></div>
        </details>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="system" data-admin-system>
        <div class="mgw-admin__card-head">
          <h2>Система</h2>
          <span>Состояние и безопасные переключатели</span>
        </div>

        <div class="mgw-admin__system-status" data-system-status>Загружаю состояние системы…</div>

        <div class="mgw-admin__system-kpis">
          <div><span>Реальных аккаунтов</span><strong data-system-user-count>—</strong></div>
          <div><span>Порог сезона</span><strong data-system-user-threshold>500</strong></div>
          <div><span>Соревнования</span><strong data-system-competition>—</strong></div>
          <div><span>Готовность</span><strong data-system-readiness>—</strong></div>
        </div>

        <details class="mgw-admin__technical-disclosure mgw-admin__system-panel" open>
          <summary>Аварийные переключатели</summary>
          <div class="mgw-admin__system-body">
            <p class="mgw-admin__system-help">Здесь можно быстро ограничить отдельные функции или игры без изменения их кода. Все изменения записываются в существующую конфигурацию системы.</p>

            <label class="mgw-admin__switch-row">
              <input type="checkbox" data-system-maintenance>
              <span><strong>Технические работы</strong><small>Временно ограничивает работу приложения и показывает пользователям заданное сообщение.</small></span>
            </label>
            <label class="mgw-admin__field">
              <span>Сообщение пользователям</span>
              <textarea data-system-maintenance-message rows="2" maxlength="500" placeholder="Например: идут технические работы, скоро вернёмся."></textarea>
            </label>
            <label class="mgw-admin__switch-row">
              <input type="checkbox" data-system-financial-read-only>
              <span><strong>Запретить новые финансовые операции</strong><small>Останавливает новые списания и начисления через финансовые операции. Уже записанная история баланса не меняется.</small></span>
            </label>

            <h3>Функции</h3>
            <div class="mgw-admin__system-toggle-grid">
              <label><input type="checkbox" data-system-feature="matchmaking"><span>Подбор соперников</span></label>
              <label><input type="checkbox" data-system-feature="invitations"><span>Приглашения</span></label>
              <label><input type="checkbox" data-system-feature="payments"><span>Платежи</span></label>
              <label><input type="checkbox" data-system-feature="shop"><span>Магазин</span></label>
              <label><input type="checkbox" data-system-feature="tournaments"><span>Турниры</span></label>
              <label><input type="checkbox" data-system-feature="ads"><span>Реклама</span></label>
            </div>

            <h3>Игры</h3>
            <div class="mgw-admin__system-toggle-grid">
              <label><input type="checkbox" data-system-game="tictactoe"><span>Крестики-нолики</span></label>
              <label><input type="checkbox" data-system-game="four_in_a_row"><span>Четыре в ряд</span></label>
              <label><input type="checkbox" data-system-game="battleship"><span>Морской бой</span></label>
              <label><input type="checkbox" data-system-game="checkers"><span>Русские шашки</span></label>
              <label><input type="checkbox" data-system-game="reversi"><span>Реверси</span></label>
              <label><input type="checkbox" data-system-game="chess"><span>Шахматы</span></label>
              <label><input type="checkbox" data-system-game="go"><span>Го</span></label>
              <label><input type="checkbox" data-system-game="domino"><span>Домино</span></label>
            </div>

            <label class="mgw-admin__field">
              <span>Причина изменения</span>
              <input data-system-flag-reason type="text" maxlength="800" autocomplete="off" placeholder="Коротко укажите, что меняем и зачем">
            </label>
            <div class="mgw-admin__system-actions">
              <button type="button" data-system-save-flags>Сохранить переключатели</button>
              <button type="button" data-system-refresh>Обновить состояние</button>
            </div>
          </div>
        </details>

        <details class="mgw-admin__technical-disclosure mgw-admin__system-panel">
          <summary>Первый официальный рейтинговый сезон</summary>
          <div class="mgw-admin__system-body">
            <div class="mgw-admin__system-readiness" data-system-readiness-alert hidden>
              <strong>Достигнут порог готовности.</strong>
              <span>Это только сигнал администратору. Официальный сезон сам не запускается.</span>
              <label class="mgw-admin__field">
                <span>Причина подтверждения</span>
                <input data-system-readiness-reason type="text" maxlength="800" autocomplete="off" placeholder="Например: порог проверен, продолжаем подготовку">
              </label>
              <button type="button" data-system-ack-readiness>Подтвердить уведомление</button>
            </div>

            <div class="mgw-admin__system-rehearsal" data-system-rehearsal>
              <h3>Репетиция запуска на тестовой среде</h3>
              <p>Временно переводит только тестовую среду из «Предсезона» в состояние «Активно», чтобы вручную проверить официальный сезон. После проверки тестовую среду можно вернуть обратно в «Предсезон»; журнал действий при этом сохраняется.</p>
              <label class="mgw-admin__field">
                <span>Причина</span>
                <input data-system-rehearsal-reason type="text" maxlength="800" autocomplete="off" placeholder="Например: ручная проверка первого официального сезона">
              </label>
              <div class="mgw-admin__system-actions">
                <button type="button" data-system-start-rehearsal>Начать репетицию</button>
                <button type="button" data-system-stop-rehearsal>Вернуть тестовую среду в «Предсезон»</button>
              </div>
            </div>

            <h3>Проверка перед первым официальным запуском</h3>
            <p class="mgw-admin__system-help">Эти пункты подтверждаются только после реальной ручной проверки соответствующего сценария. Они не включают сезон сами по себе.</p>
            <div class="mgw-admin__system-checklist" data-system-checklist>
              <label><input type="checkbox" data-system-check="official_season_rehearsal"><span>Официальный сезон запущен и проверен на тестовой среде.</span></label>
              <label><input type="checkbox" data-system-check="arena_presentation"><span>Арена и официальный рейтинг отображаются правильно.</span></label>
              <label><input type="checkbox" data-system-check="profile_presentation"><span>В профиле правильно показаны текущий и предыдущий сезоны.</span></label>
              <label><input type="checkbox" data-system-check="seasonal_badges"><span>Сезонные значки за 1-е, 2-е и 3-е места проверены.</span></label>
              <label><input type="checkbox" data-system-check="top3_frames"><span>Рамки призёров и срок их действия в следующем сезоне проверены.</span></label>
              <label><input type="checkbox" data-system-check="yearly_medal"><span>Четыре части годовой медали, пропуски и итоговая сборка проверены.</span></label>
              <label><input type="checkbox" data-system-check="archive_hof"><span>Архив сезонов, топ-100 и Зал славы с тройкой лидеров проверены.</span></label>
              <label><input type="checkbox" data-system-check="ready_close"><span>Закрытие сезона с подготовленными наградами проверено.</span></label>
              <label><input type="checkbox" data-system-check="missing_assets_recovery"><span>Сценарий отсутствующих материалов и повторное восстановление проверены.</span></label>
              <label><input type="checkbox" data-system-check="review_recalculation"><span>Исключение результата, пересчёт, награды и архив после пересчёта проверены.</span></label>
              <label><input type="checkbox" data-system-check="identity_exclusions"><span>Боты и тестовые аккаунты не попадают в официальные результаты.</span></label>
              <label><input type="checkbox" data-system-check="idempotency_retries"><span>Повторные запросы не создают дубликаты наград, медалей, закрытия сезона и объявления.</span></label>
              <label><input type="checkbox" data-system-check="launch_notification_localization"><span>Текст объявления о запуске и его локализация проверены.</span></label>
              <label><input type="checkbox" data-system-check="staging_e2e_green"><span>Полная автоматическая проверка тестовой среды прошла успешно после репетиции.</span></label>
              <label><input type="checkbox" data-system-check="manual_acceptance"><span>Ручная приёмка тестовой среды подтверждена.</span></label>
            </div>
            <label class="mgw-admin__field">
              <span>Версия тестовой среды (SHA)</span>
              <input data-system-staging-sha type="text" maxlength="40" autocomplete="off" placeholder="Полный идентификатор из 40 символов">
            </label>
            <label class="mgw-admin__field">
              <span>Заметки по приёмке</span>
              <textarea data-system-staging-notes rows="3" maxlength="2000" placeholder="Коротко запишите, что именно проверено вручную"></textarea>
            </label>
            <button type="button" data-system-accept-staging>Зафиксировать ручную приёмку</button>

            <div class="mgw-admin__system-activation" data-system-production-activation>
              <h3>Запуск на боевом сервере</h3>
              <p>Перевод из «Предсезона» в состояние «Активно» начинает официальную сезонную историю с момента запуска. Результаты предсезона задним числом официальными не становятся. После успешного запуска всем пользователям один раз отправляется объявление.</p>
              <label class="mgw-admin__field">
                <span>Причина запуска</span>
                <input data-system-activation-reason type="text" maxlength="800" autocomplete="off" placeholder="Почему запускаем официальный рейтинговый сезон">
              </label>
              <label class="mgw-admin__switch-row mgw-admin__switch-row--danger">
                <input type="checkbox" data-system-activation-confirm>
                <span><strong>Подтверждаю последствия запуска</strong><small>Действие записывается в аудит. Матчи предсезона не превращаются задним числом в официальную историю.</small></span>
              </label>
              <button type="button" data-system-activate-production disabled>Запустить официальный рейтинговый сезон</button>
            </div>
          </div>
        </details>

        <details class="mgw-admin__technical-disclosure mgw-admin__system-panel">
          <summary>Журнал системных действий</summary>
          <div class="mgw-admin__history" data-system-audit></div>
        </details>

        <details class="mgw-admin__technical-disclosure">
          <summary>Показать техническую диагностику</summary>
          <pre data-admin-system-check>—</pre>
        </details>
      </article>


      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="incident" data-admin-incident>
        <div class="mgw-admin__card-head">
          <h2>Инциденты и восстановление</h2>
          <span>Безопасная операционная консоль</span>
        </div>

        <div class="mgw-admin__incident-status" data-incident-status>Загружаю консоль восстановления…</div>

        <div class="mgw-admin__incident-health">
          <div><span>Среда</span><strong data-incident-environment>—</strong></div>
          <div><span>Сборка</span><strong data-incident-build>—</strong></div>
          <div><span>Схема БД</span><strong data-incident-schema>—</strong></div>
          <div><span>Активных сессий</span><strong data-incident-sessions>—</strong></div>
          <div><span>Режим безопасности</span><strong data-incident-security>—</strong></div>
        </div>

        <section class="mgw-admin__incident-panel" data-incident-empty>
          <div class="mgw-admin__incident-body" style="padding-top:14px">
            <h3>Открыть карточку инцидента</h3>
            <p class="mgw-admin__incident-help">Создайте карточку только для реального операционного события или учебной проверки. Карточка не выполняет никаких разрушительных действий сама по себе.</p>
            <div class="mgw-admin__incident-grid">
              <label class="mgw-admin__field">
                <span>Название</span>
                <input type="text" maxlength="240" autocomplete="off" data-incident-create-title placeholder="Например: подозрительная активность авторизации">
              </label>
              <label class="mgw-admin__field">
                <span>Краткое описание</span>
                <textarea rows="3" maxlength="2000" data-incident-create-summary placeholder="Что произошло и что уже известно"></textarea>
              </label>
            </div>
            <div class="mgw-admin__incident-actions">
              <button type="button" data-incident-create>Открыть инцидент</button>
              <button type="button" data-incident-refresh>Обновить состояние</button>
            </div>
          </div>
        </section>

        <div data-incident-active hidden>
          <section class="mgw-admin__incident-panel">
            <div class="mgw-admin__incident-body" style="padding-top:14px">
              <div class="mgw-admin__incident-card">
                <h3 data-incident-active-title>—</h3>
                <small data-incident-active-meta></small>
                <p data-incident-active-summary></p>
                <input type="hidden" data-incident-active-status>
              </div>
              <div class="mgw-admin__incident-grid" style="margin-top:10px">
                <label class="mgw-admin__field">
                  <span>Этап</span>
                  <select data-incident-lifecycle-status>
                    <option value="open">Открыт</option>
                    <option value="mitigating">Локализация</option>
                    <option value="recovering">Восстановление</option>
                    <option value="resolved">Завершён</option>
                  </select>
                </label>
                <label class="mgw-admin__field">
                  <span>Причина изменения</span>
                  <input type="text" maxlength="800" autocomplete="off" data-incident-lifecycle-reason placeholder="Что изменилось и почему">
                </label>
              </div>
              <div class="mgw-admin__incident-actions">
                <button type="button" data-incident-lifecycle-save>Сохранить этап</button>
                <button type="button" data-incident-refresh>Обновить состояние</button>
              </div>
            </div>
          </section>

          <details class="mgw-admin__incident-panel" open>
            <summary>Режим безопасности и сессии</summary>
            <div class="mgw-admin__incident-body">
              <p class="mgw-admin__incident-warning"><strong>Опасные действия не выполняются одним кликом.</strong> Первый администратор создаёт запрос, а выполнить или отклонить его должен другой авторизованный администратор.</p>
              <p class="mgw-admin__incident-help">Режим безопасности повторно использует существующие системные переключатели: технические работы + запрет новых финансовых операций. Выход восстанавливает точное состояние переключателей, которое было до инцидента.</p>
              <label class="mgw-admin__field">
                <span>Причина действия</span>
                <input type="text" maxlength="800" autocomplete="off" data-incident-risk-reason placeholder="Почему это действие необходимо">
              </label>
              <div class="mgw-admin__incident-actions">
                <button type="button" class="mgw-admin__incident-danger" data-incident-request-risk="enable_security_mode">Запросить режим безопасности</button>
                <button type="button" data-incident-request-risk="disable_security_mode">Запросить выход из режима безопасности</button>
                <button type="button" class="mgw-admin__incident-danger" data-incident-request-risk="revoke_all_sessions">Запросить отзыв всех сессий</button>
              </div>
              <div class="mgw-admin__incident-actions-list" data-incident-actions style="margin-top:12px"></div>
            </div>
          </details>

          <details class="mgw-admin__incident-panel">
            <summary>Проверка ключей и доступов</summary>
            <div class="mgw-admin__incident-body">
              <p class="mgw-admin__incident-warning">Никогда не вставляйте сюда сами токены, пароли или секреты. Здесь фиксируется только факт проверки или замены.</p>
              <div class="mgw-admin__incident-key-list">
                <div class="mgw-admin__incident-key" data-incident-key-row="telegram_bot_token">
                  <div class="mgw-admin__incident-key-name"><strong data-incident-key-name>Telegram Bot Token</strong><small data-incident-key-meta>Нужно проверить</small></div>
                  <label class="mgw-admin__field"><span>Статус</span><select data-incident-key-status><option value="pending">Нужно проверить</option><option value="rotated">Заменён</option><option value="verified">Проверен</option><option value="not_applicable">Не требуется</option></select></label>
                  <label class="mgw-admin__field"><span>Заметка без секрета</span><input type="text" maxlength="800" autocomplete="off" data-incident-key-note placeholder="Например: заменён в Hostinger"></label>
                  <button type="button" data-incident-save-key>Сохранить</button>
                </div>
                <div class="mgw-admin__incident-key" data-incident-key-row="database_credentials">
                  <div class="mgw-admin__incident-key-name"><strong data-incident-key-name>Доступ к базе данных</strong><small data-incident-key-meta>Нужно проверить</small></div>
                  <label class="mgw-admin__field"><span>Статус</span><select data-incident-key-status><option value="pending">Нужно проверить</option><option value="rotated">Заменён</option><option value="verified">Проверен</option><option value="not_applicable">Не требуется</option></select></label>
                  <label class="mgw-admin__field"><span>Заметка без секрета</span><input type="text" maxlength="800" autocomplete="off" data-incident-key-note placeholder="Только факт проверки"></label>
                  <button type="button" data-incident-save-key>Сохранить</button>
                </div>
                <div class="mgw-admin__incident-key" data-incident-key-row="account_data_hook_secret">
                  <div class="mgw-admin__incident-key-name"><strong data-incident-key-name>Ключ запроса данных аккаунта</strong><small data-incident-key-meta>Нужно проверить</small></div>
                  <label class="mgw-admin__field"><span>Статус</span><select data-incident-key-status><option value="pending">Нужно проверить</option><option value="rotated">Заменён</option><option value="verified">Проверен</option><option value="not_applicable">Не требуется</option></select></label>
                  <label class="mgw-admin__field"><span>Заметка без секрета</span><input type="text" maxlength="800" autocomplete="off" data-incident-key-note placeholder="Только факт проверки"></label>
                  <button type="button" data-incident-save-key>Сохранить</button>
                </div>
              </div>
            </div>
          </details>

          <details class="mgw-admin__incident-panel">
            <summary>Сохранение доказательств</summary>
            <div class="mgw-admin__incident-body">
              <p class="mgw-admin__incident-help">Сохраняйте ссылки, идентификаторы запусков и SHA. Сырые секреты, токены и приватные ключи в карточку инцидента не записываются.</p>
              <div class="mgw-admin__incident-grid mgw-admin__incident-grid--3">
                <label class="mgw-admin__field"><span>Тип</span><select data-incident-evidence-type><option value="commit_sha">Commit SHA</option><option value="workflow_run">Запуск CI</option><option value="backup_reference">Резервная копия</option><option value="log_reference">Ссылка на лог</option><option value="other">Другое</option></select></label>
                <label class="mgw-admin__field"><span>Название</span><input type="text" maxlength="240" autocomplete="off" data-incident-evidence-label placeholder="Что сохраняем"></label>
                <label class="mgw-admin__field"><span>Ссылка / идентификатор</span><input type="text" maxlength="500" autocomplete="off" data-incident-evidence-reference placeholder="Без секретных значений"></label>
                <label class="mgw-admin__field"><span>SHA-256, если есть</span><input type="text" maxlength="64" autocomplete="off" data-incident-evidence-fingerprint placeholder="64 шестнадцатеричных символа"></label>
              </div>
              <div class="mgw-admin__incident-actions"><button type="button" data-incident-add-evidence>Сохранить доказательство</button></div>
              <div class="mgw-admin__incident-history" data-incident-evidence-list style="margin-top:12px"></div>
            </div>
          </details>

          <details class="mgw-admin__incident-panel">
            <summary>Статус восстановления</summary>
            <div class="mgw-admin__incident-body">
              <p class="mgw-admin__incident-help">Консоль фиксирует состояние и безопасные ссылки на backup/rollback. Она не выполняет молча восстановление базы данных или откат production.</p>
              <div class="mgw-admin__incident-grid">
                <label class="mgw-admin__field"><span>Статус</span><select data-incident-restore-status><option value="not_started">Не начато</option><option value="preparing">Подготовка</option><option value="ready">Готово к проверке</option><option value="verified">Проверено</option><option value="blocked">Заблокировано</option></select></label>
                <label class="mgw-admin__field"><span>Ссылка на backup</span><input type="text" maxlength="500" autocomplete="off" data-incident-restore-backup placeholder="Без секретных данных"></label>
                <label class="mgw-admin__field"><span>Ссылка на rollback</span><input type="text" maxlength="500" autocomplete="off" data-incident-restore-rollback placeholder="Например, GitHub commit/branch"></label>
                <label class="mgw-admin__field"><span>SHA восстановления</span><input type="text" maxlength="40" autocomplete="off" data-incident-restore-sha placeholder="Полный 40-символьный SHA"></label>
                <label class="mgw-admin__field"><span>Заметки</span><textarea rows="3" maxlength="1200" data-incident-restore-notes placeholder="Что подготовлено и что проверено"></textarea></label>
              </div>
              <div class="mgw-admin__incident-actions"><button type="button" data-incident-save-restore>Сохранить статус восстановления</button></div>
            </div>
          </details>

          <details class="mgw-admin__incident-panel">
            <summary>Журнал инцидента</summary>
            <div class="mgw-admin__incident-body">
              <div class="mgw-admin__incident-history" data-incident-audit></div>
            </div>
          </details>
        </div>

        <details class="mgw-admin__incident-panel">
          <summary>Недавние инциденты</summary>
          <div class="mgw-admin__incident-body">
            <div class="mgw-admin__incident-history" data-incident-archive></div>
          </div>
        </details>

        <div class="mgw-admin__incident-rehearsal" data-incident-rehearsal hidden>
          <strong>Учебная симуляция на тестовой среде</strong>
          <p>Проверяет карточку инцидента, доказательства и статус восстановления. Не включает режим безопасности, не отзывает сессии и не изменяет production.</p>
          <button type="button" data-incident-run-rehearsal>Запустить безопасную симуляцию</button>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="tournaments" data-tournament-admin>
        <div class="mgw-admin__card-head">
          <h2>Официальный турнир</h2>
          <span>Управление турниром</span>
        </div>
        <div class="mgw-admin__tournament">
          <div class="mgw-admin__tournament-status" data-tournament-admin-status>Управление турниром ещё не загружено.</div>

          <div class="mgw-admin__tournament-summary" data-tournament-admin-summary></div>

          <div class="mgw-admin__tournament-grid">
            <section class="mgw-admin__tournament-panel">
              <h3>Новый официальный турнир</h3>
              <label class="mgw-admin__field">
                <span>Название</span>
                <input data-tournament-title type="text" maxlength="160" autocomplete="off" placeholder="Официальный турнир">
              </label>
              <label class="mgw-admin__field">
                <span>Игра</span>
                <select data-tournament-game>
                  <option value="tictactoe">Крестики-нолики</option>
                  <option value="four_in_a_row">Четыре в ряд</option>
                  <option value="battleship">Морской бой</option>
                  <option value="checkers">Русские шашки</option>
                  <option value="reversi">Реверси</option>
                  <option value="chess">Шахматы</option>
                  <option value="go">Го</option>
                  <option value="domino">Домино</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Количество участников</span>
                <select data-tournament-capacity>
                  <option value="8">8</option>
                  <option value="16">16</option>
                  <option value="32">32</option>
                  <option value="64">64</option>
                  <option value="128">128</option>
                </select>
              </label>
              <div class="mgw-admin__tournament-actions">
                <button type="button" data-tournament-create>Создать черновик</button>
                <button type="button" data-tournament-refresh>Обновить</button>
              </div>
              <small>Взнос фиксирован: 50 000 коинов MGW. При регистрации сумма резервируется и пока не списывается.</small>
            </section>

            <section class="mgw-admin__tournament-panel">
              <h3>Текущий турнир</h3>
              <div class="mgw-admin__tournament-current" data-tournament-current>Официальный турнир ещё не создан.</div>
              <div class="mgw-admin__tournament-actions">
                <button type="button" data-tournament-open disabled>Открыть регистрацию</button>
              </div>
              
              
              
              <div class="mgw-admin__tournament-schedule" data-tournament-cancel-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-cancel-info>Отмена турнира недоступна.</div>
                <label class="mgw-admin__field">
                  <span>Причина</span>
                  <textarea class="mgw-admin__tournament-reason" data-tournament-cancel-reason rows="3" maxlength="1200" placeholder="Опишите причину"></textarea>
                  <small class="mgw-admin__field-help">Для обычной отмены необязательно. Для аварийной остановки — обязательно.</small>
                </label>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-cancel disabled>Отменить турнир</button>
                  <button type="button" data-tournament-emergency disabled>Аварийная остановка</button>
                </div>
                <small>Обе операции требуют второго подтверждения. Всем зарегистрированным участникам возвращается полный взнос 50 000, турнирные результаты аннулируются и сохраняются только как аудит. Перенос даты здесь не выполняется.</small>
              </div>
              <div class="mgw-admin__tournament-schedule mgw-admin__tournament-review" data-tournament-review-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-review-info>Призовая проверка не активна.</div>
                <div class="mgw-admin__tournament-review-grid">
                  <label class="mgw-admin__field">
                    <span>MGW-ID игрока</span>
                    <input data-tournament-review-mgw-id type="text" maxlength="24" autocomplete="off" placeholder="MGW-…">
                  </label>
                  <label class="mgw-admin__field">
                    <span>Серьёзный сигнал</span>
                    <select data-tournament-review-signal>
                      <option value="fraud">Мошенничество</option>
                      <option value="automation">Автоматизация / бот</option>
                      <option value="duplicate_identity">Дублирующая личность</option>
                      <option value="match_manipulation">Манипуляция матчем</option>
                      <option value="cheating_report">Жалоба на нечестную игру</option>
                      <option value="manual_review">Ручная проверка</option>
                    </select>
                  </label>
                  <label class="mgw-admin__field">
                    <span>ID матча (для отмеченного матча)</span>
                    <input data-tournament-review-game-id type="text" maxlength="96" autocomplete="off" placeholder="Можно оставить пустым только для уже определённого призёра">
                  </label>
                  <label class="mgw-admin__field">
                    <span>Основание сигнала</span>
                    <input data-tournament-review-note type="text" maxlength="800" autocomplete="off" placeholder="Что именно требует тяжёлой проверки">
                  </label>
                </div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-review-flag>Поставить призовой путь на проверку</button>
                </div>
                <small>Углублённая проверка применяется только к призёру или участнику явно отмеченного матча. Сам сигнал не списывает и не начисляет деньги: он только удерживает затронутую призовую ветку до решения администратора.</small>
                <div class="mgw-admin__history" data-tournament-review-list></div>
                <nav class="mgw-admin__pager" data-tournament-review-pagination aria-label="Страницы призовых проверок" hidden></nav>
              </div>
              <div class="mgw-admin__tournament-schedule" data-tournament-schedule-panel hidden>
                <label class="mgw-admin__field">
                  <span>Дата начала</span>
                  <input data-tournament-start-date type="date">
                </label>
                <label class="mgw-admin__field">
                  <span>Время начала</span>
                  <input data-tournament-start-time type="time" step="60">
                </label>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-assign-date disabled>Назначить дату</button>
                </div>
                <div class="mgw-admin__tournament-current" data-tournament-schedule-info>Дата ещё не назначена.</div>
                <small>Время вводится в часовом поясе этого устройства и сохраняется как UTC. После назначения дата фиксируется: перенос и задержка не входят в MVP-21.3. Игроки увидят время в своём часовом поясе и обратный отсчёт.</small>
              </div>
              <small>Одновременно может существовать только один активный официальный турнир. Правила и снимок наград фиксируются при создании. Существенно изменить правила у уже открытого турнира нельзя — для этого нужен новый турнир.</small>
            </section>
          </div>

          <details class="mgw-admin__tournament-panel mgw-admin__list-disclosure">
            <summary>
              <span>
                <strong>Правила турнира</strong>
                <small>Зафиксированный снимок правил</small>
              </span>
              <i aria-hidden="true"></i>
            </summary>
            <pre class="mgw-admin__tournament-rewards" data-tournament-rules>—</pre>
          </details>

          <details class="mgw-admin__tournament-panel mgw-admin__list-disclosure">
            <summary>
              <span>
                <strong>Снимок наград</strong>
                <small>Призы и условия текущего турнира</small>
              </span>
              <i aria-hidden="true"></i>
            </summary>
            <pre class="mgw-admin__tournament-rewards" data-tournament-rewards>—</pre>
          </details>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="tournaments" data-rating-admin>
        <div class="mgw-admin__card-head">
          <h2>Сезоны и рейтинг</h2>
          <span>Проверки и пересчёты</span>
        </div>
        <div class="mgw-admin__rating">
          <div class="mgw-admin__rating-status" data-rating-status>Сезонные данные ещё не загружены.</div>

          <div class="mgw-admin__rating-metrics" data-rating-metrics></div>

          <div class="mgw-admin__rating-actions">
            <button type="button" data-rating-refresh>Обновить рейтинг</button>
            <button type="button" data-rating-rehearsal>Репетиция закрытия сезона</button>
          </div>

          <div class="mgw-admin__rating-grid">
            <section class="mgw-admin__rating-panel">
              <h3>Исключение после проверки</h3>
              <label class="mgw-admin__field">
                <span>Сезон</span>
                <input data-rating-season-id type="text" maxlength="64" autocomplete="off" placeholder="2026-q4">
              </label>
              <label class="mgw-admin__field">
                <span>MGW-ID игрока</span>
                <input data-rating-mgw-id type="text" maxlength="64" autocomplete="off" placeholder="MGW-ID-…">
              </label>
              <label class="mgw-admin__field">
                <span>Причина</span>
                <select data-rating-reason-code>
                  <option value="fraud">Мошенничество</option>
                  <option value="automation">Автоматизация / бот</option>
                  <option value="duplicate_identity">Дублирующая личность</option>
                  <option value="match_manipulation">Манипуляция матчем</option>
                  <option value="manual_review">Ручная проверка</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Комментарий проверки</span>
                <input data-rating-review-note type="text" maxlength="500" autocomplete="off" placeholder="Что проверено и почему игрок исключается">
              </label>
              <div class="mgw-admin__rating-actions">
                <button type="button" data-rating-exclude>Исключить после проверки</button>
              </div>
              <small>Исключение не переписывает историю матчей или рейтинг. После проверки отдельный пересчёт обновляет награды и медаль.</small>
            </section>

            <section class="mgw-admin__rating-panel">
              <h3>Пересчёт</h3>
              <label class="mgw-admin__field">
                <span>Причина пересчёта</span>
                <input data-rating-recalc-reason type="text" maxlength="500" autocomplete="off" placeholder="Корректировка после ручной проверки">
              </label>
              <div class="mgw-admin__rating-actions">
                <button type="button" data-rating-recalculate>Пересчитать закрытый сезон</button>
              </div>
              <small>Доступно только для финализируемого или закрытого сезона. Используются действующие ручные исключения и существующие механизмы наград.</small>
              <details class="mgw-admin__technical-disclosure mgw-admin__rating-rehearsal-wrap">
                <summary>Технический результат репетиции</summary>
                <pre class="mgw-admin__rating-rehearsal" data-rating-rehearsal-output>Репетиция ещё не запускалась.</pre>
              </details>
            </section>
          </div>

          <section class="mgw-admin__rating-panel">
            <h3>Активные исключения</h3>
            <label class="mgw-admin__field mgw-admin__compact-search">
              <span>Поиск по исключениям</span>
              <input type="search" data-rating-exclusion-query maxlength="120" autocomplete="off" placeholder="MGW-ID, ник или сезон">
            </label>
            <div class="mgw-admin__history" data-rating-exclusions></div>
            <nav class="mgw-admin__pager" data-rating-exclusions-pagination aria-label="Страницы исключений" hidden></nav>
          </section>

          <details class="mgw-admin__rating-panel mgw-admin__list-disclosure">
            <summary>
              <span>
                <strong>Последние пересчёты</strong>
                <small>История запусков и результатов</small>
              </span>
              <i aria-hidden="true"></i>
            </summary>
            <div class="mgw-admin__history" data-rating-jobs></div>
            <nav class="mgw-admin__pager" data-rating-jobs-pagination aria-label="Страницы пересчётов" hidden></nav>
          </details>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="support" data-admin-support>
        <div class="mgw-admin__card-head">
          <h2>Поддержка</h2>
          <span>Очередь обращений</span>
        </div>
        <div class="mgw-admin__support">
          <div class="mgw-admin__support-status" data-support-status>Обращения ещё не загружены.</div>
          <div class="mgw-admin__support-metrics" data-support-metrics></div>

          <div class="mgw-admin__support-tabs" role="tablist" aria-label="Состояние обращений">
            <button type="button" class="is-active" data-support-mode="active">Активные</button>
            <button type="button" data-support-mode="processed">Обработанные</button>
          </div>

          <details class="mgw-admin__support-filterbox">
            <summary>Поиск и фильтры</summary>
            <div class="mgw-admin__support-filters">
            <label class="mgw-admin__field">
              <span>Поиск</span>
              <input data-support-filter-query type="search" maxlength="120" autocomplete="off" placeholder="SUP-… / MGW-ID / тема">
            </label>
            <label class="mgw-admin__field">
              <span>Статус</span>
              <select data-support-filter-status><option value="">Все статусы</option></select>
            </label>
            <label class="mgw-admin__field">
              <span>Приоритет</span>
              <select data-support-filter-priority><option value="">Все приоритеты</option></select>
            </label>
            <label class="mgw-admin__field">
              <span>Категория</span>
              <select data-support-filter-category><option value="">Все категории</option></select>
            </label>
            <label class="mgw-admin__field">
              <span>Платформа</span>
              <select data-support-filter-platform><option value="">Все платформы</option></select>
            </label>
            <button type="button" data-support-refresh>Применить</button>
            </div>
          </details>

          <div class="mgw-admin__support-layout">
            <section class="mgw-admin__support-panel" data-support-queue-panel>
              <h3 data-support-queue-title>Активные обращения</h3>
              <div class="mgw-admin__support-queue" data-support-queue></div>
              <nav class="mgw-admin__pager" data-support-pagination aria-label="Страницы обращений" hidden></nav>
            </section>

            <section class="mgw-admin__support-panel mgw-admin__support-detail" data-support-detail hidden>
              <button class="mgw-admin__support-back" type="button" data-support-back>← К очереди</button>
              <div class="mgw-admin__support-detail-head">
                <div>
                  <span data-support-detail-number>—</span>
                  <h3 data-support-detail-subject>Обращение</h3>
                </div>
                <div class="mgw-admin__support-detail-meta">
                  <span data-support-detail-player>—</span>
                  <span data-support-detail-platform>—</span>
                  <span data-support-detail-category>—</span>
                  <span data-support-detail-owner>Ответственный не назначен</span>
                </div>
              </div>

              <div class="mgw-admin__support-controls">
                <div class="mgw-admin__support-state">
                  <span>Статус</span>
                  <strong data-support-detail-status>—</strong>
                </div>
                <label class="mgw-admin__field" data-support-priority-field>
                  <span>Приоритет</span>
                  <select class="mgw-admin__support-select" data-support-detail-priority></select>
                </label>
                <div class="mgw-admin__support-owner-actions">
                  <button type="button" data-support-assign-self>Взять в работу</button>
                  <button type="button" data-support-close>Закрыть обращение</button>
                </div>
              </div>

              <div class="mgw-admin__support-related-summary" data-support-related-summary>Связанные данные: нет</div>
              <details class="mgw-admin__support-related">
                <summary>Технические данные</summary>
                <div class="mgw-admin__support-related-grid">
                  <label class="mgw-admin__field"><span>ID матча</span><input data-support-related-game type="text" maxlength="96"></label>
                  <label class="mgw-admin__field"><span>ID пополнения</span><input data-support-related-payment type="text" maxlength="96"></label>
                  <label class="mgw-admin__field"><span>ID турнира</span><input data-support-related-tournament type="text" maxlength="64"></label>
                  <label class="mgw-admin__field"><span>ID операции</span><input data-support-related-operation type="text" maxlength="191"></label>
                </div>
                <button type="button" data-support-related-save>Сохранить технические связи</button>
              </details>

              <div class="mgw-admin__support-thread" data-support-thread></div>

              <div class="mgw-admin__support-reply">
                <label class="mgw-admin__field">
                  <span>Ответ</span>
                  <textarea data-support-reply rows="4" maxlength="4000" placeholder="Ответ пользователю"></textarea>
                </label>
                <label class="mgw-admin__field">
                  <span>Вложения · до 3 файлов, 2 МБ каждый</span>
                  <span class="mgw-admin__file-picker">
                    <input data-support-reply-files type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain">
                    <span class="mgw-admin__file-picker-button">Выбрать файлы</span>
                    <strong data-support-file-summary>Файлы не выбраны</strong>
                  </span>
                </label>
                <div class="mgw-admin__reply-file-list" data-support-reply-file-list></div>
                <button type="button" data-support-reply-send>Отправить ответ</button>
              </div>

              <details class="mgw-admin__support-history">
                <summary>История изменений</summary>
                <div data-support-history></div>
              </details>
            </section>
          </div>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="notifications" data-admin-notifications>
        <div class="mgw-admin__card-head">
          <h2>Уведомления</h2>
          <span>Сообщения игрокам</span>
        </div>
        <div class="mgw-admin__economy">
          <div class="mgw-admin__economy-meta">
            <label class="mgw-admin__field">
              <span>Источник</span>
              <select data-notification-source-type>
                <option value="admin">Администратор</option>
                <option value="system">Система</option>
                <option value="support">Поддержка</option>
              </select>
            </label>
            <label class="mgw-admin__field">
              <span>Аудитория</span>
              <select data-notification-audience-type>
                <option value="all">Все игроки</option>
                <option value="one">Один игрок</option>
                <option value="segment">Сегмент</option>
                <option value="platform">Платформа</option>
                <option value="tournament">Участники турнира</option>
                <option value="support">Участник обращения</option>
              </select>
            </label>
          </div>

          <small data-notification-audience-hint>Все текущие MGW-аккаунты.</small>

          <label class="mgw-admin__field" hidden>
            <span>Игрок (MGW-ID)</span>
            <input data-notification-target-mgw-id type="text" maxlength="24" autocomplete="off" placeholder="MGW-ID">
          </label>
          <label class="mgw-admin__field" hidden>
            <span>Платформа</span>
            <input data-notification-platform type="text" maxlength="32" autocomplete="off" placeholder="telegram">
          </label>
          <label class="mgw-admin__field" hidden>
            <span>Идентификатор аудитории</span>
            <input data-notification-audience-ref type="text" maxlength="191" autocomplete="off" placeholder="сегмент / турнир / ID обращения">
          </label>
          <label class="mgw-admin__field" hidden>
            <span>Получатели (MGW-ID)</span>
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
            <span>Переход</span>
            <select data-notification-deep-link>
              <option value="">без перехода</option>
              <option value="home">Главная</option>
              <option value="profile">Профиль</option>
              <option value="store">Магазин</option>
              <option value="store:orders">Магазин · заказы</option>
            </select>
          </label>

          <div class="mgw-admin__economy-meta">
            <label class="mgw-admin__field">
              <span>Отправить не раньше</span>
              <input data-notification-scheduled-at type="datetime-local">
            </label>
            <label class="mgw-admin__field">
              <span>Срок действия</span>
              <input data-notification-expires-at type="datetime-local">
            </label>
          </div>

          <div class="mgw-admin__economy-actions">
            <button type="button" data-notification-event-send>Создать уведомление</button>
            <button type="button" data-notification-event-refresh>Обновить</button>
            <small>Отложенная отправка и срок действия используют существующий центр уведомлений.</small>
          </div>
          <div class="mgw-admin__replay-status" data-notification-event-status>Уведомления ещё не загружены.</div>
          <details class="mgw-admin__list-disclosure mgw-admin__notification-history">
            <summary>
              <span>
                <strong>История уведомлений</strong>
                <small>Последние созданные события</small>
              </span>
              <i aria-hidden="true"></i>
            </summary>
            <div class="mgw-admin__history" data-notification-event-list></div>
            <nav class="mgw-admin__pager" data-notification-pagination aria-label="Страницы уведомлений" hidden></nav>
          </details>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="users" data-admin-reports>
        <div class="mgw-admin__card-head">
          <h2>Жалобы и модерация</h2>
          <span>Ручные решения и апелляции</span>
        </div>
        <div class="mgw-admin__reports">
          <div class="mgw-admin__report-toolbar">
            <div class="mgw-admin__report-tabs" role="tablist" aria-label="Состояние жалоб">
              <button type="button" class="is-active" data-report-mode="active">Активные</button>
              <button type="button" data-report-mode="closed">Рассмотренные</button>
            </div>
            <div class="mgw-admin__report-filters">
              <input type="search" data-report-filter-query maxlength="120" autocomplete="off" placeholder="ID, ник, причина или текст">
              <label><span>С</span><input type="date" data-report-filter-from></label>
              <label><span>По</span><input type="date" data-report-filter-to></label>
              <button type="button" data-report-queue-refresh>Применить</button>
            </div>
            <small>Активная очередь не смешивается с архивом. Рассмотренные жалобы остаются доступны через поиск и даты. Постоянная блокировка требует второго администратора.</small>
          </div>
          <div class="mgw-admin__replay-status" data-report-queue-status>Очередь ещё не загружена.</div>
          <div class="mgw-admin__history" data-report-queue-list></div>
          <nav class="mgw-admin__pager" data-report-pagination aria-label="Страницы жалоб" hidden></nav>
        </div>
      </article>


      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="tests" data-tournament-test-tools>
        <div class="mgw-admin__card-head">
          <h2>Тесты турниров</h2>
          <span>Только тестовая среда</span>
        </div>
        <div class="mgw-admin__tournament mgw-admin__test-stack">
<div class="mgw-admin__tournament-schedule" data-tournament-manual-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-manual-info>Ручная проверка тестовой среды недоступна.</div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-prepare-manual disabled>Подготовить 6/8 для двух живых аккаунтов</button>
                </div>
                <small>Только тестовая среда: 6 мест заполняются синтетическими участниками через тот же канонический сервис регистрации и резерв 50 000. Два места остаются двум живым аккаунтам для ручной проверки первого матча.</small>
              </div>
<div class="mgw-admin__tournament-schedule" data-tournament-progression-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-progression-info>Тестовые пары пока не требуют завершения.</div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-complete-fixtures disabled>Завершить тестовые пары</button>
                </div>
                <small>Только тестовая среда: завершает только пары, где оба участника — синтетические тестовые участники. Результат проходит через канонический сервис турнирного прогресса, не создаёт второй взнос и не затрагивает реально сыгранную пару.</small>
              </div>
<div class="mgw-admin__tournament-schedule" data-tournament-reset-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-reset-info>Сброс тестового турнира недоступен.</div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-reset-manual disabled>Сбросить тестовый турнир</button>
                </div>
                <small>Только тестовая среда: все турнирные резервы освобождаются через канонический журнал операций, регистрации закрываются как отозванные, синтетические тестовые аккаунты выводятся из тестовой среды, а реальный аккаунт не деактивируется. Старый турнир остаётся в аудите и освобождает активный слот для новой проверки.</small>
              </div>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="antifraud" data-admin-antifraud>
        <div class="mgw-admin__card-head">
          <h2>Проверка игр</h2>
          <span>Ручная проверка матчей</span>
        </div>

        <div class="mgw-admin__antifraud">
          <div class="mgw-admin__replay-status" data-af-status>Загружаю данные проверки…</div>

          <section class="mgw-admin__af-home" data-af-home>
            <div class="mgw-admin__af-home-tabs" role="tablist" aria-label="Раздел проверки игр">
              <button type="button" class="is-active" data-af-home-mode="match">Проверить матч</button>
              <button type="button" data-af-home-mode="cases">Кейсы</button>
            </div>

            <section class="mgw-admin__af-home-panel" data-af-home-panel="match">
              <div class="mgw-admin__af-guide">
                <strong>Проверка матча в три шага</strong>
                <span>1. Выберите матч → 2. Просмотрите сигналы и хронологию → 3. При необходимости создайте кейс.</span>
              </div>

              <div class="mgw-admin__af-match-pick">
                <div class="mgw-admin__field mgw-admin__af-recent-picker" data-af-recent-picker>
                  <span>Недавний матч</span>
                  <button type="button" class="mgw-admin__af-recent-trigger" data-af-recent-trigger aria-expanded="false">
                    <span data-af-recent-selected>Выбрать недавний матч</span>
                    <b aria-hidden="true">⌄</b>
                  </button>
                  <div class="mgw-admin__af-recent-list" data-af-recent-list role="listbox" hidden></div>
                </div>

                <span>или</span>

                <label class="mgw-admin__field">
                  <span>ID матча</span>
                  <input data-af-match-id type="text" maxlength="191" autocomplete="off" placeholder="Вставьте ID матча">
                </label>

                <button type="button" data-af-match-load>Открыть проверку</button>
              </div>

              <p class="mgw-admin__af-hint">Список недавних матчей открывается внутри панели и не использует системное меню Android.</p>
            </section>

            <section class="mgw-admin__af-home-panel" data-af-home-panel="cases" hidden>
              <div class="mgw-admin__af-case-filters" role="tablist" aria-label="Статус кейсов">
                <button type="button" class="is-active" data-af-case-filter="active">
                  <span>Все активные</span><b data-af-case-count="active">0</b>
                </button>
                <button type="button" data-af-case-filter="open">
                  <span>Новые</span><b data-af-case-count="open">0</b>
                </button>
                <button type="button" data-af-case-filter="reviewing">
                  <span>В работе</span><b data-af-case-count="reviewing">0</b>
                </button>
                <button type="button" data-af-case-filter="monitoring">
                  <span>Под наблюдением</span><b data-af-case-count="monitoring">0</b>
                </button>
                <button type="button" data-af-case-filter="closed">
                  <span>Завершённые</span><b data-af-case-count="closed">0</b>
                </button>
              </div>

              <div class="mgw-admin__af-case-toolbar">
                <label class="mgw-admin__field">
                  <span>Поиск по кейсам</span>
                  <input type="search" data-af-query maxlength="120" autocomplete="off" placeholder="Кейс, матч или ответственный">
                </label>
                <button type="button" data-af-refresh>Обновить список</button>
              </div>

              <div class="mgw-admin__support-panel">
                <h3 data-af-queue-title>Активные кейсы</h3>
                <div class="mgw-admin__af-queue" data-af-queue></div>
                <nav class="mgw-admin__af-pagination" data-af-pagination aria-label="Страницы кейсов" hidden></nav>
              </div>
            </section>
          </section>

          <section class="mgw-admin__af-review" data-af-review hidden>
            <div class="mgw-admin__af-review-head">
              <button type="button" class="mgw-admin__af-back" data-af-back>← К списку</button>
              <div>
                <strong>Проверка выбранного матча</strong>
                <span data-af-review-subtitle>—</span>
              </div>
            </div>

            <div class="mgw-admin__af-summary" data-af-match-summary></div>

            <div class="mgw-admin__replay-status mgw-admin__af-policy" data-af-policy>
              Сигналы помогают только ручной проверке и сами ничего не блокируют.
            </div>

            <div class="mgw-admin__af-review-tabs" role="tablist" aria-label="Данные проверки матча">
              <button type="button" class="is-active" data-af-review-tab="overview">Обзор</button>
              <button type="button" data-af-review-tab="timeline">Хронология</button>
              <button type="button" data-af-review-tab="pair">История пары</button>
              <button type="button" data-af-review-tab="devices">Устройства</button>
              <button type="button" data-af-review-tab="case">Кейс</button>
            </div>

            <section class="mgw-admin__af-review-panel" data-af-review-panel="overview">
              <div class="mgw-admin__af-panel-title">
                <strong>Что требует внимания</strong>
                <span>Сначала смотрите сюда. Технические данные спрятаны отдельно.</span>
              </div>
              <div class="mgw-admin__af-signals" data-af-signals></div>
            </section>

            <section class="mgw-admin__af-review-panel" data-af-review-panel="timeline" hidden>
              <div class="mgw-admin__af-panel-title">
                <strong>Хронология матча</strong>
                <span>Это не видео. Здесь по порядку показаны сохранённые сервером события матча.</span>
              </div>

              <div class="mgw-admin__af-timeline-state" data-af-timeline-state>Собираю хронологию…</div>
              <div class="mgw-admin__af-timeline" data-af-timeline></div>

              <details class="mgw-admin__af-disclosure mgw-admin__af-technical">
                <summary>Технические данные матча</summary>
                <p>Сырые события и снимки нужны только для диагностики разработчиком.</p>
                <div class="mgw-admin__replay-list" data-af-raw-timeline></div>
                <div class="mgw-admin__replay-list" data-af-raw-frames></div>
              </details>
            </section>

            <section class="mgw-admin__af-review-panel" data-af-review-panel="pair" hidden>
              <div class="mgw-admin__af-panel-title">
                <strong>История этой пары</strong>
                <span>Предыдущие матчи между этими двумя игроками.</span>
              </div>
              <div class="mgw-admin__af-history" data-af-pair-history></div>
            </section>

            <section class="mgw-admin__af-review-panel" data-af-review-panel="devices" hidden>
              <div class="mgw-admin__af-panel-title">
                <strong>Устройства и сессии</strong>
                <span>Здесь показаны совпадения device/session, а не готовый вывод о нарушении.</span>
              </div>
              <div data-af-device-session></div>
            </section>

            <section class="mgw-admin__af-review-panel" data-af-review-panel="case" hidden>
              <div class="mgw-admin__af-panel-title">
                <strong>Кейс проверки</strong>
                <span>Кейс нужен только для матча, который требует отдельного решения и истории действий администратора.</span>
              </div>

              <div class="mgw-admin__af-case" data-af-case>
                <div class="mgw-admin__af-case-summary" data-af-case-summary>Кейс ещё не создан.</div>
                <button type="button" data-af-create-case>Создать кейс</button>
                <button type="button" data-af-take-case hidden>Взять в работу</button>

                <div class="mgw-admin__af-decisions" data-af-decisions hidden>
                  <label class="mgw-admin__field">
                    <span>Комментарий</span>
                    <textarea data-af-decision-note rows="3" maxlength="800" placeholder="Коротко напишите, что проверили и почему выбрали этот итог"></textarea>
                  </label>

                  <div class="mgw-admin__af-decision-buttons">
                    <button type="button" data-af-decision="cleared">Нарушений не найдено</button>
                    <button type="button" data-af-decision="monitor">Оставить под наблюдением</button>
                    <button type="button" data-af-decision="rating_review">Передать в рейтинг</button>
                    <button type="button" data-af-decision="moderation_review">Передать в модерацию</button>
                  </div>
                </div>

                <button type="button" class="mgw-admin__af-secondary" data-af-open-processed hidden>Открыть завершённые кейсы</button>
                <small>«Под наблюдением» остаётся в активных кейсах. Остальные итоги завершают anti-fraud проверку. Ни один из этих действий сам по себе не блокирует игрока.</small>
              </div>
            </section>
          </section>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="economy" data-compensation-card>
        <div class="mgw-admin__card-head">
          <h2>Компенсации</h2>
          <span>Возврат коинов по списанию</span>
        </div>

        <div class="mgw-admin__compensation">
          <section class="mgw-admin__compensation-step">
            <div class="mgw-admin__compensation-step-head">
              <span class="mgw-admin__compensation-step-num">1</span>
              <div>
                <h3>Выберите списание</h3>
                <p>Показываем последние операции, где у игрока списались MGW Coins.</p>
              </div>
            </div>

            <div class="mgw-admin__field mgw-admin__operation-picker" data-compensation-operation-picker>
              <span>Исходная операция</span>
              <button type="button" class="mgw-admin__operation-picker-trigger" data-compensation-operation-trigger aria-expanded="false">
                <span data-compensation-operation-selected>Загрузка последних списаний…</span>
                <b aria-hidden="true">⌄</b>
              </button>
              <div class="mgw-admin__operation-picker-list" data-compensation-operation-list role="listbox" hidden></div>
            </div>

            <div class="mgw-admin__compensation-selected" data-compensation-selected hidden>
              <div class="mgw-admin__compensation-selected-main">
                <div>
                  <span>Игрок</span>
                  <strong data-compensation-selected-player>—</strong>
                </div>
                <div>
                  <span>Списано</span>
                  <strong data-compensation-selected-amount>—</strong>
                </div>
                <div>
                  <span>Когда</span>
                  <strong data-compensation-selected-time>—</strong>
                </div>
                <div>
                  <span>Баланс сейчас</span>
                  <strong data-compensation-selected-balance>—</strong>
                </div>
              </div>

              <details class="mgw-admin__compensation-tech">
                <summary>Технические данные</summary>
                <div data-compensation-selected-tech>—</div>
              </details>
            </div>

            <details class="mgw-admin__compensation-advanced">
              <summary>Не нашли нужную операцию?</summary>
              <div class="mgw-admin__compensation-advanced-body">
                <label class="mgw-admin__field">
                  <span>Поиск по игроку, MGW ID или операции</span>
                  <input data-compensation-browser-query type="search" maxlength="120" autocomplete="off" placeholder="Например: Player4576286">
                </label>
                <button type="button" data-compensation-browser-search>Найти списания</button>

                <label class="mgw-admin__field">
                  <span>Или открыть по точному ID</span>
                  <input data-compensation-operation type="text" maxlength="191" autocomplete="off" placeholder="entry_id или operation key">
                </label>
                <button type="button" data-compensation-lookup>Открыть по ID</button>
              </div>
            </details>

            <div class="mgw-admin__compensation-message" data-compensation-lookup-status hidden></div>
          </section>

          <section class="mgw-admin__compensation-step">
            <div class="mgw-admin__compensation-step-head">
              <span class="mgw-admin__compensation-step-num">2</span>
              <div>
                <h3>Укажите сумму и причину</h3>
                <p>Для обычного возврата сумма подставится автоматически по выбранному списанию.</p>
              </div>
            </div>

            <div class="mgw-admin__compensation-form">
              <label class="mgw-admin__field">
                <span>Сумма компенсации</span>
                <input data-compensation-amount type="number" min="1" step="1" inputmode="numeric" placeholder="0">
              </label>
              <label class="mgw-admin__field">
                <span>Причина</span>
                <input data-compensation-reason type="text" maxlength="500" autocomplete="off" placeholder="Например: ошибочное списание">
              </label>
            </div>

            <button class="mgw-admin__compensation-submit" type="button" data-compensation-request disabled>
              Создать компенсацию
            </button>
            <p class="mgw-admin__compensation-hint" data-compensation-limit-copy>Загрузка лимитов…</p>

            <div class="mgw-admin__compensation-message" data-compensation-status hidden></div>
          </section>

          <section class="mgw-admin__compensation-confirm" data-compensation-confirmation hidden>
            <div class="mgw-admin__compensation-step-head">
              <span class="mgw-admin__compensation-step-num">!</span>
              <div>
                <h3>Нужно второе подтверждение</h3>
                <p>Крупная компенсация не будет начислена, пока вы не подтвердите её ещё раз.</p>
              </div>
            </div>
            <div class="mgw-admin__compensation-confirm-copy" data-compensation-confirmation-copy>—</div>
            <button type="button" data-compensation-confirm>Подтвердить компенсацию</button>
          </section>

          <details class="mgw-admin__compensation-history">
            <summary>
              <span>История компенсаций</span>
              <strong data-compensation-history-count>0</strong>
            </summary>
            <div data-compensation-history>
              <div class="mgw-admin__history-empty">Загрузка…</div>
            </div>
            <nav class="mgw-admin__pager" data-compensation-pagination aria-label="Страницы компенсаций" hidden></nav>
          </details>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="economy" data-economy-card>
        <div class="mgw-admin__card-head">
          <h2>Экономика</h2>
          <span>Настройки и история изменений</span>
        </div>
        <div class="mgw-admin__economy">
          <div class="mgw-admin__economy-meta">
            <div><span>Версия</span><strong data-economy-version>—</strong></div>
            <div><span>SHA-256</span><strong data-economy-sha>—</strong></div>
          </div>

          <details class="mgw-admin__economy-disclosure" data-economy-config-disclosure>
            <summary>
              <span>
                <strong>Конфигурация экономики</strong>
                <small>Редактирование JSON и создание новой версии</small>
              </span>
              <i aria-hidden="true"></i>
            </summary>
            <div class="mgw-admin__economy-disclosure-body">
              <label class="mgw-admin__field">
                <span>Конфигурация JSON</span>
                <textarea data-economy-config rows="22" spellcheck="false" autocomplete="off"></textarea>
              </label>

              <label class="mgw-admin__field">
                <span>Причина изменения / отката</span>
                <input data-economy-reason type="text" maxlength="500" autocomplete="off" placeholder="Обязательная причина">
              </label>

              <div class="mgw-admin__economy-actions">
                <button type="button" data-economy-save>Сохранить новую версию</button>
                <small>Изменение конфигурации не меняет балансы пользователей.</small>
              </div>
            </div>
          </details>

          <details class="mgw-admin__economy-disclosure" data-economy-simulation-disclosure>
            <summary>
              <span>
                <strong>Детерминированная проверка</strong>
                <small>Технический расчёт источников и списаний</small>
              </span>
              <i aria-hidden="true"></i>
            </summary>
            <div class="mgw-admin__economy-disclosure-body mgw-admin__simulation">
              <pre data-economy-simulation>—</pre>
            </div>
          </details>

          <details class="mgw-admin__list-disclosure mgw-admin__economy-history-wrap">
            <summary>
              <span>
                <strong>История версий</strong>
                <small>Предыдущие конфигурации и откаты</small>
              </span>
              <i aria-hidden="true"></i>
            </summary>
            <div class="mgw-admin__history">
              <div data-economy-history></div>
              <nav class="mgw-admin__pager" data-economy-pagination aria-label="Страницы версий экономики" hidden></nav>
            </div>
          </details>
        </div>
      </article>

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="tests" data-test-coins-card>
        <div class="mgw-admin__card-head">
          <h2>Тестовые коины</h2>
          <span>Только тестовая среда</span>
        </div>
        <div class="mgw-admin__economy">
          <div class="mgw-admin__economy-meta">
            <label class="mgw-admin__field">
              <span>Игрок</span>
              <input data-test-coins-player type="text" maxlength="191" autocomplete="off" placeholder="Пусто — начислить себе">
            </label>
            <label class="mgw-admin__field">
              <span>Количество</span>
              <input data-test-coins-amount type="number" min="1" max="250000" step="1" value="100000" inputmode="numeric">
            </label>
          </div>
          <label class="mgw-admin__field">
            <span>Причина</span>
            <input data-test-coins-reason type="text" maxlength="200" autocomplete="off" value="Проверка игровой косметики">
          </label>
          <div class="mgw-admin__economy-actions">
            <button type="button" data-test-coins-grant>Начислить тестовые коины</button>
            <small>Только тестовая среда. Пустое поле игрока использует Telegram ID текущего администратора.</small>
          </div>
          <div class="mgw-admin__replay-status" data-test-coins-status>Начислений в этой сессии ещё не было.</div>
        </div>
      </article>
    </section>

    <footer class="mgw-admin__footer">
      Панель администратора использует существующие серверные механизмы и аудит. Тестовые инструменты доступны только в тестовой среде.
    </footer>
  </main>
</body>
</html>
