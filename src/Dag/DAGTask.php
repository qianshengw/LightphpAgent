<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Task aggregate (dag/models.py DAGTask).
 */
final class DAGTask
{
    public function __construct(
        public TaskSpec $spec,
        public TaskState $state,
        public string $createdAt = '',
    ) {
        if ($this->createdAt === '') {
            $this->createdAt = DAGConstants::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'spec' => $this->spec->toDict(),
            'state' => $this->state->toDict(),
            'created_at' => $this->createdAt,
        ];
    }
}
