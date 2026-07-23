<?php
declare(strict_types=1);

final class ProductionRuntimePrimaryContract
{
    public static function inspect(string $projectRoot): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        $targets = [
            'api' => $projectRoot . '/bot/api.php',
            'webhook_handler' => $projectRoot . '/bot/handlers/WebhookHandler.php',
        ];

        $checks = [];
        $blockers = [];
        foreach ($targets as $name => $path) {
            $source = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($source)) {
                $checks[$name] = [
                    'source_present' => false,
                    'coordinator_present' => false,
                    'direct_json_factory_absent' => false,
                    'source_sha256' => '',
                ];
                $blockers[] = 'production runtime entrypoint source is unavailable: ' . $name;
                continue;
            }

            $coordinatorPresent = str_contains($source, 'ProductionPrimaryRuntimeCoordinator');
            $directJsonFactoryAbsent = !str_contains($source, 'StorageFactory::createJson(');
            $checks[$name] = [
                'source_present' => true,
                'coordinator_present' => $coordinatorPresent,
                'direct_json_factory_absent' => $directJsonFactoryAbsent,
                'source_sha256' => hash('sha256', $source),
            ];

            if (!$coordinatorPresent) {
                $blockers[] = 'production runtime entrypoint is not wired to the DB-primary coordinator: ' . $name;
            }
            if (!$directJsonFactoryAbsent) {
                $blockers[] = 'production runtime entrypoint still opens direct JSON transactions: ' . $name;
            }
        }

        $coordinatorPath = $projectRoot . '/bot/runtime/ProductionPrimaryRuntimeCoordinator.php';
        $coordinatorSource = is_file($coordinatorPath) ? file_get_contents($coordinatorPath) : false;
        $coordinatorReady = is_string($coordinatorSource)
            && str_contains($coordinatorSource, "public const CONTRACT_VERSION = 'v1-db-primary-all-modules'")
            && str_contains($coordinatorSource, 'public function executeApiRequest(')
            && str_contains($coordinatorSource, 'public function executeWebhookMutation(');
        if (!$coordinatorReady) {
            $blockers[] = 'production DB-primary runtime coordinator is missing or incomplete';
        }

        $checks['coordinator'] = [
            'source_present' => is_string($coordinatorSource),
            'contract_ready' => $coordinatorReady,
            'source_sha256' => is_string($coordinatorSource) ? hash('sha256', $coordinatorSource) : '',
        ];
        $blockers = array_values(array_unique($blockers));

        return [
            'ready' => $blockers === [],
            'contract_version' => 'v1-db-primary-all-modules',
            'contract_fingerprint' => hash('sha256', self::canonicalJson($checks)),
            'checks' => $checks,
            'blockers' => $blockers,
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
