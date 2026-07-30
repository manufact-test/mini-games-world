<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server;

final readonly class RuntimeConfig
{
    public function __construct(
        public string $environment,
        public string $dataDirectory,
        public string $build,
    ) {
        if ($this->environment !== 'staging') {
            throw new \InvalidArgumentException('The clean runtime server is staging-only.');
        }
        if (trim($this->dataDirectory) === '') {
            throw new \InvalidArgumentException('A staging data directory is required.');
        }
        if (trim($this->build) === '') {
            throw new \InvalidArgumentException('A clean runtime build identifier is required.');
        }
    }

    public static function fromEnvironment(): self
    {
        $configured = trim((string)(getenv('MGW_CLEAN_RUNTIME_DATA_DIR') ?: ''));
        $dataDirectory = $configured !== ''
            ? $configured
            : dirname(__DIR__, 4) . '/_private_mgw/runtime_staging';

        return new self(
            environment: 'staging',
            dataDirectory: rtrim($dataDirectory, '/\\'),
            build: 'mgw-clean-server-v1',
        );
    }
}
