<?php

declare(strict_types=1);

namespace LightAgent\Knowledge;

/**
 * The retrieval contract a knowledge-base backend has to satisfy
 * (port of knowledge.py RetrievalProvider Protocol).
 *
 * The Python original defines this Protocol, so a project can plug in a
 * vector database, a hosted search service or a hybrid retriever without
 * touching the framework. The PHP port shipped the built-in SQLite FTS5
 * provider but NOT the contract itself, which left implementers without a
 * signature to code against — the only thing they could rely on was
 * duck typing. This interface restores the seam.
 *
 * The method names and the capability names (`rag.ingest`, `rag.search`, …)
 * mirror the original exactly, so a provider written against either project
 * is portable.
 */
interface RetrievalProvider
{
    /**
     * Ingest (or replace) a document and return its document id.
     *
     * @param RetrievalDocument|array<string, mixed> $document
     */
    public function ingest(RetrievalDocument|array $document): string;

    /**
     * Remove a document. Returns false when it did not exist or when the
     * owner/tenant boundary refused the deletion.
     */
    public function remove(string $documentId, ?string $ownerId = null, ?string $tenantId = null): bool;

    /**
     * List the indexed documents, optionally restricted to one owner/tenant.
     *
     * @return list<RetrievalDocument>
     */
    public function listDocuments(?string $ownerId = null, ?string $tenantId = null): array;

    /**
     * Search the indexed chunks.
     *
     * @return list<RetrievalResult>
     */
    public function search(
        string $query,
        int $limit = 5,
        ?string $scope = null,
        ?string $ownerId = null,
        ?string $tenantId = null,
    ): array;

    /**
     * Read one chunk by its citation id, or null when it does not exist or
     * belongs to another owner/tenant.
     */
    public function readChunk(string $chunkId, ?string $ownerId = null, ?string $tenantId = null): ?RetrievalResult;

    /**
     * Rebuild the search index from the stored chunks.
     */
    public function reindex(): void;
}
