<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Security\SecurityUtils;

/**
 * Verification protocols and deterministic callable adapters
 * (port of dag/verification.py CallableVerifier).
 *
 * PORTING-DIFF: Python's async wait_for timeout maps to
 * AsyncRuntime::waitFor in fiber contexts; sync callables run inline.
 */
final class CallableVerifier
{
    public function __construct(
        public readonly string $key,
        public readonly \Closure $function,
        public readonly string $version = '1',
        public readonly AssuranceLevel $assuranceLevel = AssuranceLevel::EXECUTABLE_CHECKS,
        public readonly ?float $timeout = null,
        public string $configDigest = '',
        ?array $config = null,
    ) {
        if (trim($this->key) === '') {
            throw new \InvalidArgumentException('verifier key must not be empty');
        }
        if ($this->timeout !== null && $this->timeout <= 0) {
            throw new \InvalidArgumentException('verifier timeout must be positive');
        }
        $this->configDigest = SecurityUtils::canonicalDigest($config ?? []);
    }

    public function verify(VerificationRequest $request): VerificationReport
    {
        try {
            $raw = ($this->function)($request);
            return $this->normalize($raw, $request);
        } catch (\Throwable $error) {
            return $this->report(
                $request,
                VerificationVerdict::ERROR,
                diagnostics: ['verifier failed: ' . $error::class],
            );
        }
    }

    private function normalize(mixed $raw, VerificationRequest $request): VerificationReport
    {
        if ($raw instanceof VerificationReport) {
            if ($raw->inputSnapshotHash !== $request->inputSnapshotHash) {
                return $this->report(
                    $request,
                    VerificationVerdict::ERROR,
                    diagnostics: ['verifier returned a report for a different input snapshot'],
                );
            }
            // Bind the report to the candidate bytes (V5-09): the input
            // snapshot hash covers the contract + dependencies + state
            // version, but NOT the candidate content. A report that does
            // not name the content hash of the candidate cannot authorise
            // its publication, so it is downgraded to an error instead of
            // being passed through.
            if (
                $request->candidate !== null
                && !in_array($request->candidate->contentHash, $raw->artifactHashes, true)
            ) {
                return $this->report(
                    $request,
                    VerificationVerdict::ERROR,
                    diagnostics: ['verifier returned a report that is not bound to the candidate artifact'],
                );
            }
            return $raw;
        }
        if ($raw === true) {
            return $this->report($request, VerificationVerdict::PASS);
        }
        if ($raw === false || $raw === null) {
            return $this->report($request, VerificationVerdict::FAIL, diagnostics: ['verification failed']);
        }
        if (is_array($raw)) {
            $verdict = VerificationVerdict::from((string) ($raw['verdict'] ?? 'error'));
            $assurance = AssuranceLevel::from((string) ($raw['assurance_level'] ?? $this->assuranceLevel->value));
            return $this->report(
                $request,
                $verdict,
                assuranceLevel: $assurance,
                diagnostics: array_map('strval', is_array($raw['diagnostics'] ?? null) ? $raw['diagnostics'] : []),
                evidenceRefs: array_map('strval', is_array($raw['evidence_refs'] ?? null) ? $raw['evidence_refs'] : []),
            );
        }
        return $this->report(
            $request,
            VerificationVerdict::ERROR,
            diagnostics: ['verifier returned an unsupported result type'],
        );
    }

    /**
     * @param list<string>|null $diagnostics
     * @param list<string>|null $evidenceRefs
     */
    private function report(
        VerificationRequest $request,
        VerificationVerdict $verdict,
        ?AssuranceLevel $assuranceLevel = null,
        ?array $diagnostics = null,
        ?array $evidenceRefs = null,
    ): VerificationReport {
        $hashes = $request->candidate !== null ? [$request->candidate->contentHash] : [];
        return new VerificationReport(
            kind: $request->kind,
            verdict: $verdict,
            assuranceLevel: $assuranceLevel ?? $this->assuranceLevel,
            verifierKey: $this->key,
            verifierVersion: $this->version,
            configDigest: $this->configDigest,
            inputSnapshotHash: $request->inputSnapshotHash,
            artifactHashes: $hashes,
            evidenceRefs: $evidenceRefs ?? [],
            diagnostics: $diagnostics ?? [],
        );
    }
}
