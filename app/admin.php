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
  <link rel="stylesheet" href="./assets/css/admin-shell.css?v=11&replay=17-6&manual_acceptance=v7-support-lifecycle-v3&mvp22_1=support-tickets&mvp22_2=compensation-ux-v4&economy_ui=collapsed-technical-v1&mvp22_3=moderation-v2-report-cards&mvp20_8=rating-admin&mvp21_1=tournament-registration&mvp21_10=prize-review-v1&mvp21_manual=admin-ui-v1">
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="./assets/js/admin-shell.js?v=6&replay=17-6&test-coins=staging" defer></script>
  <script src="./assets/js/admin-compensation.js?v=4&mvp22_2=admin-simple-flow-v4" defer></script>
  <script src="./assets/js/admin-reports.js?v=2&mvp18=reports&mvp22_3=manual-acceptance-v6" defer></script>
  <script src="./assets/js/admin-notifications.js?v=1&mvp18=bell-pipeline" defer></script>
  <script src="./assets/js/admin-support.js?v=7&mvp22_1=lifecycle-v3&support_focus=ticket-detail-v1&attachment_viewer=inline-v2&reply_files=managed-v1&reply_delivery=bell-verified-v2" defer></script>
  <script src="./assets/js/admin-rating.js?v=2&mvp20_8=rating-admin" defer></script>
  <script src="./assets/js/admin-tournaments.js?v=17&mvp21_3=local-time-copy-v2&mvp21_4=staging-reset-reseed-v2&mvp21_5=manual-acceptance-fixes-v3&mvp21_5=corrective-v5&mvp21_6=fixture-progression-helper-v3&mvp21_8=corrective-v12&mvp21_8_cancel=cancellation-emergency-v1&mvp21_10=prize-review-v1&mvp21_manual=admin-ui-v2" defer></script>
</head>
<body>
  <main class="mgw-admin" data-admin-api="../bot/admin-read.php" data-economy-api="../bot/admin-economy.php" data-compensation-api="../bot/admin-compensation.php" data-test-coins-api="../bot/admin-test-coins.php" data-replay-api="../bot/admin-replay.php" data-reports-api="../bot/admin-reports.php" data-support-api="../bot/admin-support.php" data-notifications-api="../bot/admin-notifications.php" data-rating-api="../bot/admin-rating.php" data-tournament-api="../bot/admin-tournaments.php">
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
      <button type="button" data-admin-nav-target="users">Пользователи</button>
      <button type="button" data-admin-nav-target="support">Поддержка</button>
      <button type="button" data-admin-nav-target="tournaments">Турниры и сезоны</button>
      <button type="button" data-admin-nav-target="economy">Экономика</button>
      <button type="button" data-admin-nav-target="notifications">Уведомления</button>
      <button type="button" data-admin-nav-target="system">Система</button>
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

      <article class="mgw-admin__card" data-admin-section="system">
        <div class="mgw-admin__card-head">
          <h2>Состояние системы</h2>
          <span>Только просмотр</span>
        </div>
        <pre data-admin-system-check>—</pre>
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

          <section class="mgw-admin__tournament-panel">
            <h3>Правила турнира</h3>
            <pre class="mgw-admin__tournament-rewards" data-tournament-rules>—</pre>
          </section>

          <section class="mgw-admin__tournament-panel">
            <h3>Снимок наград</h3>
            <pre class="mgw-admin__tournament-rewards" data-tournament-rewards>—</pre>
          </section>
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
              <pre class="mgw-admin__rating-rehearsal" data-rating-rehearsal-output>Репетиция ещё не запускалась.</pre>
            </section>
          </div>

          <section class="mgw-admin__rating-panel">
            <h3>Активные исключения</h3>
            <div class="mgw-admin__history" data-rating-exclusions></div>
          </section>

          <section class="mgw-admin__rating-panel">
            <h3>Последние пересчёты</h3>
            <div class="mgw-admin__history" data-rating-jobs></div>
          </section>
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
          <div class="mgw-admin__history" data-notification-event-list></div>
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

      <article class="mgw-admin__card mgw-admin__card--wide" data-admin-section="tests" data-replay-card>
        <div class="mgw-admin__card-head">
          <h2>Диагностика матча</h2>
          <span>Только просмотр</span>
        </div>
        <div class="mgw-admin__replay">
          <div class="mgw-admin__replay-search">
            <label class="mgw-admin__field">
              <span>ID матча</span>
              <input data-replay-match-id type="text" maxlength="191" autocomplete="off" placeholder="Введите ID матча">
            </label>
            <button type="button" data-replay-load>Загрузить диагностику</button>
          </div>
          <div class="mgw-admin__replay-status" data-replay-status>Укажите ID матча. Данные не изменяются.</div>
          <div data-replay-output hidden>
            <div class="mgw-admin__replay-summary" data-replay-summary></div>
            <h3>События</h3>
            <div class="mgw-admin__replay-list" data-replay-timeline></div>
            <h3>Снимки состояния</h3>
            <div class="mgw-admin__replay-list" data-replay-frames></div>
          </div>
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

            <label class="mgw-admin__field">
              <span>Исходная операция</span>
              <select data-compensation-operation-picker>
                <option value="">Загрузка последних списаний…</option>
              </select>
            </label>

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

          <div class="mgw-admin__history">
            <h3>История версий</h3>
            <div data-economy-history></div>
          </div>
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
