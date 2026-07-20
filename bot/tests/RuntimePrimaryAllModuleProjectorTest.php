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
        private array $auditOverrides = []
    ) {}

    public function module(): string
    {
        return $this->name;
    }

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->projectCalls++;
        return array_replace($this->report($snapshot, $stateRevision, $stateSha256, false), $this->projectOverrides);
    }

    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->auditCalls++;
        return array_replace($this->report($snapshot, $stateRevision, $stateSha256, true), $this->auditOverrides);
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
            is_array($settings['audit'] ?? null) ? $settings['audit'] : []
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
$projectors = $makeProjectors();
$subject = new RuntimePrimaryAllModuleProjector($projectors);
$result = $subject->project($snapshot, 7, $stateSha);
$assertTrue(($result['ok'] ?? false) === true, 'All-module projection must succeed');
$assertTrue(($result['parity_ok'] ?? false) === true, 'All-module projection must prove parity');
$assertTrue(($result['state_revision'] ?? 0) === 7, 'All-module projection must preserve revision');
$assertTrue(hash_equals($stateSha, (string)($result['state_sha256'] ?? '')), 'All-module projection must preserve state fingerprint');
$assertTrue(($result['projected_modules'] ?? []) === $modules, 'All-module projection must preserve dependency order');
$assertTrue(count((array)($result['project_reports'] ?? [])) === 9, 'All-module projection must return nine project reports');
$assertTrue(count((array)($result['audit_reports'] ?? [])) === 9, 'All-module projection must return nine audit reports');
$assertTrue(($result['read_only'] ?? true) === false, 'Projection pass must not claim read-only behavior');
$assertTrue(
    preg_match('/^[a-f0-9]{64}$/', (string)($result['all_module_fingerprint'] ?? '')) === 1,
    'All-module projection must expose a deterministic fingerprint'
);
foreach ($projectors as $projector) {
    $assertTrue($projector->projectCalls === 1, 'Each module must project exactly once');
    $assertTrue($projector->auditCalls === 1, 'Each module must audit exactly once');
}

$auditProjectors = $makeProjectors();
$auditSubject = new RuntimePrimaryAllModuleProjector($auditProjectors);
$audit = $auditSubject->auditOnly($snapshot, 7, $stateSha);
$assertTrue(($audit['ok'] ?? false) === true, 'Audit-only pass must succeed');
$assertTrue(($audit['parity_ok'] ?? false) === true, 'Audit-only pass must prove parity');
$assertTrue(($audit['read_only'] ?? false) === true, 'Audit-only pass must identify itself as read-only');
$assertTrue(($audit['projected_modules'] ?? []) === $modules, 'Audit-only pass must verify all nine modules');
$assertTrue(count((array)($audit['audit_reports'] ?? [])) === 9, 'Audit-only pass must return nine audit reports');
$assertTrue(
    hash_equals((string)$result['all_module_fingerprint'], (string)$audit['all_module_fingerprint']),
    'Projection and later audit-only pass must produce the same all-module fingerprint'
);
foreach ($auditProjectors as $projector) {
    $assertTrue($projector->projectCalls === 0, 'Audit-only pass must never invoke a project mutation');
    $assertTrue($projector->auditCalls === 1, 'Audit-only pass must audit every module exactly once');
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
    static fn() => $subject->project($snapshot, 0, $stateSha),
    'revision must be positive'
);
$assertThrows(
    static fn() => $auditSubject->auditOnly($snapshot, 0, $stateSha),
    'revision must be positive'
);
$assertThrows(
    static fn() => $subject->project($snapshot, 7, str_repeat('0', 64)),
    'snapshot fingerprint mismatch'
);
$assertThrows(
    static fn() => $auditSubject->auditOnly($snapshot, 7, str_repeat('0', 64)),
    'snapshot fingerprint mismatch'
);

$badProject = new RuntimePrimaryAllModuleProjector($makeProjectors([
    'economy' => ['project' => ['parity' => false]],
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
    static fn() => $wrongRevision->auditOnly($snapshot, 7, $stateSha),
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
