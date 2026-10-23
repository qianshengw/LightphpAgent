<?php

declare(strict_types=1);

namespace LightAgent\Session;

use LightAgent\Support\Json;
/**
 * Context projection, budget, and compaction
 * (session.py ContextProjector / ContextBudget / ContextCompactor / CompactionResult).
 */
final class ContextProjector
{
    /**
     * Project model messages from Session events.
     *
     * Tool protocol correctness (OpenAI contract): every tool result message
     * must (a) carry a non-null tool_call_id and (b) directly follow an
     * assistant message declaring the matching tool_calls. The projector
     * buffers tool results and flushes them after the assistant turn closes,
     * synthesizing the assistant tool_calls message from tool.requested
     * events when the assistant.completed event does not carry one.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(Session $session, ?string $turnId = null): array
    {
        $requested = [];
        foreach ($session->events as $event) {
            if (
                $event->type === 'model.requested'
                && ($turnId === null || $event->turnId === $turnId)
                && is_array($event->data['messages'] ?? null)
            ) {
                $requested[] = $event;
            }
        }

        $messages = [];
        // Tool results waiting for their assistant tool_calls message. Each
        // entry is a well-formed role=tool message.
        $pendingToolMessages = [];
        // Ids declared via tool.requested that have NOT been answered by a
        // tool.completed yet (A3): failed/blocked tools must still produce a
        // tool message, or the projected history carries orphan tool_calls.
        $openToolCallIds = [];
        // Ids that a projected assistant message has ALREADY declared
        // (V5-47): a tool result whose id is declared here is not an
        // orphan, even though no tool.requested event opened it.
        $declaredToolCallIds = [];
        // error.recorded payloads keyed by tool name — used to answer failed
        // tool calls with the actual error text instead of a generic string.
        $toolErrorBuffers = [];
        // Deferred declaration group (C2b): the in-progress assistant
        // tool_calls message and its collected responses. Null when no tool
        // group is open.
        $pendingAssistant = null;
        $pendingResponses = [];
        $flushPending = static function () use (&$messages, &$pendingToolMessages, &$openToolCallIds, &$toolErrorBuffers, &$pendingAssistant, &$pendingResponses): void {
            // Answer any tool call that never completed (parse failure,
            // guardrail block, execution error).
            foreach ($openToolCallIds as $id => $toolName) {
                $errorText = null;
                if ($toolName !== null && isset($toolErrorBuffers[$toolName])) {
                    $errorText = array_shift($toolErrorBuffers[$toolName]);
                }
                $pendingResponses[] = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'content' => (string) ($errorText ?? '[LA-TOOL] Tool call did not complete.'),
                ];
            }
            $openToolCallIds = [];
            // Emit the deferred declaration group: assistant first, then its
            // responses — never interleaved with other message roles.
            if ($pendingAssistant !== null) {
                $messages[] = $pendingAssistant;
                $pendingAssistant = null;
            }
            if ($pendingResponses !== []) {
                $messages = array_merge($messages, $pendingResponses);
                $pendingResponses = [];
            }
            if ($pendingToolMessages !== []) {
                $messages = array_merge($messages, $pendingToolMessages);
                $pendingToolMessages = [];
            }
        };

        foreach ($session->events as $event) {
            if ($turnId !== null && $event->turnId !== $turnId) {
                continue;
            }
            if (in_array($event->type, ['message.received', 'message.created'], true)) {
                $flushPending();
                $messages[] = [
                    'role' => ($event->data['role'] ?? 'user'),
                    'content' => ($event->data['content'] ?? ''),
                ];
            } elseif (in_array($event->type, ['assistant.completed', 'model.completed'], true)
                && (($event->data['content'] ?? null) !== null
                    || is_array($event->data['tool_calls'] ?? null))) {
                $flushPending();
                $assistant = ['role' => 'assistant', 'content' => ($event->data['content'] ?? '')];
                // The assistant message may CARRY the tool calls it made
                // (V5-47). Dropping that declaration while still emitting
                // the matching tool message produced an assistant message
                // without tool_calls followed by a tool message — an
                // invalid OpenAI sequence that the provider rejects.
                $toolCalls = self::normalizeToolCalls($event->data['tool_calls'] ?? null);
                if ($toolCalls !== []) {
                    $assistant['tool_calls'] = $toolCalls;
                    foreach ($toolCalls as $call) {
                        $declaredToolCallIds[(string) $call['id']] = true;
                    }
                }
                $messages[] = $assistant;
            } elseif ($event->type === 'tool.requested') {
                // The assistant message declaring the tool call is DEFERRED
                // (C2b): the live path records requested/completed events
                // INTERLEAVED per tool (req A, comp A, req B, comp B), so a
                // declaration emitted on first sight would produce
                // [assistant(A), assistant(B), tool(A), tool(B)] — an OpenAI
                // protocol violation. Instead, declarations accumulate in a
                // pending group; the whole group is flushed (assistant
                // message + its tool messages, in id order) once every call
                // in the group has a response or the next non-tool event
                // arrives. This matches the live chatParams shape exactly.
                $toolCallId = $event->data['id']
                    ?? ('call_' . substr(md5((string) ($event->data['name'] ?? '') . '|' . (string) ($event->data['arguments'] ?? '')), 0, 12));
                if ($pendingAssistant !== null && $pendingResponses === []) {
                    // Same declaration group still open and unanswered —
                    // append to it.
                    $pendingAssistant['tool_calls'][] = [
                        'id' => $toolCallId,
                        'type' => 'function',
                        'function' => [
                            'name' => (string) ($event->data['name'] ?? ''),
                            'arguments' => (string) ($event->data['arguments'] ?? '{}'),
                        ],
                    ];
                } else {
                    $flushPending();
                    $pendingAssistant = [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => $toolCallId,
                            'type' => 'function',
                            'function' => [
                                'name' => (string) ($event->data['name'] ?? ''),
                                'arguments' => (string) ($event->data['arguments'] ?? '{}'),
                            ],
                        ]],
                    ];
                }
                $openToolCallIds[$toolCallId] = isset($event->data['name']) ? (string) $event->data['name'] : null;
                $declaredToolCallIds[$toolCallId] = true;
            } elseif ($event->type === 'tool.completed') {
                $toolCallId = $event->data['id']
                    ?? $event->data['tool_call_id']
                    ?? null;
                if ($toolCallId === null) {
                    // Legacy sessions without an id: pair with the OLDEST
                    // requested-but-unanswered call of the same tool name
                    // (FIFO), falling back to any open call. A name-only hash
                    // would collide when one tool is called twice.
                    $toolName = isset($event->data['name']) ? (string) $event->data['name'] : null;
                    $toolCallId = null;
                    foreach ($openToolCallIds as $openId => $openName) {
                        if ($openName !== null && $openName === $toolName) {
                            $toolCallId = $openId;
                            break;
                        }
                    }
                    if ($toolCallId === null && $openToolCallIds !== []) {
                        $toolCallId = (string) array_key_first($openToolCallIds);
                    }
                    if ($toolCallId === null) {
                        // No open call at all (defensive): standalone id.
                        $toolCallId = 'call_' . substr(md5((string) $toolName . '|' . (string) ($event->data['output'] ?? '')), 0, 12);
                    }
                }
                // Was this tool call declared by an assistant message? The
                // flag has to be captured BEFORE the id is removed from the
                // open calls.
                $wasDeclared = isset($openToolCallIds[$toolCallId])
                    || isset($declaredToolCallIds[$toolCallId]);
                if (isset($openToolCallIds[$toolCallId])) {
                    unset($openToolCallIds[$toolCallId]);
                }
                // ORPHAN tool result (V5-47): no assistant message declares
                // this tool call, so the projected history would carry a
                // role=tool message without the matching tool_calls
                // declaration — an invalid OpenAI sequence (the provider
                // answers 400). The declaration is synthesised here so the
                // tool result stays in the context AND the sequence stays
                // valid.
                if (!$wasDeclared) {
                    $flushPending();
                    $pendingAssistant = [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => $toolCallId,
                            'type' => 'function',
                            'function' => [
                                'name' => (string) ($event->data['name'] ?? ''),
                                'arguments' => (string) ($event->data['arguments'] ?? '{}'),
                            ],
                        ]],
                    ];
                    $declaredToolCallIds[$toolCallId] = true;
                }
                $pendingResponses[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => (string) ($event->data['output'] ?? ''),
                ];
                // Group complete: every declared call now has its response —
                // emit assistant(tool_calls) followed by the tool messages.
                if ($pendingAssistant !== null && $openToolCallIds === []) {
                    $messages[] = $pendingAssistant;
                    $messages = array_merge($messages, $pendingResponses);
                    $pendingAssistant = null;
                    $pendingResponses = [];
                }
            } elseif ($event->type === 'error.recorded'
                && in_array($event->data['stage'] ?? '', ['tool_arguments', 'tool_guardrail', 'tool_execution'], true)
                && isset($event->data['error'])) {
                $toolName = isset($event->data['tool']) ? (string) $event->data['tool'] : null;
                if ($toolName !== null) {
                    $toolErrorBuffers[$toolName][] = (string) $event->data['error'];
                }
            }
        }
        $flushPending();

        if ($messages !== []) {
            return $messages;
        }
        if ($requested !== []) {
            $last = end($requested);
            return Json::decode($last->data['messages']);
        }
        return [];
    }

    /**
     * Reconstruct the exact messages sent for one persisted model request.
     *
     * @return list<array<string, mixed>>
     */
    public function modelRequest(Session $session, ?int $sequence = null): array
    {
        $requested = [];
        foreach ($session->events as $event) {
            if (
                $event->type === 'model.requested'
                && is_array($event->data['messages'] ?? null)
                && ($sequence === null || $event->sequence === $sequence)
            ) {
                $requested[] = $event;
            }
        }
        if ($requested === []) {
            throw new \RuntimeException('model request event was not found');
        }
        $last = end($requested);
        return Json::decode($last->data['messages']);
    }

    /**
     * Normalise the tool calls carried by an event into the OpenAI shape.
     *
     * The payloads differ between the recorders (the live path stores the
     * provider's `function_call` array, older sessions store a flat
     * `name`/`arguments` pair), so both spellings are accepted.
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeToolCalls(mixed $toolCalls): array
    {
        if (!is_array($toolCalls)) {
            return [];
        }
        $out = [];
        foreach ($toolCalls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $id = (string) ($call['id'] ?? $call['tool_call_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'type' => (string) ($call['type'] ?? 'function'),
                'function' => [
                    'name' => (string) ($function['name'] ?? $call['name'] ?? ''),
                    'arguments' => (string) ($function['arguments'] ?? $call['arguments'] ?? '{}'),
                ],
            ];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trace(Session $session, ?string $turnId = null): array
    {
        $trace = [];
        foreach ($session->events as $event) {
            if ($turnId !== null && $event->turnId !== $turnId) {
                continue;
            }
            $traceType = $event->data['trace_type'] ?? null;
            if ($traceType !== null) {
                $trace[] = [
                    'type' => $traceType,
                    'data' => $event->data['trace_data'] ?? [],
                    'timestamp' => $event->timestamp,
                    'trace_id' => $event->data['trace_id'] ?? null,
                    'parent_trace_id' => $event->data['parent_trace_id'] ?? null,
                    'run_group_id' => $event->data['run_group_id'] ?? null,
                ];
            }
        }
        return $trace;
    }
}
