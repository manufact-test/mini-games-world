<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const RULES_VERSION = 'official-tournament-rules-v1';
    private const RULES_LANGUAGE = 'ru';

    public function version(): string
    {
        return '20260920_0050_add_tournament_rules_consent';
    }

    public function description(): string
    {
        return 'Add MVP-21.2 immutable tournament rules consent and registration-close metadata.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $this->upSqlite($database);
        } else {
            $this->upMysql($database);
        }

        $this->backfillExistingTournaments($database);
    }

    private function upMysql(DatabaseConnectionInterface $database): void
    {
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournaments',
            'rules_version',
            'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reward_snapshot_json'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournaments',
            'rules_language',
            'VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER rules_version'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournaments',
            'rules_snapshot_json',
            'LONGTEXT NULL AFTER rules_language'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournaments',
            'rules_sha256',
            'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER rules_snapshot_json'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournaments',
            'registration_closed_at_utc',
            'DATETIME(6) NULL AFTER registration_opened_at_utc'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournaments',
            'registration_closed_reason',
            'VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER registration_closed_at_utc'
        );

        $this->ensureMysqlColumn(
            $database,
            'mgw_tournament_registrations',
            'rules_version',
            'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reservation_id'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournament_registrations',
            'rules_language',
            'VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER rules_version'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournament_registrations',
            'rules_sha256',
            'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER rules_language'
        );
        $this->ensureMysqlColumn(
            $database,
            'mgw_tournament_registrations',
            'rules_accepted_at_utc',
            'DATETIME(6) NULL AFTER rules_sha256'
        );
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $this->ensureSqliteColumn($database, 'mgw_tournaments', 'rules_version', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournaments', 'rules_language', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournaments', 'rules_snapshot_json', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournaments', 'rules_sha256', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournaments', 'registration_closed_at_utc', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournaments', 'registration_closed_reason', 'TEXT NULL');

        $this->ensureSqliteColumn($database, 'mgw_tournament_registrations', 'rules_version', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournament_registrations', 'rules_language', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournament_registrations', 'rules_sha256', 'TEXT NULL');
        $this->ensureSqliteColumn($database, 'mgw_tournament_registrations', 'rules_accepted_at_utc', 'TEXT NULL');
    }

    private function ensureMysqlColumn(
        DatabaseConnectionInterface $database,
        string $table,
        string $column,
        string $definition
    ): void {
        $exists = (int)$database->fetchValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME=:table_name
               AND COLUMN_NAME=:column_name',
            ['table_name'=>$table,'column_name'=>$column]
        ) > 0;
        if ($exists) return;

        $database->execute(
            'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition
        );
    }

    private function ensureSqliteColumn(
        DatabaseConnectionInterface $database,
        string $table,
        string $column,
        string $definition
    ): void {
        foreach ($database->fetchAll('PRAGMA table_info(' . $table . ')') as $row) {
            if (is_array($row) && (string)($row['name'] ?? '') === $column) return;
        }
        $database->execute(
            'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition
        );
    }

    private function backfillExistingTournaments(DatabaseConnectionInterface $database): void
    {
        $rows = $database->fetchAll(
            'SELECT tournament_id,game_type,capacity FROM mgw_tournaments
             WHERE rules_version IS NULL OR rules_snapshot_json IS NULL OR rules_sha256 IS NULL'
        );

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $snapshot = $this->rulesSnapshot(
                (string)($row['game_type'] ?? ''),
                (int)($row['capacity'] ?? 0)
            );
            $json = json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $database->execute(
                'UPDATE mgw_tournaments
                 SET rules_version=:rules_version,
                     rules_language=:rules_language,
                     rules_snapshot_json=:rules_snapshot_json,
                     rules_sha256=:rules_sha256
                 WHERE tournament_id=:tournament_id',
                [
                    'rules_version'=>self::RULES_VERSION,
                    'rules_language'=>self::RULES_LANGUAGE,
                    'rules_snapshot_json'=>$json,
                    'rules_sha256'=>$this->rulesSha256FromJson($json),
                    'tournament_id'=>(string)$row['tournament_id'],
                ]
            );
        }
    }

    private function rulesSha256FromJson(string $json): string
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $canonical = $this->canonicalizeJsonValue($decoded);
        $encoded = json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $encoded);
    }

    private function canonicalizeJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key=>$item) {
            $value[$key] = $this->canonicalizeJsonValue($item);
        }
        return $value;
    }

    private function rulesSnapshot(string $gameType, int $capacity): array
    {
        $gameTitle = match ($gameType) {
            'tictactoe' => 'Крестики-нолики',
            'four_in_a_row' => 'Четыре в ряд',
            'battleship' => 'Морской бой',
            'checkers' => 'Русские шашки',
            'reversi' => 'Реверси',
            'chess' => 'Шахматы',
            'go' => 'Го',
            'domino' => 'Домино',
            default => $gameType !== '' ? $gameType : 'Игра',
        };

        return [
            'version'=>self::RULES_VERSION,
            'language'=>self::RULES_LANGUAGE,
            'title'=>'Правила официального турнира',
            'tournament'=>[
                'game_type'=>$gameType,
                'game_title'=>$gameTitle,
                'capacity'=>$capacity,
                'entry_fee'=>50000,
                'entry_asset_code'=>'mgw_coin',
            ],
            'sections'=>[
                [
                    'id'=>'registration',
                    'title'=>'Регистрация и взнос',
                    'items'=>[
                        "Турнир проходит по игре «{$gameTitle}». Количество участников: {$capacity}.",
                        'Взнос — 50 000 коинов. При регистрации сумма резервируется, а не списывается.',
                        'До заполнения турнира участник может отменить регистрацию: место освобождается, резерв 50 000 полностью снимается.',
                        'Когда все места заняты, регистрация закрывается автоматически, состав фиксируется и ожидает назначения даты.',
                    ],
                ],
                [
                    'id'=>'schedule',
                    'title'=>'Дата и участие',
                    'items'=>[
                        'После набора состава администратор назначает дату и время турнира.',
                        'Участникам предусмотрены напоминания за день, за час и за 15 минут до начала.',
                        'Турнирный зал открывается за 15 минут до старта. Сетка формируется случайно точно в момент начала.',
                        'Отсутствующий участник остаётся в сетке и получает техническое поражение по турнирным правилам.',
                    ],
                ],
                [
                    'id'=>'start',
                    'title'=>'Готовность и старт матча',
                    'items'=>[
                        'Перед первым матчем даётся 2 минуты на подтверждение «Я готов».',
                        'После готовности обоих игроков поле блокируется до общего 10-секундного визуального, звукового и вибрационного отсчёта.',
                        'Игровой таймер начинается только после окончания этого отсчёта.',
                    ],
                ],
                [
                    'id'=>'rounds',
                    'title'=>'Раунды и ничьи',
                    'items'=>[
                        'Следующий раунд начинается после завершения всех матчей текущего раунда.',
                        'Между раундами предусмотрен перерыв 5 минут.',
                        'При ничьей повторный матч начинается через 1 минуту, стороны меняются, повторный взнос не резервируется.',
                        'Турнир включает финал и отдельный матч за третье место.',
                    ],
                ],
                [
                    'id'=>'technical',
                    'title'=>'Отключения и технические исходы',
                    'items'=>[
                        'При отключении одного игрока действует окно восстановления 60 секунд.',
                        'При отключении обоих игроков предусмотрена отдельная ветка восстановления длительностью до 3 минут.',
                        'Ручной выход, отсутствие обоих игроков и серверная/игровая неисправность обрабатываются отдельными техническими исходами турнира.',
                        'При отмене или аварийной остановке турнира предусмотрен полный возврат взноса; результаты аннулируются с аудитом.',
                    ],
                ],
                [
                    'id'=>'rewards',
                    'title'=>'Награды',
                    'items'=>[
                        '1 место: 200 000 коинов всего — возврат 50 000 взноса + 150 000 приз; Golden Ticket; корона чемпиона на 30 дней; постоянный значок победителя; чемпионская косметика; Зал славы; золотой кубок.',
                        '2 место: 80 000 коинов всего — возврат 50 000 взноса + 30 000 приз; серебряная рамка на 30 дней; постоянный результат финалиста; серебряный кубок.',
                        '3 место: возврат 50 000 взноса; бронзовая отметка на 30 дней; постоянный результат третьего места; бронзовый кубок.',
                        'Остальным участникам взнос не возвращается, кроме предусмотренных веток отмены или аварийной остановки.',
                        'Golden Ticket нельзя продать или передать. Он сохраняется до будущего Большого турнира; заранее не обещаются фиксированная дата или фиксированное число участников.',
                    ],
                ],
                [
                    'id'=>'immutability',
                    'title'=>'Версия правил',
                    'items'=>[
                        'Согласие сохраняется вместе с точной версией правил, языком и временем принятия.',
                        'Существенные изменения правил не применяются к уже открытому турниру молча: для них требуется отмена текущего турнира и создание нового.',
                    ],
                ],
            ],
        ];
    }
};
