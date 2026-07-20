<?php
declare(strict_types=1);

final class RuntimePrimaryEntrypointEvidence
{
    public static function inspect(string $projectRoot): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        if ($projectRoot === '' || !is_dir($projectRoot)) {
            throw new InvalidArgumentException('Entrypoint evidence project root is unavailable.');
        }

        $targets = [
            'api' => $projectRoot . '/bot/api.php',
            'webhook_handler' => $projectRoot . '/bot/handlers/WebhookHandler.php',
        ];
        $evidence = [];
        foreach ($targets as $name => $path) {
            $source = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($source)) {
                throw new RuntimeException('Entrypoint evidence source is unavailable: ' . $name . '.');
            }
            $evidence[$name] = [
                'source_sha256' => hash('sha256', $source),
                'direct_json_factory_present' => str_contains($source, 'StorageFactory::createJson('),
                'db_primary_coordinator_present' => str_contains($source, 'ProductionPrimaryRuntimeCoordinator'),
            ];
        }

        return [
            'contract_version' => 'v1-json-first-entrypoints',
            'entrypoints' => $evidence,
            'contract_fingerprint' => hash('sha256', self::canonicalJson($evidence)),
            'application_entrypoints_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
