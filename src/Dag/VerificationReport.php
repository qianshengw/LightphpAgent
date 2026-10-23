<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Verification report (dag/models.py VerificationReport).
 *
 * @param list<string> $artifactHashes
 * @param list<string> $evidenceRefs
 * @param list<string> $diagnostics
 */
final class VerificationReport
{
    public function __construct(
        public readonly string $kind,
        public VerificationVerdict $verdict,
        public AssuranceLevel $assuranceLevel,
        public readonly string $verifierKey,
        public readonly string $verifierVersion,
        public readonly string $configDigest,
        public readonly string $inputSnapshotHash,
        public array $artifactHashes = [],
        public array $evidenceRefs = [],
        public array $diagnostics = [],
        public string $reportId = '',
        public string $createdAt = '',
        public int $schemaVersion = DAGConstants::SCHEMA_VERSION,
    ) {
        if ($this->reportId === '') {
            $this->reportId = bin2hex(random_bytes(16));
        }
        if ($this->createdAt === '') {
            $this->createdAt = DAGConstants::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'kind' => $this->kind,
            'verdict' => $this->verdict->value,
            'assurance_level' => $this->assuranceLevel->value,
            'verifier_key' => $this->verifierKey,
            'verifier_version' => $this->verifierVersion,
            'config_digest' => $this->configDigest,
            'input_snapshot_hash' => $this->inputSnapshotHash,
            'artifact_hashes' => $this->artifactHashes,
            'evidence_refs' => $this->evidenceRefs,
            'diagnostics' => $this->diagnostics,
            'report_id' => $this->reportId,
            'created_at' => $this->createdAt,
            'schema_version' => $this->schemaVersion,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            kind: (string) ($value['kind'] ?? ''),
            verdict: VerificationVerdict::from((string) ($value['verdict'] ?? 'pass')),
            assuranceLevel: AssuranceLevel::from((string) ($value['assurance_level'] ?? 'executable_checks')),
            verifierKey: (string) ($value['verifier_key'] ?? ''),
            verifierVersion: (string) ($value['verifier_version'] ?? ''),
            configDigest: (string) ($value['config_digest'] ?? ''),
            inputSnapshotHash: (string) ($value['input_snapshot_hash'] ?? ''),
            artifactHashes: is_array($value['artifact_hashes'] ?? null) ? $value['artifact_hashes'] : [],
            evidenceRefs: is_array($value['evidence_refs'] ?? null) ? $value['evidence_refs'] : [],
            diagnostics: is_array($value['diagnostics'] ?? null) ? $value['diagnostics'] : [],
            reportId: (string) ($value['report_id'] ?? '') ?: bin2hex(random_bytes(16)),
            createdAt: (string) ($value['created_at'] ?? '') ?: DAGConstants::utcNow(),
            schemaVersion: (int) ($value['schema_version'] ?? DAGConstants::SCHEMA_VERSION),
        );
    }
}
