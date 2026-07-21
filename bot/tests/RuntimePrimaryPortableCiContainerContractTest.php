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
        && str_contains($dockerfile, 'ARG MGW_DOCKERFILE_BLOB_SHA')
        && str_contains($dockerfile, '[[ "${MGW_DOCKERFILE_BLOB_SHA}" =~ ^[a-f0-9]{40}$ ]]')
        && str_contains($dockerfile, 'org.mgw.portable-ci.dockerfile-blob-sha="${MGW_DOCKERFILE_BLOB_SHA}"')
        && str_contains($dockerfile, 'v2-containerized-portable-focused-suite'),
    'Container image must be fingerprint-bound to the exact Dockerfile blob'
);
$assertTrue(
    str_contains($dockerfile, 'docker-php-ext-install -j"$(nproc)" mbstring pdo_sqlite')
        && str_contains($dockerfile, '--uid 10001')
        && str_contains($dockerfile, 'USER 10001:10001')
        && str_contains($dockerfile, 'WORKDIR /workspace')
        && str_contains($dockerfile, 'ENTRYPOINT ["bash", "ops/ci/run-portable-focused-suite.sh"]'),
    'Container image must use PHP 8.3, required extensions, non-root user and exact entrypoint'
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
    str_starts_with($wrapper, "#!/usr/bin/env bash\nset -euo pipefail\numask 077\n")
        && str_contains($wrapper, 'git status --porcelain=v1 --untracked-files=all')
        && !str_contains($wrapper, '--untracked-files=no')
        && str_contains($wrapper, 'checkout changes are present.'),
    'Container wrapper must use strict private Bash and require a fully clean checkout'
);
$assertTrue(
    str_contains($wrapper, 'git ls-files --error-unmatch "$DOCKERFILE"')
        && str_contains($wrapper, 'DOCKERFILE_BLOB_SHA="$(git hash-object "$DOCKERFILE")"')
        && str_contains($wrapper, 'MGW_DOCKERFILE_BLOB_SHA=$DOCKERFILE_BLOB_SHA')
        && str_contains($wrapper, 'CURRENT_IMAGE_LABEL')
        && str_contains($wrapper, 'image is not bound to the current Dockerfile blob'),
    'Container wrapper must bind image reuse and rebuild to the tracked Dockerfile blob'
);
$buildStart = strpos($wrapper, 'docker build \\');
$stdinContext = strpos($wrapper, '- < "$DOCKERFILE"');
$imageId = strpos($wrapper, 'IMAGE_ID="$(docker image inspect');
$runStart = strpos($wrapper, 'exec docker run \\');
$assertTrue(
    $buildStart !== false
        && $stdinContext !== false
        && $imageId !== false
        && $runStart !== false
        && $buildStart < $stdinContext
        && $stdinContext < $imageId
        && $imageId < $runStart
        && str_contains($wrapper, '[[ "$IMAGE_ID" =~ ^sha256:[a-f0-9]{64}$ ]]')
        && str_ends_with(trim($wrapper), '"$IMAGE_ID"')
        && !str_contains($wrapper, '--file "$DOCKERFILE"')
        && !str_contains($wrapper, 'docker buildx'),
    'Container build must use zero context and runtime must execute the verified immutable image ID'
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
    str_contains($wrapper, '(( HOST_UID > 0 && HOST_GID > 0 ))')
        && str_contains($wrapper, 'must not run with root host identity')
        && str_contains($wrapper, '--user "$HOST_UID:$HOST_GID"')
        && str_contains($wrapper, 'dst=/workspace,readonly')
        && str_contains($wrapper, 'dst=/artifacts')
        && str_contains($wrapper, '--tmpfs /tmp:rw,noexec,nosuid,nodev,size=268435456'),
    'Container runtime must use a non-root host identity, read-only checkout and isolated storage'
);
$assertTrue(
    str_contains($wrapper, 'GIT_CONFIG_KEY_0=safe.directory')
        && str_contains($wrapper, 'GIT_CONFIG_VALUE_0=/workspace')
        && str_contains($wrapper, 'GIT_OPTIONAL_LOCKS=0')
        && str_contains($wrapper, 'MGW_CI_OUTPUT_DIR=/artifacts')
        && str_contains($wrapper, 'MGW_CI_TIMEOUT_SECONDS="$TIMEOUT_SECONDS"'),
    'Container runtime must bind read-only Git safety and portable output/timeout configuration'
);
$assertTrue(
    str_contains($wrapper, 'container artifacts must stay outside the repository checkout')
        && str_contains($wrapper, 'container artifacts must not be stored inside public_html')
        && str_contains($wrapper, 'MGW_CI_CONTAINER_OUTPUT_DIR must not be a symbolic link')
        && str_contains($wrapper, 'container artifact directory must be empty before the run')
        && str_contains($wrapper, 'find "$OUTPUT_DIR" -mindepth 1 -maxdepth 1')
        && str_contains($wrapper, 'unsupported delimiter characters'),
    'Container wrapper must protect a fresh exact host artifact bundle and mount paths'
);
$assertTrue(
    !str_contains($wrapper, '--privileged')
        && !str_contains($wrapper, '--network=host')
        && !str_contains($wrapper, '/var/run/docker.sock')
        && !str_contains($wrapper, 'secrets.')
        && !str_contains($wrapper, 'DATABASE_URL')
        && !str_contains($wrapper, 'DB_PASSWORD')
        && !str_contains($wrapper, 'public_html:/workspace'),
    'Container wrapper must not expose host control sockets, secrets, DB or production paths'
);

fwrite(STDOUT, "RuntimePrimaryPortableCiContainerContractTest passed: {$assertions} assertions.\n");
