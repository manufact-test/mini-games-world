<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductionCutoverRunTrait.php';
require_once __DIR__ . '/ProductionCutoverPerformTrait.php';
require_once __DIR__ . '/ProductionCutoverControlTrait.php';
require_once __DIR__ . '/ProductionCutoverNoopTrait.php';
require_once __DIR__ . '/ProductionCutoverDataTrait.php';
require_once __DIR__ . '/ProductionCutoverRuntimeTrait.php';
require_once __DIR__ . '/ProductionCutoverReportTrait.php';

final class ProductionCutoverRunner
{
    use ProductionCutoverRunTrait;
    use ProductionCutoverPerformTrait;
    use ProductionCutoverControlTrait;
    use ProductionCutoverNoopTrait;
    use ProductionCutoverDataTrait;
    use ProductionCutoverRuntimeTrait;
    use ProductionCutoverReportTrait;

    private const BUILD = 'v103-mvp14-production-cutover';
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
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private BackupManager $backupManager,
        private ProductionCutoverConfig $policy,
        private ?int $now = null
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($projectRoot)), '/');
        $this->configFile = str_replace('\\', '/', trim($this->configFile));
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Production cutover project root is unavailable.');
        }
        if ($this->configFile === '' || !is_file($this->configFile)) {
            throw new InvalidArgumentException('Production cutover private config is unavailable.');
        }

        $this->privateDir = rtrim(str_replace('\\', '/', dirname($this->configFile)), '/');
        if ($this->privateDir === '' || $this->isInside($this->privateDir, $this->projectRoot)) {
            throw new RuntimeException('Production cutover private directory is unavailable or unsafe.');
        }
        $this->runtimeFile = $this->privateDir . '/runtime.php';
        $this->runtimeBackupFile = $this->privateDir . '/production-cutover.runtime.backup';
        $this->stateFile = $this->privateDir . '/production-cutover.json';

        $dataDir = rtrim(str_replace('\\', '/', trim((string)($this->config['data_dir'] ?? ''))), '/');
        if ($dataDir === '' || !is_dir($dataDir)) {
            throw new RuntimeException('Production JSON data directory is unavailable.');
        }
        $this->writeBlockFile = $dataDir . '/.cutover-write-block';
    }
}
