<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Dependency graph algorithms that avoid recursion limits
 * (port of dag/graph.py).
 */
final class DAGGraph
{
    private function __construct()
    {
    }

    /**
     * Deterministic topological order via iterative Kahn's algorithm.
     *
     * @param iterable<string> $taskIds
     * @param iterable<array{0: string, 1: string}> $edges [task_id, dependency_id]
     * @return list<string>
     */
    public static function topologicalOrder(iterable $taskIds, iterable $edges): array
    {
        $nodes = [];
        foreach ($taskIds as $id) {
            $nodes[$id] = true;
        }
        $edgeSet = [];
        foreach ($edges as $edge) {
            $edgeSet[$edge[0] . '|' . $edge[1]] = [$edge[0], $edge[1]];
        }

        $unknown = [];
        foreach ($edgeSet as [$taskId, $dependencyId]) {
            foreach ([$taskId, $dependencyId] as $item) {
                if (!isset($nodes[$item])) {
                    $unknown[$item] = true;
                }
            }
        }
        if ($unknown !== []) {
            $sorted = array_keys($unknown);
            sort($sorted);
            throw new DAGError('UNKNOWN-DEPENDENCY', 'unknown task IDs: ' . json_encode($sorted));
        }
        foreach ($edgeSet as [$taskId, $dependencyId]) {
            if ($taskId === $dependencyId) {
                throw new DAGError('CYCLE', 'self dependencies are not allowed');
            }
        }

        /** @var array<string, int> $indegree */
        $indegree = array_fill_keys(array_keys($nodes), 0);
        /** @var array<string, list<string>> $reverse */
        $reverse = array_fill_keys(array_keys($nodes), []);
        foreach ($edgeSet as [$taskId, $dependencyId]) {
            $indegree[$taskId]++;
            $reverse[$dependencyId][] = $taskId;
        }

        // sorted deque of ready nodes (Python: deque(sorted(...)), popleft)
        $ready = [];
        foreach ($indegree as $taskId => $degree) {
            if ($degree === 0) {
                $ready[] = $taskId;
            }
        }
        sort($ready);

        /** @var list<string> $ordered */
        $ordered = [];
        while ($ready !== []) {
            $current = array_shift($ready); // popleft
            $ordered[] = $current;
            $dependents = $reverse[$current];
            sort($dependents);
            foreach ($dependents as $dependent) {
                $indegree[$dependent]--;
                if ($indegree[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
        }

        if (count($ordered) !== count($nodes)) {
            throw new DAGError('CYCLE', 'task dependencies contain a cycle');
        }
        return $ordered;
    }

    /**
     * Transitive dependency closure of one task.
     *
     * @param iterable<array{0: string, 1: string}> $edges
     * @return list<string>
     */
    public static function dependencyClosure(string $taskId, iterable $edges): array
    {
        /** @var array<string, list<string>> $dependencies */
        $dependencies = [];
        foreach ($edges as [$task, $dependency]) {
            $dependencies[$task][] = $dependency;
        }
        $seen = [];
        $pending = $dependencies[$taskId] ?? [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($dependencies[$current] ?? [] as $next) {
                $pending[] = $next;
            }
        }
        return array_keys($seen);
    }
}
