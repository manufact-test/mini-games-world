<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$dockerfile = file_get_contents($projectRoot . '/ops/ci/Dockerfile.portable-focused');
$wrapper = file_get_contents($projectRoot . '/ops/ci/run-portable-focused-suite-container.sh');
if (!is_string($dockerfile) || !is_string($wrapper)) {
    throw new RuntimeException('Containerized portable CI sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($dockerfile, 'ARG PHP_IMAGE=php:8.3-cli-bookworm')
        && str_contains($dockerfile, 'FROM ${PHP_IMAGE}')
        && str_contains($dockerfile, 'docker-php-ext-install -j"$(nproc)" mbstring pdo_sqlite'),
    'Container image must use PHP 8.3 and install exact test extensions'
);
$assertTrue(
    str_contains($dockerfile, '--uid 10001')
        && str_contains($dockerfile, 'USER 10001:10001')
        && str_contains($dockerfile, 'WORKDIR /workspace')
        && str_contains($dockerfile, 'ENTRYPOINT ["bash", "ops/ci/run-portable-focused-suite.sh"]'),
    'Container image must use a fixed non-root user and exact portable entrypoint'
);
$assertTrue(
    !preg_match('/^\s*(COPY|ADD)\s+/mi', $dockerfile)
        && !preg_match('/^\s*EXPOSE\s+/mi', $dockerfile)
        && !str_contains($dockerfile, 'curl ')
        && !str_contains($dockerfile, 'wget ')
        && !str_contains($dockerfile, 'sudo '),
    'Container image must not embed repository content or remote bootstrap scripts'
);
$assertTrue(
    str_starts_with($wrapper, "#!/usr/bin/env bash\n")
        && str_contains($wrapper, 'set -euo pipefail')
        && str_contains($wrapper, 'tracked checkout changes are present'),
    'Container wrapper must use strict Bash and require a clean checkout'
);
$buildStart = strpos($wrapper, 'docker build \\');
$stdinContext = strpos($wrapper, '- < "$DOCKERFILE"');
$hostIdentity = strpos($wrapper, 'HOST_UID="$(id -u)"');
$assertTrue(
    $buildStart !== false
        && $stdinContext !== false
        && $hostIdentity !== false
        && $buildStart < $stdinContext
        && $stdinContext < $hostIdentity
        && !str_contains($wrapper, '--file "$DOCKERFILE"')
        && !str_contains($wrapper, 'docker buildx'),
    'Container image build must use Dockerfile stdin and zero repository context'
);
$assertTrue(
    str_contains($wrapper, '--network=none')
        && str_contains($wrapper, '--read-only')
        && str_contains($wrapper, '--cap-drop=ALL')
        && str_contains($wrapper, '--security-opt=no-new-privileges')
        && str_contains($wrapper, '--pids-limit=256')
        && str_contains($wrapper, '--memory=1g')
        && str_contains($wrapper, '--cpus=2'),
    'Container runtime must disable network and apply bounded sandbox controls'
);
$assertTrue(
    str_contains($wrapper, '--user "$HOST_UID:$HOST_GID"')
        && str_contains($wrapper, 'dst=/workspace,readonly')
        && str_contains($wrapper, 'dst=/artifacts')
        && str_contains($wrapper, '--tmpfs /tmp:rw,noexec,nosuid,nodev,size=268435456'),
    'Container runtime must use host identity, read-only checkout and isolated temp/artifacts'
);
$assertTrue(
    str_contains($wrapper, 'GIT_CONFIG_KEY_0=safe.directory')
        && str_contains($wrapper, 'GIT_CONFIG_VALUE_0=/workspace')
        && str_contains($wrapper, 'MGW_CI_OUTPUT_DIR=/artifacts')
        && str_contains($wrapper, 'MGW_CI_TIMEOUT_SECONDS="$TIMEOUT_SECONDS"'),
    'Container runtime must bind Git safety and portable CI output/timeout configuration'
);
$assertTrue(
    str_contains($wrapper, 'container artifacts must stay outside the repository checkout')
        && str_contains($wrapper, 'container artifacts must not be stored inside public_html')
        && str_contains($wrapper, 'MGW_CI_CONTAINER_OUTPUT_DIR must not be a symbolic link'),
    'Container wrapper must protect host artifact paths'
);
$assertTrue(
    !str_contains($wrapper, '--privileged')
        && !str_contains($wrapper, '--network=host')
        && !str_contains($wrapper, '/var/run/docker.sock')
        && !str_contains($wrapper, 'secrets')
        && !str_contains($wrapper, 'DATABASE_URL')
        && !str_contains($wrapper, 'DB_PASSWORD')
        && !str_contains($wrapper, 'public_html:/workspace'),
    'Container wrapper must not expose host control sockets, secrets, DB or production paths'
);

fwrite(STDOUT, "RuntimePrimaryPortableCiContainerContractTest passed: {$assertions} assertions.\n");
