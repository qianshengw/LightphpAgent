<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Security\SecurityContext;

/**
 * Content-addressed local artifact storage for verified DAG outputs
 * (port of dag/artifacts.py LocalArtifactStore).
 */
final class LocalArtifactStore
{
    /** Normalized (realpath) root used for all containment checks. */
    private string $normalizedRoot;

    /**
     * @param list<string> $reservedFiles files that are never orphans. The
     *        task graph database lives in the same root as the artifacts
     *        (`<artifactRoot>/graph.sqlite3` plus its WAL/SHM sidecars),
     *        so the cleanup must not delete it (M-24).
     */
    public function __construct(
        public readonly string $root,
        public readonly int $maxArtifactBytes = 10 * 1024 * 1024,
        public readonly array $reservedFiles = [],
    ) {
        if ($this->maxArtifactBytes <= 0) {
            throw new \InvalidArgumentException('max_artifact_bytes must be positive');
        }
        if (!is_dir($this->root)) {
            mkdir($this->root, 0777, true);
        }
        $this->normalizedRoot = str_replace('\\', '/', (string) realpath($this->root));
    }

    /**
     * Write immutable blobs first; SQLite controls staged/published authority.
     *
     * @param array<string, list<string>>|null $dependencyManifest
     */
    public function stage(
        string|bytes $content,
        DAGRun $run,
        DAGTask $task,
        TaskAttempt $attempt,
        string $mediaType = 'text/plain',
        ?array $dependencyManifest = null,
    ): ArtifactManifest {
        $payload = is_string($content) ? $content : (string) $content;
        if (strlen($payload) > $this->maxArtifactBytes) {
            throw new DAGError('BUDGET-EXHAUSTED', 'artifact exceeds max_artifact_bytes');
        }
        $digest = hash('sha256', $payload);
        $relative = "{$run->tenantId}/{$run->projectId}/" . substr($digest, 0, 2) . "/{$digest}";
        $destination = $this->resolve($relative, createParents: true);
        $destDir = dirname($destination);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        if (is_file($destination)) {
            if ($this->digestFile($destination) !== $digest) {
                throw new DAGError('ARTIFACT-INTEGRITY', 'existing artifact bytes do not match the content hash');
            }
        } else {
            $temporary = $destination . '.tmp-' . bin2hex(random_bytes(8));
            // The write result must be checked: an unchecked failure
            // produces a "ghost artifact" - a manifest for bytes that were
            // never stored (V5-26).
            $written = @file_put_contents($temporary, $payload, LOCK_EX);
            if ($written === false || $written !== strlen($payload)) {
                @unlink($temporary);
                throw new DAGError('ARTIFACT-INTEGRITY', 'failed to write the artifact blob');
            }
            if (!@rename($temporary, $destination)) {
                @unlink($temporary);
                throw new DAGError('ARTIFACT-INTEGRITY', 'failed to publish the artifact blob');
            }
        }
        return new ArtifactManifest(
            runId: $run->runId,
            taskId: $task->spec->taskId,
            attemptId: $attempt->attemptId,
            tenantId: $run->tenantId,
            projectId: $run->projectId,
            contentHash: $digest,
            relativeBlobPath: str_replace(DIRECTORY_SEPARATOR, '/', $relative),
            mediaType: $mediaType,
            byteSize: strlen($payload),
            contractHash: $task->spec->contractHash,
            dependencyManifest: $dependencyManifest ?? [],
        );
    }

    /**
     * @param array<string, mixed> $context security-context-like array (tenant_id/project_id)
     */
    public function read(
        ArtifactManifest $manifest,
        array $context,
        bool $requirePublished = true,
    ): string {
        if (($context['tenant_id'] ?? null) !== $manifest->tenantId || ($context['project_id'] ?? null) !== $manifest->projectId) {
            throw new \RuntimeException('artifact is outside the tenant/project security context');
        }
        if ($requirePublished && $manifest->state !== 'published') {
            throw new \RuntimeException('staged artifacts are not readable as trusted results');
        }
        $path = $this->resolve($manifest->relativeBlobPath);
        if (!is_file($path)) {
            throw new DAGError('ARTIFACT-INTEGRITY', 'artifact blob is missing or not a regular file');
        }
        $payload = (string) file_get_contents($path);
        if (strlen($payload) !== $manifest->byteSize || hash('sha256', $payload) !== $manifest->contentHash) {
            throw new DAGError('ARTIFACT-INTEGRITY', 'artifact content failed integrity verification');
        }
        return $payload;
    }

    public function checkIntegrity(ArtifactManifest $manifest): bool
    {
        try {
            $path = $this->resolve($manifest->relativeBlobPath);
            return is_file($path)
                && (int) filesize($path) === $manifest->byteSize
                && $this->digestFile($path) === $manifest->contentHash;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * List (and optionally delete) the blobs that no manifest references.
     *
     * Only the tenant/project blob subtrees hold artifacts: every other
     * file belongs to the runtime. In particular the task graph database
     * (`<root>/graph.sqlite3` plus its `-wal`/`-shm` sidecars) shares the
     * artifact root and must never be deleted by the cleanup (M-24).
     *
     * @param list<ArtifactManifest> $manifests
     * @return list<string>
     */
    public function removeOrphans(array $manifests, bool $dryRun = true): array
    {
        $referenced = [];
        foreach ($manifests as $manifest) {
            $referenced[$manifest->relativeBlobPath] = true;
        }
        $orphans = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->root) + 1));
            if (isset($referenced[$relative])) {
                continue;
            }
            if ($this->isReserved($relative, str_replace('\\', '/', $file->getPathname()))) {
                continue;
            }
            if (!$this->isBlobPath($relative)) {
                continue;
            }
            $orphans[] = $relative;
            if (!$dryRun) {
                unlink($file->getPathname());
            }
        }
        sort($orphans);
        return $orphans;
    }

    /**
     * Whether a store relative path is one of the reserved runtime files.
     *
     * A reservation matches the file itself and its WAL/SHM/journal
     * sidecars.
     */
    private function isReserved(string $relative, string $absolutePath): bool
    {
        foreach ($this->reservedFiles as $reserved) {
            $normalized = str_replace('\\', '/', $reserved);
            $absolute = str_replace('\\', '/', (string) realpath($reserved));
            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                if ($normalized . $suffix === $relative) {
                    return true;
                }
                if ($absolute !== '' && $absolute . $suffix === $absolutePath) {
                    return true;
                }
            }
            if ($normalized === $relative) {
                return true;
            }
            if ($absolute !== '' && $absolute === $absolutePath) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a store relative path points into a tenant/project blob
     * subtree (`<tenant>/<project>/<shard>/<hash>`).
     */
    private function isBlobPath(string $relative): bool
    {
        return substr_count($relative, '/') >= 2;
    }

    private function resolve(string $relative, bool $createParents = false): string
    {
        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            throw new DAGError('ARTIFACT-INTEGRITY', 'artifact path must stay within the store root');
        }
        $normalized = [];
        foreach (explode('/', str_replace('\\', '/', $relative)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new DAGError('ARTIFACT-INTEGRITY', 'artifact path must stay within the store root');
            }
            $normalized[] = $part;
        }
        // Lexical containment check (works for not-yet-created paths).
        $root = rtrim($this->normalizedRoot, '/');
        $path = $root . '/' . implode('/', $normalized);
        // When the parent exists, verify physically; otherwise lexical is enough
        // because $normalized cannot contain '..' segments.
        $parent = dirname($path);
        if (is_dir($parent)) {
            $real = str_replace('\\', '/', (string) realpath($parent));
            if ($real === '' || !str_starts_with(strtolower($real), strtolower($root))) {
                throw new DAGError('ARTIFACT-INTEGRITY', 'artifact path escaped the store root');
            }
            $path = $real . '/' . basename($path);
        } elseif ($createParents) {
            if (!mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new DAGError('ARTIFACT-INTEGRITY', 'failed to create artifact parent directory');
            }
            $real = str_replace('\\', '/', (string) realpath($parent));
            if ($real === '' || !str_starts_with(strtolower($real), strtolower($root))) {
                throw new DAGError('ARTIFACT-INTEGRITY', 'artifact path escaped the store root');
            }
            $path = $real . '/' . basename($path);
        }
        return $path;
    }

    private function digestFile(string $path): string
    {
        return hash('sha256', (string) file_get_contents($path));
    }
}
