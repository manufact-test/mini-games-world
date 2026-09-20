<?php
declare(strict_types=1);

final class SeasonCalendar
{
    public const TIMEZONE = 'Europe/Moscow';

    public function definitionForInstant(DateTimeImmutable $instant): array
    {
        $local = $instant->setTimezone(new DateTimeZone(self::TIMEZONE));
        $year = (int)$local->format('Y');
        $month = (int)$local->format('n');
        $quarter = intdiv($month - 1, 3) + 1;
        return $this->definition($year, $quarter);
    }

    public function definition(int $year, int $quarter): array
    {
        if ($year < 2000 || $year > 9999) {
            throw new InvalidArgumentException('Season year is out of range.');
        }
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidArgumentException('Season quarter must be between 1 and 4.');
        }

        $timezone = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');
        $startMonth = (($quarter - 1) * 3) + 1;
        $startLocal = new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $startMonth),
            $timezone
        );
        $endLocal = $startLocal->modify('+3 months');

        return [
            'season_id' => sprintf('%04d-q%d', $year, $quarter),
            'calendar_year' => $year,
            'quarter' => $quarter,
            'timezone' => self::TIMEZONE,
            'calendar_start_at_utc' => $startLocal->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            'calendar_end_at_utc' => $endLocal->setTimezone($utc)->format('Y-m-d H:i:s.u'),
        ];
    }

    public function nextDefinition(array|string $season): array
    {
        if (is_string($season)) {
            [$year, $quarter] = $this->parseSeasonId($season);
        } else {
            $year = (int)($season['calendar_year'] ?? 0);
            $quarter = (int)($season['quarter'] ?? 0);
        }

        if ($quarter === 4) {
            return $this->definition($year + 1, 1);
        }
        return $this->definition($year, $quarter + 1);
    }

    public function parseSeasonId(string $seasonId): array
    {
        $seasonId = strtolower(trim($seasonId));
        if (preg_match('/^(\d{4})-q([1-4])$/', $seasonId, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid quarterly season id.');
        }
        return [(int)$matches[1], (int)$matches[2]];
    }

    public function reminderDueAt(array $season, int $daysBeforeEnd): string
    {
        if (!in_array($daysBeforeEnd, [21, 14, 7], true)) {
            throw new InvalidArgumentException('Unsupported season reminder checkpoint.');
        }
        $end = $this->utc((string)($season['calendar_end_at_utc'] ?? ''));
        return $end->modify('-' . $daysBeforeEnd . ' days')->format('Y-m-d H:i:s.u');
    }

    public function utc(string $timestamp): DateTimeImmutable
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') throw new InvalidArgumentException('UTC timestamp is required.');
        return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
