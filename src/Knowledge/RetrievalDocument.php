<?php

declare(strict_types=1);

namespace LightAgent\Knowledge;

/**
 * A document ingested into a retrieval provider (knowledge.py RetrievalDocument).
 */
final class RetrievalDocument
{
    public string $documentId;
    public string $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly ?string $title = null,
        public readonly ?string $source = null,
        ?string $documentId = null,
        public readonly string $scope = 'workspace',
        public readonly ?string $ownerId = null,
        public readonly ?string $tenantId = null,
        ?string $createdAt = null,
        public array $metadata = [],
    ) {
        $this->documentId = $documentId ?? bin2hex(random_bytes(16));
        $this->createdAt = $createdAt ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    /**
     * A copy that carries a different owner/tenant.
     *
     * The identity of a document must come from the CALLER, not from the
     * payload: on the capability path the payload is model-supplied, so
     * letting it name the owner would let any principal file a document
     * under somebody else's identity.
     */
    public function withIdentity(?string $ownerId, ?string $tenantId): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            source: $this->source,
            documentId: $this->documentId,
            scope: $this->scope,
            ownerId: $ownerId,
            tenantId: $tenantId,
            createdAt: $this->createdAt,
            metadata: $this->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'content' => $this->content,
            'title' => $this->title,
            'source' => $this->source,
            'document_id' => $this->documentId,
            'scope' => $this->scope,
            'owner_id' => $this->ownerId,
            'tenant_id' => $this->tenantId,
            'created_at' => $this->createdAt,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            content: (string) ($value['content'] ?? ''),
            title: isset($value['title']) ? (string) $value['title'] : null,
            source: isset($value['source']) ? (string) $value['source'] : null,
            documentId: isset($value['document_id']) ? (string) $value['document_id'] : null,
            scope: (string) ($value['scope'] ?? 'workspace'),
            ownerId: isset($value['owner_id']) ? (string) $value['owner_id'] : null,
            tenantId: isset($value['tenant_id']) ? (string) $value['tenant_id'] : null,
            createdAt: isset($value['created_at']) ? (string) $value['created_at'] : null,
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
        );
    }
}

/**
 * One retrieval hit (knowledge.py RetrievalResult).
 */
final class RetrievalResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $chunkId,
        public readonly string $content,
        public readonly string $citationId,
        public readonly ?string $title = null,
        public readonly ?string $source = null,
        public readonly int $position = 0,
        public readonly ?float $score = null,
        public readonly string $scope = 'workspace',
        public readonly ?string $ownerId = null,
        public readonly ?string $tenantId = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'document_id' => $this->documentId,
            'chunk_id' => $this->chunkId,
            'content' => $this->content,
            'citation_id' => $this->citationId,
            'title' => $this->title,
            'source' => $this->source,
            'position' => $this->position,
            'score' => $this->score,
            'scope' => $this->scope,
            'owner_id' => $this->ownerId,
            'tenant_id' => $this->tenantId,
            'metadata' => $this->metadata,
        ];
    }
}
