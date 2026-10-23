<?php

declare(strict_types=1);

namespace LightAgent\Protocol;

/**
 * Memory storage & retrieval protocol (protocol.py MemoryProtocol).
 *
 * PHP port: interface instead of typing.Protocol. Implementations may return
 * any value, mirroring the Python `-> Any` signatures.
 */
interface MemoryProtocol
{
    public function store(string $data, string $userId): mixed;

    public function retrieve(string $query, string $userId): mixed;
}
