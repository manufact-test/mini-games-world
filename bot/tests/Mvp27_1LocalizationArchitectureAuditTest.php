<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/runtime/localization/LocalizationCatalog.php';

final class Mvp27_1LocalizationArchitectureAuditTest
{
    private int $assertions = 0;
    private string $dir;

    public function run(): void
    {
        $this->dir = sys_get_temp_dir() . '/mgw-mvp27-i18n-' . bin2hex(random_bytes(6));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Cannot create localization fixture directory.');
        }

        try {
            $this->writeFixture();
            $catalog = new LocalizationCatalog($this->dir);

            $this->same('ru', $catalog->defaultLocale(), 'Synthetic default locale mismatch.');
            $this->same(['ru','en'], $catalog->supportedLocales(), 'Server catalog must support an EN catalog structurally.');
            $this->same('Home', $catalog->translate('nav.home', [], 'en'), 'Server EN translation lookup failed.');
            $this->same('1 coin', $catalog->plural('units.coin', 1, [], 'en'), 'Server EN singular form failed.');
            $this->same('2 coins', $catalog->plural('units.coin', 2, [], 'en'), 'Server EN other form failed.');
            $this->same('12,345', $catalog->formatNumber(12345, 0, 'en'), 'Server EN number formatting fallback failed.');

            $date = new DateTimeImmutable('2026-10-01 15:30:00', new DateTimeZone('UTC'));
            $this->same('2026-10-01', $catalog->formatDate($date, 'short', 'en'), 'Server EN date fallback failed.');
            $this->same('Tic-Tac-Toe', $catalog->rules('tictactoe', 'en')['title'] ?? null,
                'Server rules metadata must resolve EN once the catalog exists.');

            echo 'Mvp27_1LocalizationArchitectureAuditTest passed: ' . $this->assertions . " assertions.\n";
        } finally {
            $this->removeFixture();
        }
    }

    private function writeFixture(): void
    {
        $manifest = [
            'schema_version' => 1,
            'default_locale' => 'ru',
            'fallback_locale' => 'ru',
            'supported_locales' => ['ru','en'],
            'catalogs' => ['ru'=>'ru.json','en'=>'en.json'],
            'formats' => [],
            'rules' => [
                'games' => [
                    'tictactoe' => ['version'=>2,'languages'=>['ru','en'],'title_key'=>'games.tictactoe.name'],
                    'four_in_a_row' => ['version'=>2,'languages'=>['ru'],'title_key'=>'games.four_in_a_row.name'],
                    'battleship' => ['version'=>1,'languages'=>['ru'],'title_key'=>'games.battleship.name'],
                    'checkers' => ['version'=>1,'languages'=>['ru'],'title_key'=>'games.checkers.name'],
                    'reversi' => ['version'=>2,'languages'=>['ru'],'title_key'=>'games.reversi.name'],
                    'chess' => ['version'=>1,'languages'=>['ru'],'title_key'=>'games.chess.name'],
                    'go' => ['version'=>2,'languages'=>['ru'],'title_key'=>'games.go.name'],
                    'domino' => ['version'=>1,'languages'=>['ru'],'title_key'=>'games.domino.name'],
                ],
            ],
        ];
        $ru = [
            'nav'=>['home'=>'Главная'],
            'games'=>[
                'tictactoe'=>['name'=>'Крестики-нолики'],
                'four_in_a_row'=>['name'=>'Четыре в ряд'],
                'battleship'=>['name'=>'Морской бой'],
                'checkers'=>['name'=>'Русские шашки'],
                'reversi'=>['name'=>'Реверси'],
                'chess'=>['name'=>'Шахматы'],
                'go'=>['name'=>'Го'],
                'domino'=>['name'=>'Домино'],
            ],
            'units'=>['coin'=>['one'=>'{count} коин','few'=>'{count} коина','many'=>'{count} коинов','other'=>'{count} коина']],
        ];
        $en = [
            'nav'=>['home'=>'Home'],
            'games'=>['tictactoe'=>['name'=>'Tic-Tac-Toe']],
            'units'=>['coin'=>['one'=>'{count} coin','other'=>'{count} coins']],
        ];

        foreach (['manifest.json'=>$manifest,'ru.json'=>$ru,'en.json'=>$en] as $name=>$payload) {
            file_put_contents(
                $this->dir . '/' . $name,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }
    }

    private function removeFixture(): void
    {
        if (!isset($this->dir) || !is_dir($this->dir)) return;
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' Expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . '.');
        }
    }
}

(new Mvp27_1LocalizationArchitectureAuditTest())->run();
