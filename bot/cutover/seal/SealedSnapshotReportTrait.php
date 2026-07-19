<?php
declare(strict_types=1);

trait SealedSnapshotReportTrait
{
    private function nowUtc(): string
    {
        return gmdate(DATE_ATOM);
    }

    private function withFingerprint(array $report): array
    {
        unset($report['report_fingerprint']);
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $report['report_fingerprint'] = hash('sha256', $json);
        return $report;
    }
}
