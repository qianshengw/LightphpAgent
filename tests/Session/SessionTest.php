<?php

declare(strict_types=1);

namespace LightAgent\Tests\Session;

use LightAgent\Session\InMemorySessionStore;
use LightAgent\Session\JsonlSessionStore;
use LightAgent\Session\Session;
use LightAgent\Session\SessionEvent;
use LightAgent\Session\SqliteSessionStore;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_append_assigns_sequence_and_updates_timestamp(): void
    {
        $session = new Session();
        $e1 = $session->append('turn.started', ['a' => 1]);
        $e2 = $session->append('message.received', ['b' => 2]);

        self::assertSame(1, $e1->sequence);
        self::assertSame(2, $e2->sequence);
        self::assertSame(3, $session->nextSequence());
        self::assertSame($e2->timestamp, $session->updatedAt);
    }

    public function test_event_type_must_be_non_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SessionEvent(type: '', sessionId: 's');
    }

    public function test_append_event_sequence_validation(): void
    {
        $session = new Session();
        $session->append('a');

        $this->expectException(\InvalidArgumentException::class);
        $session->appendEvent(new SessionEvent(type: 'b', sessionId: $session->sessionId, sequence: 5));
    }

    public function test_fork_copies_events_through_boundary(): void
    {
        $session = new Session();
        $session->append('e1', ['i' => 1]);
        $session->append('e2', ['i' => 2]);
        $session->append('e3', ['i' => 3]);

        $forked = $session->fork(throughSequence: 2);
        $types = array_map(static fn (SessionEvent $e): string => $e->type, $forked->events);

        self::assertSame(['session.started', 'e1', 'e2', 'session.forked'], $types);
        self::assertSame($session->sessionId, $forked->metadata['forked_from']);
        self::assertSame(2, $forked->metadata['forked_at_sequence']);
    }

    public function test_fork_boundary_out_of_range(): void
    {
        $session = new Session();
        $session->append('e1');

        $this->expectException(\InvalidArgumentException::class);
        $session->fork(throughSequence: 9);
    }

    public function test_replay_classifies_turns(): void
    {
        $session = new Session();
        $session->append('turn.started', [], turnId: 't1');
        $session->append('turn.completed', [], turnId: 't1');
        $session->append('turn.started', [], turnId: 't2');
        $session->append('turn.failed', [], turnId: 't3');

        $replay = $session->replay();

        self::assertSame(['t1'], $replay->completedTurns);
        self::assertSame(['t2'], $replay->incompleteTurns);
        self::assertSame(['t3'], $replay->failedTurns);
    }

    public function test_json_safe_redacts_sensitive_keys(): void
    {
        $session = new Session();
        $event = $session->append('x', ['api_key' => 'sk-123', 'name' => 'ok']);

        self::assertSame('[redacted]', $event->data['api_key']);
        self::assertSame('ok', $event->data['name']);
    }

    public function test_validate_detects_sequence_gap(): void
    {
        $session = new Session();
        $session->append('e1');
        $reflection = new \ReflectionProperty(Session::class, 'events');
        $events = $session->events;
        // Forge a gap
        $forged = new SessionEvent(type: 'e2', sessionId: $session->sessionId, sequence: 3);
        $events[] = $forged;
        $reflection->setValue($session, $events);

        $this->expectException(\InvalidArgumentException::class);
        $session->validate();
    }

    // ------------------------------------------------------------------
    // Stores
    // ------------------------------------------------------------------

    public function test_in_memory_store_roundtrip(): void
    {
        $store = new InMemorySessionStore();
        $session = $store->create(null, metadata: ['k' => 'v']);
        $session->append('e1', ['x' => 1]);
        $store->save($session);

        $loaded = $store->get($session->sessionId);
        self::assertNotNull($loaded);
        self::assertSame('v', $loaded->metadata['k']);
        self::assertCount(1, $loaded->events);
        self::assertSame('e1', $loaded->events[0]->type);
    }

    public function test_in_memory_create_duplicate_rejected(): void
    {
        $store = new InMemorySessionStore();
        $session = $store->create();
        $this->expectException(\InvalidArgumentException::class);
        $store->create(new Session(sessionId: $session->sessionId));
    }

    public function test_in_memory_refuses_older_sequence(): void
    {
        $store = new InMemorySessionStore();
        $session = $store->create();
        $session->append('e1');
        $session->append('e2');
        $store->save($session);

        $older = $store->get($session->sessionId);
        // Roll back to before e2 and try to save
        array_pop($older->events);

        $this->expectException(\InvalidArgumentException::class);
        $store->save($older);
    }

    public function test_jsonl_store_roundtrip(): void
    {
        $dir = sys_get_temp_dir() . '/la_jsonl_' . bin2hex(random_bytes(4));
        try {
            $store = new JsonlSessionStore($dir);
            $session = $store->create(null, metadata: ['k' => 'v']);
            $session->append('e1', ['x' => '中文']);
            $session->append('e2');
            $store->save($session);

            $loaded = $store->get($session->sessionId);
            self::assertNotNull($loaded);
            self::assertSame('中文', $loaded->events[0]->data['x']);
            self::assertCount(2, $loaded->events);

            $listed = $store->list();
            self::assertCount(1, $listed);

            self::assertTrue($store->delete($session->sessionId));
            self::assertFalse($store->delete($session->sessionId));
        } finally {
            foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_sqlite_store_roundtrip(): void
    {
        $path = sys_get_temp_dir() . '/la_sqlite_' . bin2hex(random_bytes(4)) . '.db';
        try {
            $store = new SqliteSessionStore($path);
            $session = $store->create(null, metadata: ['k' => 'v']);
            $session->append('e1', ['x' => 1]);
            $session->append('e2', ['y' => 2]);
            $store->save($session);

            $loaded = $store->get($session->sessionId);
            self::assertNotNull($loaded);
            self::assertCount(2, $loaded->events);
            self::assertSame(2, $loaded->events[1]->sequence);

            // Save again with new event (upsert path)
            $loaded->append('e3');
            $store->save($loaded);
            $reloaded = $store->get($session->sessionId);
            self::assertCount(3, $reloaded->events);

            self::assertCount(1, $store->list());
            self::assertTrue($store->delete($session->sessionId));
            self::assertNull($store->get($session->sessionId));
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }
}
