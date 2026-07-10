<?php
declare(strict_types=1);

final class ShopCatalogService
{
    private ?array $catalog = null;

    public function __construct(private array $config) {}

    public function publicCatalog(): array
    {
        $catalog = $this->catalog();

        return [
            'version' => (int)($catalog['version'] ?? 1),
            'currency' => (string)($catalog['currency'] ?? 'GOLD'),
            'updated_at' => (string)($catalog['updated_at'] ?? ''),
            'countries' => $catalog['countries'] ?? [],
            'items' => $catalog['items'] ?? [],
        ];
    }

    public function items(): array
    {
        return $this->catalog()['items'] ?? [];
    }

    public function countries(): array
    {
        return $this->catalog()['countries'] ?? [];
    }

    public function minGoldCost(): int
    {
        $costs = [];
        foreach ($this->items() as $item) {
            foreach (($item['denominations'] ?? []) as $denomination) {
                $cost = (int)($denomination['gold_cost'] ?? 0);
                if ($cost > 0) {
                    $costs[] = $cost;
                }
            }
        }

        return $costs ? min($costs) : (int)($this->config['shop_min_order'] ?? 1000);
    }

    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $raw = $this->loadRawCatalog();
        $countries = $this->normalizeCountries($raw['countries'] ?? []);
        $countryNames = [];
        foreach ($countries as $country) {
            $countryNames[(string)$country['code']] = (string)$country['name'];
        }

        $items = $this->normalizeItems($raw['items'] ?? [], $countryNames);
        $this->catalog = [
            'version' => max(1, (int)($raw['version'] ?? 1)),
            'currency' => trim((string)($raw['currency'] ?? 'GOLD')) ?: 'GOLD',
            'updated_at' => trim((string)($raw['updated_at'] ?? '')),
            'countries' => $countries,
            'items' => $items,
        ];

        return $this->catalog;
    }

    private function loadRawCatalog(): array
    {
        $file = trim((string)($this->config['shop_catalog_file'] ?? ''));
        if ($file === '') {
            $file = __DIR__ . '/../catalog/prizes.php';
        }

        if (!is_file($file)) {
            error_log('Mini Games World shop catalog not found: ' . $file);
            return [];
        }

        $catalog = require $file;
        if (!is_array($catalog)) {
            error_log('Mini Games World shop catalog must return an array: ' . $file);
            return [];
        }

        return $catalog;
    }

    private function normalizeCountries(array $countries): array
    {
        $result = [];
        $seen = [];

        foreach ($countries as $country) {
            if (!is_array($country) || empty($country['enabled'])) {
                continue;
            }

            $code = strtoupper(trim((string)($country['code'] ?? '')));
            $name = trim((string)($country['name'] ?? ''));
            if ($code === '' || $name === '' || isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $result[] = [
                'code' => $code,
                'name' => $name,
                'sort_order' => (int)($country['sort_order'] ?? 1000),
            ];
        }

        usort($result, fn(array $a, array $b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['name'], $b['name']));
        return $result;
    }

    private function normalizeItems(array $items, array $countryNames): array
    {
        $result = [];
        $seenItems = [];
        $seenDenominations = [];

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['enabled'])) {
                continue;
            }

            $id = trim((string)($item['id'] ?? ''));
            $countryCode = strtoupper(trim((string)($item['country_code'] ?? '')));
            $provider = trim((string)($item['provider'] ?? ''));
            $providerCode = trim((string)($item['provider_code'] ?? ''));
            $title = trim((string)($item['title'] ?? $provider));

            if ($id === '' || isset($seenItems[$id]) || !isset($countryNames[$countryCode]) || $provider === '' || $providerCode === '') {
                continue;
            }

            $denominations = [];
            foreach (($item['denominations'] ?? []) as $denomination) {
                if (!is_array($denomination) || empty($denomination['enabled'])) {
                    continue;
                }

                $denominationId = trim((string)($denomination['id'] ?? ''));
                $goldCost = (int)($denomination['gold_cost'] ?? 0);
                if ($denominationId === '' || $goldCost <= 0 || isset($seenDenominations[$denominationId])) {
                    continue;
                }

                $seenDenominations[$denominationId] = true;
                $denominations[] = [
                    'id' => $denominationId,
                    'label' => trim((string)($denomination['label'] ?? ($goldCost . ' Gold'))),
                    'gold_cost' => $goldCost,
                    'sort_order' => (int)($denomination['sort_order'] ?? 1000),
                ];
            }

            usort($denominations, fn(array $a, array $b) => ($a['sort_order'] <=> $b['sort_order']) ?: ($a['gold_cost'] <=> $b['gold_cost']));
            if (!$denominations) {
                continue;
            }

            $seenItems[$id] = true;
            $result[] = [
                'id' => $id,
                'country_code' => $countryCode,
                'country' => $countryNames[$countryCode],
                'provider_code' => $providerCode,
                'provider' => $provider,
                'title' => $title !== '' ? $title : $provider,
                'description' => trim((string)($item['description'] ?? '')),
                'delivery_type' => trim((string)($item['delivery_type'] ?? 'manual_code')) ?: 'manual_code',
                'image' => trim((string)($item['image'] ?? '')),
                'image_alt' => trim((string)($item['image_alt'] ?? $title)),
                'sort_order' => (int)($item['sort_order'] ?? 1000),
                'min_amount' => (int)$denominations[0]['gold_cost'],
                'denominations' => $denominations,
            ];
        }

        usort($result, fn(array $a, array $b) => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['title'], $b['title']));
        return $result;
    }
}
