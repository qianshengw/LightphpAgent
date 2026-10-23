<?php

declare(strict_types=1);

namespace LightAgent\Result;

use LightAgent\Support\Json;

/**
 * Optional structured event for streaming agent runs (result.py StreamEvent).
 */
final class StreamEvent implements \Stringable
{
    public function __construct(
        public readonly string $type,
        public mixed $data = null,
        public readonly ?string $traceId = null,
    ) {
    }

    public function __toString(): string
    {
        $data = $this->data;
        if ($data === null) {
            return '';
        }
        if (is_string($data)) {
            return $data;
        }
        if (is_scalar($data)) {
            return (string) $data;
        }
        $json = Json::encodeOrError($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json;
    }
}
