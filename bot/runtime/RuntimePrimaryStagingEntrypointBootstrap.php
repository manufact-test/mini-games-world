<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require_once __DIR__ . '/RuntimePrimaryPrivateConfigGuard.php';
require_once __DIR__ . '/RuntimePrimaryRepositoryCommitResolver.php';
require_once __DIR__ . '/RuntimePrimaryEntrypointEvidence.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceVerifier.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV2Verifier.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV2Gate.php';
require_once __DIR__ . '/RuntimePrimaryStagingSelectorEvidence.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV3Verifier.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV3Gate.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceGate.php';
require_once __DIR__ . '/RuntimePrimaryJsonEvidence.php';
require_once __DIR__ . '/RuntimePrimaryStagingSchemaInspector.php';
require_once __DIR__ . '/RuntimePrimaryStagingActivationConfig.php';
require_once __DIR__ . '/RuntimePrimaryStagingActivationEvidenceLoader.php';
require_once __DIR__ . '/RuntimePrimaryProjectionBootstrap.php';
require_once __DIR__ . '/RuntimePrimaryStagingActivationGuard.php';
require_once __DIR__ . '/RuntimePrimaryStagingStorageResolverConfig.php';
require_once __DIR__ . '/RuntimePrimaryStagingStorageResolution.php';
require_once __DIR__ . '/RuntimePrimaryStagingStorageResolver.php';

require_once __DIR__ . '/RuntimePrimaryProjectionWorkerInterface.php';
require_once __DIR__ . '/RuntimePrimaryProjectionAuditorInterface.php';
require_once __DIR__ . '/RuntimePrimaryProjectionWorkerAdapter.php';
require_once __DIR__ . '/RuntimePrimaryProjectionAuditorAdapter.php';
require_once __DIR__ . '/RuntimePrimaryStagingRequestSessionConfig.php';
require_once __DIR__ . '/RuntimePrimaryStagingRequestFinalizer.php';
require_once __DIR__ . '/RuntimePrimaryStagingRequestSessionReadiness.php';

require_once __DIR__ . '/RuntimePrimaryStagingRequestLifecycleEvidence.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV4Verifier.php';
require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV4Gate.php';
require_once __DIR__ . '/RuntimePrimaryEntrypointStorageContext.php';
require_once __DIR__ . '/RuntimePrimaryStagingApiRequestFinalizationHook.php';
require_once __DIR__ . '/RuntimePrimaryStagingApiSessionCoordinator.php';
require_once __DIR__ . '/RuntimePrimaryStagingEntrypointStorageSelector.php';
