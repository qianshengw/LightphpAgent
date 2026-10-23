<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Artifact manifest (dag/models.py ArtifactManifest).
 *
 * @param array<string, list<string>> $dependencyManifest
 * @param array<string, mixed> $metadata
 */
final class ArtifactManifest
{
    public function __construct(
        public string $runId,
        public string $taskId,
        public string $attemptId,
        public string $tenantId,
        public string $projectId,
        public string $contentHash,
        public string $relativeBlobPath,
        public string $mediaType,
        public int $byteSize,
        public string $contractHash,
        public array $dependencyManifest = [],
        public string $artifactId = '',
        public ?string $verificationReportId = null,
        public string $state = 'staged',
        public string $createdAt = '',
        public int $schemaVersion = DAGConstants::SCHEMA_VERSION,
        public array $metadata = [],
    ) {
        if ($this->artifactId === '') {
            $this->artifactId = bin2hex(random_bytes(16));
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
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'attempt_id' => $this->attemptId,
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'content_hash' => $this->contentHash,
            'relative_blob_path' => $this->relativeBlobPath,
            'media_type' => $this->mediaType,
            'byte_size' => $this->byteSize,
            'contract_hash' => $this->contractHash,
            'dependency_manifest' => $this->dependencyManifest,
            'artifact_id' => $this->artifactId,
            'verification_report_id' => $this->verificationReportId,
            'state' => $this->state,
            'created_at' => $this->createdAt,
            'schema_version' => $this->schemaVersion,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            runId: (string) ($value['run_id'] ?? ''),
            taskId: (string) ($value['task_id'] ?? ''),
            attemptId: (string) ($value['attempt_id'] ?? ''),
            tenantId: (string) ($value['tenant_id'] ?? ''),
            projectId: (string) ($value['project_id'] ?? ''),
            contentHash: (string) ($value['content_hash'] ?? ''),
            relativeBlobPath: (string) ($value['relative_blob_path'] ?? ''),
            mediaType: (string) ($value['media_type'] ?? ''),
            byteSize: (int) ($value['byte_size'] ?? 0),
            contractHash: (string) ($value['contract_hash'] ?? ''),
            dependencyManifest: is_array($value['dependency_manifest'] ?? null) ? $value['dependency_manifest'] : [],
            artifactId: (string) ($value['artifact_id'] ?? '') ?: bin2hex(random_bytes(16)),
            verificationReportId: isset($value['verification_report_id']) ? (string) $value['verification_report_id'] : null,
            state: (string) ($value['state'] ?? 'staged'),
            createdAt: (string) ($value['created_at'] ?? '') ?: DAGConstants::utcNow(),
            schemaVersion: (int) ($value['schema_version'] ?? DAGConstants::SCHEMA_VERSION),
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
        );
    }
}
