<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionProjectorInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryModuleProjectorInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryAllModuleProjector.php';

final class RuntimePrimaryAllModuleTestProjector implements RuntimePrimaryModuleProjectorInterface
{
    public int $projectCalls = 0;
    public int $auditCalls = 0;

    public function __construct(
        private string $name,
        private array $projectOverrides = [],
        private array $auditOverrides = [],
        private bool $mismatchUntilProjected = false
    ) {}

    public function module(): string
    {
        return $this->name;
    }

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->projectCalls++;
        return array_replace(
            $this->report($snapshot, $stateRevision, $stateSha256, false),
            $this->projectOverrides
        );
    }

    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->auditCalls++;
        $report = $this->report($snapshot, $stateRevision, $stateSha256, true);
        if ($this->mismatchUntilProjected && $this->projectCalls === 0) {
            $report['ok'] = false;
            $report['parity'] = false;
            $report['database_fingerprint'] = str_repeat('b', 64);
            $report['blockers'] = ['normalized module is stale'];
        }
        return array_replace($report, $this->auditOverrides);
    }

    private function report(array $snapshot, int $stateRevision, string $stateSha256, bool $readOnly): array
    {
        $moduleFingerprint = hash('sha256', $this->canonicalJson([
            'module' => $this->name,
            'snapshot' => $snapshot,
        ]));
        return [
            'ok' => true,
            'parity' => true,
            'read_only' => $readOnly,
            'module' => $this->name,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'source_fingerprint' => $moduleFingerprint,
            'database_fingerprint' => $moduleFingerprint,
            'summary' => ['records' => count($snapshot)],
            'blockers' => [],
        ];
    }

    private function canonicalJson(array $value): string
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

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};
$canonicalJson = static function (array $value): string {
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
};
$modules = [
    'accounts', 'realtime', 'economy', 'notifications', 'invites',
    'history', 'shop', 'payments', 'weekly_bonus',
];
$makeProjectors = static function (array $overrides = []) use ($modules): array {
    $items = [];
    foreach ($modules as $module) {
        $settings = is_array($overrides[$module] ?? null) ? $overrides[$module] : [];
        $items[] = new RuntimePrimaryAllModuleTestProjector(
            $module,
            is_array($settings['project'] ?? null) ? $settings['project'] : [],
            is_array($settings['audit'] ?? null) ? $settings['audit'] : [],
            ($settings['mismatch_until_projected'] ?? false) === true
        );
    }
    return $items;
};

$snapshot = [
    'users' => ['100' => ['id' => '100', 'balance' => 50]],
    'games' => [],
    'notifications' => [],
    'system' => ['sequence' => 1],
];
$stateSha = hash('sha256', $canonicalJson($snapshot));

$currentProjectors = $makeProjectors();
$currentSubject = new RuntimePrimaryAllModuleProjector($currentProjectors);
$currentResult = $currentSubject->project($snapshot, 7, $stateSha);
$assertTrue(($currentResult['ok'] ?? false) === true, 'All-current projection must succeed');
$assertTrue(($currentResult['parity_ok'] ?? false) === true, 'All-current projection must prove parity');
$assertTrue(($currentResult['projected_modules'] ?? []) === $modules, 'Projection must preserve module order');
$assertTrue(($currentResult['mutated_modules'] ?? null) === [], 'All-current projection must mutate no module');
$assertTrue(($currentResult['unchanged_modules'] ?? []) === $modules, 'All-current projection must report all modules unchanged');
$assertTrue(count((array)($currentResult['project_reports'] ?? [])) === 9, 'Projection must return nine project reports');
$assertTrue(count((array)($currentResult['audit_reports'] ?? [])) === 9, 'Projection must return nine audit reports');
$assertTrue(($currentResult['read_only'] ?? true) === false, 'Projection pass must not claim read-only behavior');
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', (string)($currentResult['all_module_fingerprint'] ?? '')) === 1,
    'Projection must expose a deterministic fingerprint'
);
foreach ($currentProjectors as $projector) {
    $assertTrue($projector->projectCalls === 0, 'An already current module must not project');
    $assertTrue($projector->auditCalls === 1, 'An already current module must audit exactly once');
}

$deltaProjectors = $makeProjectors([
    'realtime' => ['mismatch_until_projected' => true],
]);
$deltaSubject = new RuntimePrimaryAllModuleProjector($deltaProjectors);
$deltaResult = $deltaSubject->project($snapshot, 7, $stateSha);
$assertTrue(($deltaResult['mutated_modules'] ?? []) === ['realtime'], 'Only stale realtime module must project');
$assertTrue(count((array)($deltaResult['unchanged_modules'] ?? [])) === 8, 'Eight current modules must remain unchanged');
foreach ($deltaProjectors as $projector) {
    if ($projector->module() === 'realtime') {
        $assertTrue($projector->projectCalls === 1, 'Stale realtime module must project once');
        $assertTrue($projector->auditCalls === 2, 'Stale realtime module must audit before and after projection');
    } else {
        $assertTrue($projector->projectCalls === 0, 'Unchanged module must skip projection');
        $assertTrue($projector->auditCalls === 1, 'Unchanged module must audit once');
    }
}

$auditProjectors = $makeProjectors();
$auditSubject = new RuntimePrimaryAllModuleProjector($auditProjectors);
$audit = $auditSubject->auditOnly($snapshot, 7, $stateSha);
$assertTrue(($audit['ok'] ?? false) === true, 'Audit-only pass must succeed');
$assertTrue(($audit['parity_ok'] ?? false) === true, 'Audit-only pass must prove parity');
$assertTrue(($audit['read_only'] ?? false) === true, 'Audit-only pass must identify itself as read-only');
$assertTrue(($audit['projected_modules'] ?? []) === $modules, 'Audit-only pass must verify all nine modules');
$assertTrue(count((array)($audit['audit_reports'] ?? [])) === 9, 'Audit-only pass must return nine reports');
$assertTrue(
    hash_equals((string)$currentResult['all_module_fingerprint'], (string)$audit['all_module_fingerprint']),
    'No-op projection and later audit must produce the same fingerprint'
);
foreach ($auditProjectors as $projector) {
    $assertTrue($projector->projectCalls === 0, 'Audit-only pass must never project');
    $assertTrue($projector->auditCalls === 1, 'Audit-only pass must audit every module once');
}

$assertThrows(
    static fn() => new RuntimePrimaryAllModuleProjector(array_slice($makeProjectors(), 0, 8)),
    'missing required modules'
);
$duplicates = $makeProjectors();
$duplicates[] = new RuntimePrimaryAllModuleTestProjector('accounts');
$assertThrows(
    static fn() => new RuntimePrimaryAllModuleProjector($duplicates),
    'duplicate module'
);
$unsupported = $makeProjectors();
$unsupported[8] = new RuntimePrimaryAllModuleTestProjector('unknown');
$assertThrows(
    static fn() => new RuntimePrimaryAllModuleProjector($unsupported),
    'unsupported module'
);
$assertThrows(
    static fn() => $currentSubject->project($snapshot, 0, $stateSha),
    'revision must be positive'
);
$assertThrows(
    static fn() => $auditSubject->auditOnly($snapshot, 0, $stateSha),
    'revision must be positive'
);
$assertThrows(
    static fn() => $currentSubject->project($snapshot, 7, str_repeat('0', 64)),
    'snapshot fingerprint mismatch'
);

$badProject = new RuntimePrimaryAllModuleProjector($makeProjectors([
    'economy' => [
        'mismatch_until_projected' => true,
        'project' => ['parity' => false],
    ],
]));
$assertThrows(
    static fn() => $badProject->project($snapshot, 7, $stateSha),
    'did not pass parity: economy'
);
$badAudit = new RuntimePrimaryAllModuleProjector($makeProjectors([
    'history' => ['audit' => ['read_only' => false]],
]));
$assertThrows(
    static fn() => $badAudit->auditOnly($snapshot, 7, $stateSha),
    'audit is not read-only: history'
);
$wrongRevision = new RuntimePrimaryAllModuleProjector($makeProjectors([
    'shop' => ['audit' => ['state_revision' => 8]],
]));
$assertThrows(
    static fn() => $wrongRevision->project($snapshot, 7, $stateSha),
    'wrong revision: shop'
);
$wrongState = new RuntimePrimaryAllModuleProjector($makeProjectors([
    'payments' => ['audit' => ['state_sha256' => str_repeat('a', 64)]],
]));
$assertThrows(
    static fn() => $wrongState->auditOnly($snapshot, 7, $stateSha),
    'wrong state fingerprint: payments'
);
$mismatch = new RuntimePrimaryAllModuleProjector($makeProjectors([
    'weekly_bonus' => ['audit' => ['database_fingerprint' => str_repeat('b', 64)]],
]));
$assertThrows(
    static fn() => $mismatch->auditOnly($snapshot, 7, $stateSha),
    'fingerprints differ: weekly_bonus'
);
$blockers = new RuntimePrimaryAllModuleProjector($makeProjectors([
    'notifications' => ['audit' => ['blockers' => ['notification mismatch']]],
]));
$assertThrows(
    static fn() => $blockers->auditOnly($snapshot, 7, $stateSha),
    'contains blockers: notifications'
);

fwrite(STDOUT, "RuntimePrimaryAllModuleProjectorTest passed: {$assertions} assertions.\n");
