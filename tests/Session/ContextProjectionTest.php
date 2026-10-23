<?php

declare(strict_types=1);

namespace LightAgent\Tests\Session;

use LightAgent\Session\ContextProjector;
use LightAgent\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Context projection tests.
 *
 * These used to live in `SessionTest.php`, where PHPUnit never collected
 * them: the file-based test discovery only registers a class whose name
 * matches the file name, so `ContextProjectorTest` inside
 * `SessionTest.php` was dead code.
 */
final class ContextProjectionTest extends TestCase
{
    public function test_messages_projection(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'hi'], turnId: 't1');
        $session->append('assistant.completed', ['content' => 'hello'], turnId: 't1');
        $session->append('tool.completed', ['tool_call_id' => 'c1', 'output' => '42'], turnId: 't1');

        $messages = (new ContextProjector())->messages($session, 't1');

        self::assertSame('user', $messages[0]['role']);
        self::assertSame('hi', $messages[0]['content']);
        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('hello', $messages[1]['content']);
        // The tool result has no assistant declaration in this session, so
        // the projector synthesises one (V5-47) — the tool message must
        // never stand alone.
        self::assertSame('assistant', $messages[2]['role']);
        self::assertSame('c1', $messages[2]['tool_calls'][0]['id']);
        self::assertSame('tool', $messages[3]['role']);
        self::assertSame('c1', $messages[3]['tool_call_id']);
        self::assertSame('42', $messages[3]['content']);
    }

    public function test_model_request_reconstruction(): void
    {
        $session = new Session();
        $session->append('model.requested', ['messages' => [['role' => 'user', 'content' => 'q1']]]);
        $session->append('model.requested', ['messages' => [['role' => 'user', 'content' => 'q2']]]);

        $projector = new ContextProjector();
        self::assertSame([['role' => 'user', 'content' => 'q2']], $projector->modelRequest($session));
        self::assertSame([['role' => 'user', 'content' => 'q1']], $projector->modelRequest($session, 1));

        $this->expectException(\RuntimeException::class);
        $projector->modelRequest(new Session());
    }

    public function test_trace_projection(): void
    {
        $session = new Session();
        $session->append('trace.recorded', ['trace_type' => 'run_start', 'trace_data' => ['query' => 'x'], 'trace_id' => 'tid']);

        $trace = (new ContextProjector())->trace($session);
        self::assertCount(1, $trace);
        self::assertSame('run_start', $trace[0]['type']);
        self::assertSame('tid', $trace[0]['trace_id']);
    }

    /**
     * V5-47: a `tool` message must follow an assistant message that
     * declares the matching tool call.
     */
    public function test_tool_message_is_never_orphaned(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'q']);
        $session->append('tool.completed', ['id' => 'call_x', 'name' => 't', 'output' => 'out']);

        $messages = (new ContextProjector())->messages($session);
        $declared = [];
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? '') === 'assistant' && isset($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $call) {
                    $declared[(string) $call['id']] = $index;
                }
            }
            if (($message['role'] ?? '') === 'tool') {
                $id = (string) ($message['tool_call_id'] ?? '');
                self::assertArrayHasKey($id, $declared, 'the tool result must be declared by an assistant message');
                self::assertLessThan(
                    $index,
                    $declared[$id],
                    'the assistant declaration must come BEFORE the tool result',
                );
            }
        }
    }

    /**
     * V5-47: the tool_calls carried by an `assistant.completed` event must
     * survive the projection — dropping them produced an assistant
     * message without a declaration followed by a tool message.
     */
    public function test_assistant_tool_calls_declaration_is_preserved(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'q']);
        $session->append('assistant.completed', [
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'search', 'arguments' => '{"q":"x"}'],
            ]],
        ]);
        $session->append('tool.completed', ['id' => 'call_1', 'name' => 'search', 'output' => 'hit']);

        $messages = (new ContextProjector())->messages($session);

        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('call_1', $messages[1]['tool_calls'][0]['id']);
        self::assertSame('search', $messages[1]['tool_calls'][0]['function']['name']);
        self::assertSame('tool', $messages[2]['role']);
        self::assertSame('call_1', $messages[2]['tool_call_id']);
    }
}
