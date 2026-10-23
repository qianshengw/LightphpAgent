<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Verification input bundle (dag/models.py VerificationRequest, frozen).
 *
 * @param array<string, list<ArtifactManifest>> $dependencyArtifacts
 */
final class VerificationRequest
{
    public function __construct(
        public readonly string $kind,
        public readonly DAGRun $run,
        public readonly DAGTask $task,
        public readonly TaskAttempt $attempt,
        public readonly string $inputSnapshotHash,
        public readonly ?ArtifactManifest $candidate = null,
        public readonly ?string $candidateContent = null,
        public readonly ?DecompositionProposal $decomposition = null,
        public array $dependencyArtifacts = [],
        public readonly ?\LightAgent\Security\SecurityContext $securityContext = null,
    ) {
    }
}

/**
 * Per-attempt execution context handed to workers (dag/models.py TaskContext, frozen).
 *
 * @param array<string, list<ArtifactManifest>> $dependencyArtifacts
 */
final class TaskContext
{
    public function __construct(
        public readonly DAGRun $run,
        public readonly DAGTask $task,
        public readonly TaskAttempt $attempt,
        public array $dependencyArtifacts = [],
        public readonly ?\LightAgent\Security\SecurityContext $securityContext = null,
        public readonly ?\LightAgent\Async\CancellationToken $cancellationToken = null,
        public readonly ?string $lastDiagnostic = null,
    ) {
    }
}
