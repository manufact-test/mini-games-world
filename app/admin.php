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
  <link rel="stylesheet" href="./assets/css/admin-shell.css?v=5&replay=17-6&mvp20_8=rating-admin&mvp21_1=tournament-registration&mvp21_10=prize-review-v1">
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <script src="./assets/js/admin-shell.js?v=4&replay=17-6&test-coins=staging" defer></script>
  <script src="./assets/js/admin-reports.js?v=1&mvp18=reports" defer></script>
  <script src="./assets/js/admin-notifications.js?v=1&mvp18=bell-pipeline" defer></script>
  <script src="./assets/js/admin-rating.js?v=1&mvp20_8=rating-admin" defer></script>
  <script src="./assets/js/admin-tournaments.js?v=15&mvp21_3=local-time-copy-v2&mvp21_4=staging-reset-reseed-v2&mvp21_5=manual-acceptance-fixes-v3&mvp21_5=corrective-v5&mvp21_6=fixture-progression-helper-v3&mvp21_8=corrective-v12&mvp21_8_cancel=cancellation-emergency-v1&mvp21_10=prize-review-v1&mvp21_manual=admin-ui-v1" defer></script>
</head>
<body>
  <main class="mgw-admin" data-admin-api="../bot/admin-read.php" data-economy-api="../bot/admin-economy.php" data-test-coins-api="../bot/admin-test-coins.php" data-replay-api="../bot/admin-replay.php" data-reports-api="../bot/admin-reports.php" data-notifications-api="../bot/admin-notifications.php" data-rating-api="../bot/admin-rating.php" data-tournament-api="../bot/admin-tournaments.php">
    <header class="mgw-admin__header">
      <div>
        <p class="mgw-admin__eyebrow">MINI GAMES WORLD</p>
        <h1>Web Admin</h1>
        <p class="mgw-admin__subtitle">Системный обзор, рейтинг, bell events, экономика, replay storage и очередь жалоб.</p>
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

      <article class="mgw-admin__card mgw-admin__card--wide" data-tournament-admin>
        <div class="mgw-admin__card-head">
          <h2>Официальный турнир</h2>
          <span>MVP-21.3 · дата, отсчёт и уведомления</span>
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
              <div class="mgw-admin__tournament-schedule" data-tournament-manual-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-manual-info>Ручная проверка staging недоступна.</div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-prepare-manual disabled>Подготовить 6/8 для двух живых аккаунтов</button>
                </div>
                <small>Только staging: 6 мест заполняются синтетическими участниками через тот же канонический сервис регистрации и резерв 50 000. Два места остаются двум живым аккаунтам для ручной проверки первого матча.</small>
              </div>
              <div class="mgw-admin__tournament-schedule" data-tournament-progression-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-progression-info>Fixture-only пары пока не требуют завершения.</div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-complete-fixtures disabled>Завершить fixture-only пары</button>
                </div>
                <small>Только staging: завершает только пары, где оба участника — синтетические fixture. Результат проходит через канонический TournamentRoundProgressionService, не создаёт второй взнос и не затрагивает реально сыгранную пару.</small>
              </div>
              <div class="mgw-admin__tournament-schedule" data-tournament-reset-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-reset-info>Сброс staging-турнира недоступен.</div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-reset-manual disabled>Сбросить staging-турнир</button>
                </div>
                <small>Только staging: все турнирные резервы освобождаются через канонический ledger, registrations закрываются как withdrawn, синтетические fixture accounts выводятся из тестового runtime, а реальный аккаунт не деактивируется. Старый турнир остаётся в аудите и освобождает active slot для новой проверки.</small>
              </div>
              <div class="mgw-admin__tournament-schedule" data-tournament-cancel-panel hidden>
                <div class="mgw-admin__tournament-current" data-tournament-cancel-info>Отмена турнира недоступна.</div>
                <label class="mgw-admin__field">
                  <span>Причина</span>
                  <textarea data-tournament-cancel-reason rows="3" maxlength="1200" placeholder="Опишите причину" style="min-height:88px;padding:11px 12px;resize:vertical"></textarea>
                  <small style="color:#858791;font-size:11px;line-height:1.45">Для обычной отмены необязательно. Для аварийной остановки — обязательно.</small>
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
                      <option value="fraud">fraud</option>
                      <option value="automation">automation</option>
                      <option value="duplicate_identity">duplicate_identity</option>
                      <option value="match_manipulation">match_manipulation</option>
                      <option value="cheating_report">cheating_report</option>
                      <option value="manual_review">manual_review</option>
                    </select>
                  </label>
                  <label class="mgw-admin__field">
                    <span>Game ID (для flagged match)</span>
                    <input data-tournament-review-game-id type="text" maxlength="96" autocomplete="off" placeholder="Можно пустым только для уже определённого top-3">
                  </label>
                  <label class="mgw-admin__field">
                    <span>Основание сигнала</span>
                    <input data-tournament-review-note type="text" maxlength="800" autocomplete="off" placeholder="Что именно требует тяжёлой проверки">
                  </label>
                </div>
                <div class="mgw-admin__tournament-actions">
                  <button type="button" data-tournament-review-flag>Поставить призовой путь на проверку</button>
                </div>
                <small>Тяжёлая проверка применяется только к top-3 или участнику явно flagged tournament match. Сам сигнал не списывает и не начисляет деньги: он только удерживает затронутую призовую ветку до решения Admin.</small>
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

      <article class="mgw-admin__card mgw-admin__card--wide" data-rating-admin>
        <div class="mgw-admin__card-head">
          <h2>Rating Admin</h2>
          <span>MVP-20.8 · reviewed corrections</span>
        </div>
        <div class="mgw-admin__rating">
          <div class="mgw-admin__rating-status" data-rating-status>Rating Admin ещё не загружен.</div>

          <div class="mgw-admin__rating-metrics" data-rating-metrics></div>

          <div class="mgw-admin__rating-actions">
            <button type="button" data-rating-refresh>Обновить рейтинг</button>
            <button type="button" data-rating-rehearsal>Репетиция закрытия сезона</button>
          </div>

          <div class="mgw-admin__rating-grid">
            <section class="mgw-admin__rating-panel">
              <h3>Reviewed exclusion</h3>
              <label class="mgw-admin__field">
                <span>Season ID</span>
                <input data-rating-season-id type="text" maxlength="64" autocomplete="off" placeholder="2026-q4">
              </label>
              <label class="mgw-admin__field">
                <span>MGW-ID игрока</span>
                <input data-rating-mgw-id type="text" maxlength="64" autocomplete="off" placeholder="MGW-ID-…">
              </label>
              <label class="mgw-admin__field">
                <span>Причина</span>
                <select data-rating-reason-code>
                  <option value="fraud">fraud</option>
                  <option value="automation">automation</option>
                  <option value="duplicate_identity">duplicate_identity</option>
                  <option value="match_manipulation">match_manipulation</option>
                  <option value="manual_review">manual_review</option>
                </select>
              </label>
              <label class="mgw-admin__field">
                <span>Комментарий проверки</span>
                <input data-rating-review-note type="text" maxlength="500" autocomplete="off" placeholder="Что проверено и почему игрок исключается">
              </label>
              <div class="mgw-admin__rating-actions">
                <button type="button" data-rating-exclude>Исключить после review</button>
              </div>
              <small>Exclusion не переписывает match history или rating score. После review отдельный recalculation пересобирает награды и медаль.</small>
            </section>

            <section class="mgw-admin__rating-panel">
              <h3>Recalculation</h3>
              <label class="mgw-admin__field">
                <span>Причина пересчёта</span>
                <input data-rating-recalc-reason type="text" maxlength="500" autocomplete="off" placeholder="Reviewed fraud correction">
              </label>
              <div class="mgw-admin__rating-actions">
                <button type="button" data-rating-recalculate>Пересчитать закрытый сезон</button>
              </div>
              <small>Разрешено только для FINALIZING/CLOSED. Используются действующие review exclusions и существующие award owners.</small>
              <pre class="mgw-admin__rating-rehearsal" data-rating-rehearsal-output>Репетиция ещё не запускалась.</pre>
            </section>
          </div>

          <section class="mgw-admin__rating-panel">
            <h3>Активные exclusions</h3>
            <div class="mgw-admin__history" data-rating-exclusions></div>
          </section>

          <section class="mgw-admin__rating-panel">
            <h3>Последние recalculation jobs</h3>
            <div class="mgw-admin__history" data-rating-jobs></div>
          </section>
        </div>
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

      <article class="mgw-admin__card mgw-admin__card--wide" data-test-coins-card>
        <div class="mgw-admin__card-head">
          <h2>Тестовые коины</h2>
          <span>staging only</span>
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
            <small>Только staging. Пустое поле игрока использует Telegram ID текущего администратора.</small>
          </div>
          <div class="mgw-admin__replay-status" data-test-coins-status>Начислений в этой сессии ещё не было.</div>
        </div>
      </article>
    </section>

    <footer class="mgw-admin__footer">
      Bell events используют существующий Notification Center и не создают второй notification store. Replay viewer читает только durable events/snapshots. Очередь жалоб не применяет автоматических санкций. Web Admin не переключает runtime; Android push остаётся вне MVP-18.6.
    </footer>
  </main>
</body>
</html>
