<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tournaments/TournamentRegistrationService.php';

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260920_0051_refresh_tournament_rules_copy';
    }

    public function description(): string
    {
        return 'Refresh active tournament rules to the human-readable MVP-21.2 copy revision.';
    }

    public function transactional(): bool
    {
        return true;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $rows = $database->fetchAll(
            'SELECT tournament_id,game_type,capacity,rules_version
             FROM mgw_tournaments
             WHERE active_slot=:active_slot',
            ['active_slot'=>TournamentRegistrationService::ACTIVE_SLOT]
        );

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if ((string)($row['rules_version'] ?? '') === TournamentRegistrationService::RULES_VERSION) {
                continue;
            }

            $gameType = (string)($row['game_type'] ?? '');
            $capacity = (int)($row['capacity'] ?? 0);
            $snapshot = TournamentRegistrationService::canonicalRulesSnapshot($gameType, $capacity);
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
                    'rules_version'=>TournamentRegistrationService::RULES_VERSION,
                    'rules_language'=>TournamentRegistrationService::RULES_LANGUAGE,
                    'rules_snapshot_json'=>$json,
                    'rules_sha256'=>TournamentRegistrationService::canonicalRulesSha256($gameType, $capacity),
                    'tournament_id'=>(string)$row['tournament_id'],
                ]
            );
        }
    }
};
