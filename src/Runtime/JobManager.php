<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Support\Timestamp;
use LightAgent\Async\AsyncRuntime;
use LightAgent\Async\CancellationToken;
use LightAgent\Session\Session;

/**
 * Manage cancellable background jobs and persist their state
 * (port of runtime.py JobManager).
 *
 * PORTING-DIFF: Python wraps operations in asyncio Tasks; PHP runs the
 * operation inline inside a fiber started via AsyncRuntime::async(), which
 * preserves cancellation checks and lease semantics at the same boundaries.
 */
final class JobManager
{
    /** @var array<string, JobRecord> */
    private array $records = [];

    /** @var array<string, \Amp\Future> */
    private array $futures = [];

    /** @var array<string, CancellationToken> */
    private array $cancellations = [];

    /** @var array<string, string> */
    private array $idempotencyKeys = [];

    public readonly string $managerId;

    public function __construct(
        public readonly ?\Closure $eventSink = null,
        public readonly ?AgentInbox $inbox = null,
    ) {
        $this->managerId = bin2hex(random_bytes(16));
    }

    /**
     * @param callable|mixed $operation callable() or a plain value
     * @param array<string, mixed>|null $metadata
     */
    public function start(
        string $name,
        mixed $operation,
        ?string $ownerAgentId = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
        ?CancellationToken $cancellationToken = null,
        ?float $leaseSeconds = null,
    ): JobRecord {
        if ($leaseSeconds !== null && $leaseSeconds <= 0) {
            throw new \InvalidArgumentException('lease_seconds must be positive');
        }
        if ($idempotencyKey !== null && trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('idempotency_key must not be empty');
        }
        if ($idempotencyKey !== null && isset($this->idempotencyKeys[$idempotencyKey])) {
            return $this->records[$this->idempotencyKeys[$idempotencyKey]];
        }
        $token = $cancellationToken !== null ? $cancellationToken->child() : new CancellationToken();
        $record = new JobRecord(
            name: $name,
            ownerAgentId: $ownerAgentId,
            metadata: $metadata ?? [],
            idempotencyKey: $idempotencyKey,
            cancellationTokenId: $token->tokenId,
            leaseOwner: $leaseSeconds !== null ? $this->managerId : null,
            leaseEpoch: $leaseSeconds !== null ? 1 : 0,
            leaseExpiresAt: $leaseSeconds !== null
                ? Timestamp::inSeconds($leaseSeconds)
                : null,
        );
        $this->records[$record->jobId] = $record;
        $this->cancellations[$record->jobId] = $token;
        if ($idempotencyKey !== null) {
            $this->idempotencyKeys[$idempotencyKey] = $record->jobId;
        }
        $this->emit('job.created', $record);
        // The lease epoch is captured EAGERLY: it is the fencing token of
        // this execution (M-35). A later resume() bumps the epoch, and the
        // stale execution must not publish its result any more.
        $epoch = $record->leaseEpoch;
        $this->futures[$record->jobId] = AsyncRuntime::async(fn (): mixed => $this->execute($record->jobId, $operation, $epoch));
        return $record;
    }

    /**
     * Run the operation of a job and publish its result.
     *
     * @param int|null $claimedEpoch the lease epoch captured when the job
     *        was claimed. `resume()` bumps the epoch, so an execution whose
     *        epoch no longer matches the record belongs to a STALE claim
     *        and must not touch the record (M-35).
     */
    private function execute(string $jobId, mixed $operation, ?int $claimedEpoch = null): void
    {
        $record = $this->records[$jobId];
        $token = $this->cancellations[$jobId] ?? new CancellationToken();
        if ($this->isStaleEpoch($record, $claimedEpoch)) {
            // The record already belongs to a newer epoch - nothing to do.
            return;
        }
        $stale = false;
        try {
            if ($record->status === JobStatus::CANCELLED) {
                // cancel() finalized the record before the operation even
                // started: the operation must not run at all. The old code
                // overwrote the status with RUNNING here, so the job ran
                // anyway and the cancellation was reported TWICE (V5-43).
                return;
            }
            $record->status = JobStatus::RUNNING;
            $record->startedAt = \LightAgent\Session\SessionConstants::utcNow();
            $this->emit('job.started', $record);
            if ($token->isCancelled()) {
                throw new \LightAgent\Async\AsyncCancelledException($token->getReason() ?? 'cancelled');
            }
            if ($record->isLeaseExpired()) {
                $record->status = JobStatus::INTERRUPTED;
                $record->error = 'job lease expired before execution';
                $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
                $this->emit('job.interrupted', $record);
                return;
            }
            $value = is_callable($operation) ? $operation() : $operation;
            // Post-execution token check (A6): a cancel() arriving while the
            // operation ran must discard the result 鈥?the record below is
            // only written when the job was never cancelled.
            if ($token->isCancelled()) {
                throw new \LightAgent\Async\AsyncCancelledException($token->getReason() ?? 'cancelled');
            }
            if ($record->status === JobStatus::CANCELLED) {
                // cancel() already finalized this record; do not overwrite.
                return;
            }
            if ($this->isStaleEpoch($record, $claimedEpoch)) {
                // A resume() took the record over while the operation was
                // running: the result belongs to a stale epoch and must not
                // be published (M-35).
                $stale = true;
                return;
            }
            if ($record->isLeaseExpired()) {
                $record->status = JobStatus::INTERRUPTED;
                $record->error = 'job lease expired before result publication';
                $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
                $this->emit('job.interrupted', $record);
                return;
            }
            $record->result = $value;
            $record->status = JobStatus::SUCCESS;
            $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
            $this->emit('job.completed', $record);
        } catch (\LightAgent\Async\AsyncCancelledException $e) {
            if ($this->isStaleEpoch($record, $claimedEpoch)) {
                $stale = true;
                return;
            }
            // V5-43: cancel() already emitted `job.cancelled` and finalized
            // the record - emitting it a second time here produced TWO
            // events for ONE cancellation.
            $alreadyCancelled = $record->status === JobStatus::CANCELLED;
            $record->status = JobStatus::CANCELLED;
            $record->error = $token->getReason() ?? $e->getMessage();
            $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
            if (!$alreadyCancelled) {
                $this->emit('job.cancelled', $record);
            }
        } catch (\Throwable $error) {
            if ($this->isStaleEpoch($record, $claimedEpoch)) {
                $stale = true;
                return;
            }
            $record->status = JobStatus::FAILED;
            $record->error = $error::class . ': ' . $error->getMessage();
            $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
            $this->emit('job.failed', $record);
        } finally {
            if (!$stale) {
                if ($record->completedAt === null) {
                    $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
                }
                if ($this->inbox !== null) {
                    $this->inbox->enqueue(
                        InboxMessageType::CONTEXT,
                        ['job' => $record->toDict()],
                        correlationId: $record->jobId,
                        metadata: ['kind' => 'job_completion'],
                    );
                }
            }
        }
    }

    /**
     * True when the execution no longer owns the record (M-35).
     */
    private function isStaleEpoch(JobRecord $record, ?int $claimedEpoch): bool
    {
        return $claimedEpoch !== null && $record->leaseEpoch !== $claimedEpoch;
    }

    public function wait(string $jobId): JobRecord
    {
        if (!isset($this->records[$jobId])) {
            throw new \RuntimeException($jobId);
        }
        $future = $this->futures[$jobId] ?? null;
        if ($future !== null) {
            try {
                $future->await();
            } catch (\Throwable) {
                // job-level failure recorded on the record itself
            }
        }
        return $this->records[$jobId];
    }

    public function cancel(string $jobId, ?string $reason = null): bool
    {
        $future = $this->futures[$jobId] ?? null;
        $record = $this->records[$jobId] ?? null;
        $terminal = [JobStatus::SUCCESS, JobStatus::FAILED, JobStatus::CANCELLED, JobStatus::INTERRUPTED];
        if ($future === null || $record === null || in_array($record->status, $terminal, true)) {
            return false;
        }
        $token = $this->cancellations[$jobId] ?? null;
        if ($token !== null) {
            $token->cancel($reason ?? 'job cancellation requested');
        }
        // Cancel at the next cooperative boundary: mark the record now.
        $record->status = JobStatus::CANCELLED;
        $record->error = $reason ?? 'job cancellation requested';
        $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
        $this->emit('job.cancelled', $record);
        return true;
    }

    public function cancellationToken(string $jobId): CancellationToken
    {
        if (!isset($this->records[$jobId])) {
            throw new \RuntimeException($jobId);
        }
        $token = $this->cancellations[$jobId] ?? null;
        if ($token === null) {
            throw new \RuntimeException("job `{$jobId}` is not active");
        }
        return $token;
    }

    public function renewLease(
        string $jobId,
        float $leaseSeconds,
        ?string $owner = null,
        ?int $expectedEpoch = null,
    ): JobRecord {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('lease_seconds must be positive');
        }
        $record = $this->records[$jobId] ?? null;
        if ($record === null) {
            throw new \RuntimeException($jobId);
        }
        $resolvedOwner = $owner ?? $this->managerId;
        if ($record->leaseOwner !== $resolvedOwner) {
            throw new \RuntimeException('job lease belongs to another owner');
        }
        if ($expectedEpoch !== null && $record->leaseEpoch !== $expectedEpoch) {
            throw new \RuntimeException('job lease epoch is stale');
        }
        if (!in_array($record->status, [JobStatus::PENDING, JobStatus::RUNNING], true)) {
            throw new \RuntimeException('only active jobs can renew a lease');
        }
        // Millisecond precision: DateTimeInterface::ATOM truncates to whole
        // seconds, so a sub-second lease could be expired on creation (T8).
        $record->leaseExpiresAt = Timestamp::inSeconds($leaseSeconds);
        $this->emit('job.lease_renewed', $record);
        return $record;
    }

    public function resume(string $jobId, mixed $operation, float $leaseSeconds = 60.0): JobRecord
    {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('lease_seconds must be positive');
        }
        $record = $this->records[$jobId] ?? null;
        if ($record === null) {
            throw new \RuntimeException($jobId);
        }
        if ($record->status !== JobStatus::INTERRUPTED) {
            throw new \RuntimeException('only interrupted jobs can be resumed');
        }
        $token = new CancellationToken();
        $record->status = JobStatus::PENDING;
        $record->startedAt = null;
        $record->completedAt = null;
        $record->error = null;
        $record->leaseOwner = $this->managerId;
        $record->leaseEpoch++;
        // Millisecond precision: DateTimeInterface::ATOM truncates to whole
        // seconds, so a sub-second lease could be expired on creation (T8).
        $record->leaseExpiresAt = Timestamp::inSeconds($leaseSeconds);
        $record->cancellationTokenId = $token->tokenId;
        $this->cancellations[$jobId] = $token;
        $this->emit('job.resumed', $record);
        // The new execution owns the NEW epoch: the previous execution of
        // this job still holds the old epoch and is fenced out by
        // `isStaleEpoch()` (M-35).
        $epoch = $record->leaseEpoch;
        $this->futures[$jobId] = AsyncRuntime::async(fn (): mixed => $this->execute($jobId, $operation, $epoch));
        return $record;
    }

    public function emitOutput(string $jobId, mixed $value): JobRecord
    {
        $record = $this->records[$jobId] ?? null;
        if ($record === null) {
            throw new \RuntimeException($jobId);
        }
        $record->output[] = $value;
        $this->emit('job.output', $record);
        return $record;
    }

    public function get(string $jobId): JobRecord
    {
        if (!isset($this->records[$jobId])) {
            throw new \RuntimeException($jobId);
        }
        return $this->records[$jobId];
    }

    /**
     * @return list<JobRecord>
     */
    public function list(): array
    {
        return array_values($this->records);
    }

    public function markInterrupted(): void
    {
        foreach ($this->records as $record) {
            if (in_array($record->status, [JobStatus::PENDING, JobStatus::RUNNING], true)) {
                $record->status = JobStatus::INTERRUPTED;
                $record->completedAt = \LightAgent\Session\SessionConstants::utcNow();
                $this->emit('job.interrupted', $record);
            }
        }
    }

    public function restore(Session $session): void
    {
        $records = [];
        foreach ($session->events as $event) {
            if (!str_starts_with($event->type, 'job.')) {
                continue;
            }
            $value = $event->data['job'] ?? null;
            if (!is_array($value)) {
                continue;
            }
            $record = JobRecord::fromDict($value);
            $records[$record->jobId] = $record;
        }
        $this->records = $records;
        $this->futures = [];
        $this->cancellations = [];
        $this->idempotencyKeys = [];
        foreach ($records as $record) {
            if ($record->idempotencyKey !== null) {
                $this->idempotencyKeys[$record->idempotencyKey] = $record->jobId;
            }
        }
    }

    private function emit(string $eventType, JobRecord $record): void
    {
        if ($this->eventSink !== null) {
            ($this->eventSink)($eventType, ['job' => $record->toDict()]);
        }
    }
}
