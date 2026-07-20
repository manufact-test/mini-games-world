<?php
declare(strict_types=1);

interface RuntimePrimaryStagingEvidenceSourceInterface
{
    public function repositoryCommit(): string;
    public function phpEvidence(): array;
    public function databaseEvidence(): array;
    public function captureJsonEvidence(): array;
    public function runRehearsal(): array;
    public function concurrencyEvidence(): array;
    public function entrypointEvidence(): array;
}
