<?php
declare(strict_types=1);

final class LocalizationCatalog
{
    private array $manifest;
    private array $catalogs = [];

    public function __construct(private readonly string $localeDirectory)
    {
        $this->manifest = $this->loadJson($this->localeDirectory . '/manifest.json');
        $this->validateManifest();

        foreach ($this->manifest['catalogs'] as $locale => $relativePath) {
            $this->catalogs[(string)$locale] = $this->loadJson(
                $this->localeDirectory . '/' . ltrim((string)$relativePath, '/')
            );
        }
    }

    public function defaultLocale(): string
    {
        return (string)$this->manifest['default_locale'];
    }

    public function supportedLocales(): array
    {
        return array_values($this->manifest['supported_locales']);
    }

    public function clientPayload(): array
    {
        return [
            'manifest' => $this->manifest,
            'catalogs' => $this->catalogs,
        ];
    }

    public function translate(string $key, array $params = [], ?string $locale = null): string
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $value = $this->readPath($this->catalogs[$resolvedLocale], $key);
        if (!is_string($value)) {
            throw new RuntimeException('Missing translation key: ' . $key);
        }

        return $this->interpolate($value, $params);
    }

    public function plural(string $key, int|float $count, array $params = [], ?string $locale = null): string
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $forms = $this->readPath($this->catalogs[$resolvedLocale], $key);
        if (!is_array($forms)) {
            throw new RuntimeException('Missing plural translation key: ' . $key);
        }

        $category = $this->pluralCategory($resolvedLocale, $count);
        $template = $forms[$category] ?? $forms['other'] ?? null;
        if (!is_string($template)) {
            throw new RuntimeException('Missing plural form: ' . $key . '.' . $category);
        }

        return $this->interpolate($template, ['count' => $count] + $params);
    }

    public function formatNumber(int|float $value, int $fractionDigits = 0, ?string $locale = null): string
    {
        $resolvedLocale = $this->resolveLocale($locale);
        if ($resolvedLocale === 'ru') {
            return number_format($value, max(0, $fractionDigits), ',', "\u{00A0}");
        }

        return number_format($value, max(0, $fractionDigits), '.', ',');
    }

    public function formatDate(DateTimeInterface $value, string $style = 'short', ?string $locale = null): string
    {
        $resolvedLocale = $this->resolveLocale($locale);
        if ($resolvedLocale === 'ru') {
            return $style === 'long' ? $this->formatRussianLongDate($value) : $value->format('d.m.Y');
        }

        return $value->format('Y-m-d');
    }

    public function formatDateTime(DateTimeInterface $value, string $style = 'short', ?string $locale = null): string
    {
        return $this->formatDate($value, $style, $locale) . ' ' . $value->format('H:i');
    }

    public function rules(string $gameType, ?string $locale = null): array
    {
        $resolvedLocale = $this->resolveLocale($locale);
        $entry = $this->manifest['rules']['games'][$gameType] ?? null;
        if (!is_array($entry)) {
            throw new RuntimeException('Rules metadata is unavailable: ' . $gameType);
        }
        if (!in_array($resolvedLocale, $entry['languages'] ?? [], true)) {
            throw new RuntimeException('Rules language is unavailable: ' . $gameType . '/' . $resolvedLocale);
        }

        $titleKey = (string)($entry['title_key'] ?? '');
        return $entry + [
            'locale' => $resolvedLocale,
            'title' => $this->translate($titleKey, [], $resolvedLocale),
        ];
    }

    private function validateManifest(): void
    {
        if (($this->manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported localization manifest schema.');
        }

        $defaultLocale = $this->manifest['default_locale'] ?? null;
        $fallbackLocale = $this->manifest['fallback_locale'] ?? null;
        $supported = $this->manifest['supported_locales'] ?? null;
        $catalogs = $this->manifest['catalogs'] ?? null;
        if (!is_string($defaultLocale) || !is_string($fallbackLocale) || !is_array($supported) || !is_array($catalogs)) {
            throw new RuntimeException('Localization manifest is incomplete.');
        }
        if (!in_array($defaultLocale, $supported, true) || !in_array($fallbackLocale, $supported, true)) {
            throw new RuntimeException('Localization default/fallback locale is unsupported.');
        }
        foreach ($supported as $locale) {
            if (!is_string($locale) || !isset($catalogs[$locale]) || !is_string($catalogs[$locale])) {
                throw new RuntimeException('Localization catalog mapping is incomplete.');
            }
        }

        $rules = $this->manifest['rules']['games'] ?? null;
        if (!is_array($rules) || count($rules) !== 8) {
            throw new RuntimeException('Rules localization manifest must describe all eight games.');
        }
        foreach ($rules as $gameType => $entry) {
            if (!is_string($gameType)
                || !is_array($entry)
                || !is_int($entry['version'] ?? null)
                || ($entry['version'] ?? 0) < 1
                || !is_array($entry['languages'] ?? null)
                || !is_string($entry['title_key'] ?? null)) {
                throw new RuntimeException('Rules localization metadata is invalid.');
            }
        }
    }

    private function resolveLocale(?string $locale): string
    {
        $candidate = trim((string)($locale ?? $this->manifest['default_locale']));
        if (in_array($candidate, $this->manifest['supported_locales'], true) && isset($this->catalogs[$candidate])) {
            return $candidate;
        }

        $fallback = (string)$this->manifest['fallback_locale'];
        if (!isset($this->catalogs[$fallback])) {
            throw new RuntimeException('Localization fallback catalog is unavailable.');
        }
        return $fallback;
    }

    private function pluralCategory(string $locale, int|float $count): string
    {
        if ((float)(int)$count !== (float)$count) {
            return 'other';
        }

        $number = abs((int)$count);
        if ($locale === 'ru') {
            $mod10 = $number % 10;
            $mod100 = $number % 100;
            if ($mod10 === 1 && $mod100 !== 11) {
                return 'one';
            }
            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
                return 'few';
            }
            return 'many';
        }

        return $number === 1 ? 'one' : 'other';
    }

    private function formatRussianLongDate(DateTimeInterface $value): string
    {
        $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];
        return (int)$value->format('j') . ' ' . $months[(int)$value->format('n')] . ' ' . $value->format('Y');
    }

    private function readPath(array $source, string $key): mixed
    {
        $value = $source;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function interpolate(string $template, array $params): string
    {
        return (string)preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static function (array $match) use ($params): string {
            $key = $match[1];
            return array_key_exists($key, $params) ? (string)$params[$key] : $match[0];
        }, $template);
    }

    private function loadJson(string $path): array
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Localization source is unavailable: ' . basename($path));
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Localization source is invalid: ' . basename($path), 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Localization source must be an object: ' . basename($path));
        }
        return $decoded;
    }
}
