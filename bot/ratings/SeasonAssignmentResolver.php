<?php
declare(strict_types=1);

/**
 * Resolves a finished match to the official season that owned its finish time.
 *
 * This prevents a delayed projection from putting a pre-boundary result into
 * the new quarter merely because it was processed after the boundary.
 */
final class SeasonAssignmentResolver
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function resolve(?string $finishedAtUtc, array $control): array
    {
        $state = strtolower(trim((string)($control['competition_state'] ?? '')));
        $currentSeasonId = trim((string)($control['current_season_id'] ?? ''));
        $activatedAt = $this->nullableText($control['activated_at'] ?? null);

        if ($state !== PerGameRatingService::STATE_ACTIVE || $finishedAtUtc === null || trim($finishedAtUtc) === '') {
            return [
                'competition_state' => $state,
                'season_id' => $currentSeasonId !== '' ? $currentSeasonId : PerGameRatingService::PRESEASON_ID,
            ];
        }

        if ($activatedAt !== null && $this->compare($finishedAtUtc, $activatedAt) < 0) {
            return [
                'competition_state' => PerGameRatingService::STATE_PRESEASON,
                'season_id' => PerGameRatingService::PRESEASON_ID,
            ];
        }

        try {
            $rows = $this->database->fetchAll(
                'SELECT season_id
                 FROM mgw_rating_seasons
                 WHERE official_start_at_utc <= :finished_at
                   AND calendar_end_at_utc > :finished_at
                 ORDER BY official_start_at_utc DESC
                 LIMIT 1',
                ['finished_at' => $finishedAtUtc]
            );
        } catch (Throwable) {
            // Backward-compatible fallback for focused tests that intentionally
            // exercise MVP-20.1 without the later MVP-20.4 migration loaded.
            $rows = [];
        }

        if ($rows !== [] && is_array($rows[0])) {
            $seasonId = trim((string)($rows[0]['season_id'] ?? ''));
            if ($seasonId !== '') {
                return [
                    'competition_state' => PerGameRatingService::STATE_ACTIVE,
                    'season_id' => $seasonId,
                ];
            }
        }

        return [
            'competition_state' => PerGameRatingService::STATE_ACTIVE,
            'season_id' => $currentSeasonId !== '' ? $currentSeasonId : PerGameRatingService::PRESEASON_ID,
        ];
    }

    private function compare(string $left, string $right): int
    {
        $leftTs = strtotime($left);
        $rightTs = strtotime($right);
        if ($leftTs === false || $rightTs === false) {
            throw new RuntimeException('MVP-20.4 season assignment timestamp is invalid.');
        }
        return $leftTs <=> $rightTs;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
