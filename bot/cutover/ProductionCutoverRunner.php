<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductionCutoverPackageManifest.php';
require_once __DIR__ . '/ProductionRuntimePrimaryContract.php';
require_once __DIR__ . '/ProductionCutoverRunTrait.php';
require_once __DIR__ . '/ProductionCutoverPerformTrait.php';
require_once __DIR__ . '/ProductionCutoverReleaseTrait.php';
require_once __DIR__ . '/ProductionCutoverControlTrait.php';
require_once __DIR__ . '/ProductionCutoverNoopTrait.php';
require_once __DIR__ . '/ProductionCutoverDataTrait.php';
require_once __DIR__ . '/ProductionCutoverRuntimeTrait.php';
require_once __DIR__ . '/ProductionCutoverReportTrait.php';
require_once __DIR__ . '/ProductionCutoverRecoveryPolicyTrait.php';
require_once __DIR__ . '/ProductionCutoverPackageGuardTrait.php';

final class ProductionCutoverRunner
{
    use ProductionCutoverRunTrait;
    use ProductionCutoverPerformTrait;
    use ProductionCutoverReleaseTrait;
    use ProductionCutoverNoopTrait;
    use ProductionCutoverDataTrait;
    use ProductionCutoverControlTrait,
        ProductionCutoverRuntimeTrait,
        ProductionCutoverReportTrait,
        ProductionCutoverRecoveryPolicyTrait,
        ProductionCutoverPackageGuardTrait {
        ProductionCutoverRecoveryPolicyTrait::rollback
            insteadof ProductionCutoverControlTrait;
        ProductionCutoverRecoveryPolicyTrait::automaticRollbackReport
            insteadof ProductionCutoverReportTrait;
        ProductionCutoverPackageGuardTrait::assertEnvironmentAndBuild
            insteadof ProductionCutoverRuntimeTrait;
        ProductionCutoverPackageGuardTrait::assertControlEnvironmentAndBuild
            insteadof ProductionCutoverControlTrait;
    }

    public const BUILD = 'v103-mvp14-production-cutover';
    public const PACKAGE_VERSION = 'v1-mvp14-10e-cutover-recovery-package';
    private const MODULES = [
        'accounts',
        'realtime',
        'invites',
        'notifications',
        'economy',
        'history',
        'shop',
        'payments',
        'weekly_bonus',
    ];
    private const ACTIVE_STATES = ['running', 'switching', 'validating'];

    private string $projectRoot;
    private string $privateDir;
    private string $runtimeFile;
    private string $runtimeBackupFile;
    private string $stateFile;
    private string $writeBlockFile;

    public function __construct(
        string $projectRoot,
        private array $config,
        private string $configFile,
        private ?StorageAdapterInterface $storage,
        private ?DatabaseConnectionInterface $database,
        private ?BackupManager $backupManager,
        private ProductionCutoverConfig $policy,
        private ?int $now = null
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        $this->configFile = str_replace('\\', '/', trim($this->configFile));
        if ($this->projectRoot === '' || is_link($this->projectRoot) || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Production cutover project root is unavailable.');
        }
        $canonicalProject = realpath($this->projectRoot);
        if (!is_string($canonicalProject) || !hash_equals($this->projectRoot, $canonicalProject)) {
            throw new InvalidArgumentException('Production cutover project root is not canonical.');
        }
        if ($this->configFile === '' || is_link($this->configFile) || !is_file($this->configFile)) {
            throw new InvalidArgumentException('Production cutover private config is unavailable.');
        }
        $canonicalConfig = realpath($this->configFile);
        if (!is_string($canonicalConfig) || !hash_equals($this->configFile, $canonicalConfig)) {
            throw new InvalidArgumentException('Production cutover private config is not canonical.');
        }
        clearstatcache(true, $this->configFile);
        $configMode = fileperms($this->configFile);
        if (!is_int($configMode) || ($configMode & 0777) !== 0600) {
            throw new RuntimeException('Production cutover private config must have mode 0600.');
        }

        $this->privateDir = rtrim(str_replace('\\', '/', dirname($this->configFile)), '/');
        if ($this->privateDir === '' || $this->isInside($this->privateDir, $this->projectRoot)) {
            throw new RuntimeException('Production cutover private directory is unavailable or unsafe.');
        }
        clearstatcache(true, $this->privateDir);
        $privateMode = fileperms($this->privateDir);
        if (!is_int($privateMode) || ($privateMode & 0022) !== 0) {
            throw new RuntimeException('Production cutover private directory is group/world writable.');
        }
        $this->runtimeFile = $this->privateDir . '/runtime.php';
        $this->runtimeBackupFile = $this->privateDir . '/production-cutover.runtime.backup';
        $this->stateFile = $this->privateDir . '/production-cutover.json';

        $dataDir = rtrim(str_replace('\\', '/', trim((string)($this->config['data_dir'] ?? ''))), '/');
        if ($dataDir === '' || is_link($dataDir) || !is_dir($dataDir)) {
            throw new RuntimeException('Production JSON data directory is unavailable.');
        }
        $canonicalData = realpath($dataDir);
        if (!is_string($canonicalData) || !hash_equals($dataDir, $canonicalData)) {
            throw new RuntimeException('Production JSON data directory is not canonical.');
        }
        $this->writeBlockFile = $dataDir . '/.cutover-write-block';
    }
}
