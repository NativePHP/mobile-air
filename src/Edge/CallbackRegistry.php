<?php

namespace Native\Mobile\Edge;

class CallbackRegistry
{
    protected int $nextId = 1;

    protected array $map = [];

    public function register(string $method): int
    {
        $id = $this->nextId++;
        $this->map[$id] = $method;

        return $id;
    }

    public function resolve(int $id): ?string
    {
        return $this->map[$id] ?? null;
    }

    public function reset(): void
    {
        $this->nextId = 1;
        $this->map = [];
    }
}
