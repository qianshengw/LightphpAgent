<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Create isolated workers from stable application-owned registrations
 * (worker.py RegistryWorkerFactory).
 */
final class RegistryWorkerFactory
{
    /** @var array<string, mixed> */
    public array $registrations = [];

    /** @var array<string, bool> */
    public array $factories = [];

    /**
     * @param array<string, mixed>|null $registrations
     */
    public function __construct(?array $registrations = null)
    {
        $this->registrations = $registrations ?? [];
    }

    public function register(string $key, mixed $factory): void
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('worker key must not be empty');
        }
        $this->registrations[$key] = $factory;
    }

    public function registerFactory(string $key, mixed $factory): void
    {
        $this->register($key, $factory);
        $this->factories[$key] = true;
    }

    public function create(string $workerKey, TaskContext $context): object
    {
        if (!isset($this->registrations[$workerKey])) {
            throw new \RuntimeException("worker `{$workerKey}` is not registered");
        }
        $registration = $this->registrations[$workerKey];
        if (isset($this->factories[$workerKey])) {
            $value = is_callable($registration) ? $registration($context) : $registration;
        } else {
            $value = $registration;
        }
        if (is_object($value) && method_exists($value, 'execute')) {
            return $value;
        }
        if (is_callable($value)) {
            return new CallableWorker(\Closure::fromCallable($value));
        }
        throw new \TypeError("worker registration `{$workerKey}` does not produce a DAGWorker");
    }

    public function release(object $worker, TaskContext $context): void
    {
        if (method_exists($worker, 'close')) {
            $worker->close();
        }
    }
}
