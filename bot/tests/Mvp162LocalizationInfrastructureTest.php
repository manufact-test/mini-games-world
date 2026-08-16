<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/runtime/localization/LocalizationCatalog.php';

final class Mvp162LocalizationInfrastructureTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = new LocalizationCatalog($root . '/app/locales');

        $this->same('ru', $catalog->defaultLocale(), 'RU must remain the default locale.');
        $this->same(['ru'], $catalog->supportedLocales(), 'MVP-16.2 must not pretend the full EN translation exists.');
        $this->same('Главная', $catalog->translate('nav.home'), 'Server translation keys must resolve from the shared RU catalog.');
        $this->same('1 коин', $catalog->plural('units.coin', 1), 'Russian one plural form must be correct.');
        $this->same('2 коина', $catalog->plural('units.coin', 2), 'Russian few plural form must be correct.');
        $this->same('5 коинов', $catalog->plural('units.coin', 5), 'Russian many plural form must be correct.');
        $this->same("12\u{00A0}345", $catalog->formatNumber(12345), 'Russian number grouping must be deterministic.');

        $date = new DateTimeImmutable('2026-08-16 15:06:00', new DateTimeZone('Europe/Vilnius'));
        $this->same('16.08.2026', $catalog->formatDate($date), 'Short RU date format must be deterministic.');
        $this->same('16.08.2026 15:06', $catalog->formatDateTime($date), 'Short RU datetime format must be deterministic.');

        $expectedGames = ['tictactoe', 'four_in_a_row', 'battleship', 'checkers', 'reversi', 'chess', 'go', 'domino'];
        foreach ($expectedGames as $gameType) {
            $rules = $catalog->rules($gameType);
            $this->true((int)($rules['version'] ?? 0) >= 1, 'Every rules entry must be explicitly versioned.');
            $this->true(in_array('ru', $rules['languages'] ?? [], true), 'Every accepted game must declare RU rules language.');
            $this->true(is_string($rules['title'] ?? null) && ($rules['title'] ?? '') !== '', 'Every rules entry must resolve its localized game title.');
        }
        $this->same(2, $catalog->rules('tictactoe')['version'] ?? null, 'Tic-Tac-Toe variant-aware rules must expose rules version 2.');

        $manifest = require $root . '/app/runtime/client/version-manifest.php';
        $this->same('keys-v1', $manifest['localization']['version'] ?? null, 'Client manifest must own the localization version.');
        $this->same('ru', $manifest['localization']['default_locale'] ?? null, 'Client manifest locale must match the shared catalog.');
        $this->true(isset($manifest['imports']['@mgw/i18n']), 'Client manifest must own one stable i18n import alias.');

        $entry = file_get_contents($root . '/app/v110.php');
        $this->true(is_string($entry) && str_contains($entry, 'id="mgw-localization"'), 'Canonical /start entry must inline the shared locale payload.');
        $this->true(is_string($entry) && str_contains($entry, 'X-MGW-Localization: keys-v1'), 'Canonical /start entry must expose the localization contract version.');

        echo 'Mvp162LocalizationInfrastructureTest passed: ' . $this->assertions . " assertions.\n";
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
        }
    }

    private function true(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

(new Mvp162LocalizationInfrastructureTest())->run();
