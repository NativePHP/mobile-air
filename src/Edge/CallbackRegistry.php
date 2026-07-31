<?php

namespace Native\Mobile\Edge;

class CallbackRegistry
{
    /**
     * Process-wide ID counter. Was per-instance, but per-instance IDs
     * collide across components — when component A registers
     * `closeSheet` as ID 5 and component B registers `confirmDelete` as
     * ID 5, an event meant for A's sheet (still visible from a stale
     * tree) lands on B's active runloop and fires `confirmDelete`
     * instead. Global counter guarantees IDs from different components
     * never collide; if an event arrives with an ID this component
     * doesn't own, `resolve()` returns null and `dispatch()` drops it
     * instead of mis-firing.
     *
     * u32 wire range (≈4B IDs) is plenty even for long-lived sessions.
     */
    protected static int $globalNextId = 1;

    protected array $map = [];

    protected array $expressionMap = [];

    protected array $navigationConfigs = [];

    /**
     * Optional `kind` tag per callback id. Used by `NativeComponent::dispatch`
     * to special-case callbacks whose return value must flow back through
     * the publish cycle (currently just `'search_query'` — the
     * `onSearchQuery($q): array` handler). Default callbacks have no kind
     * and are dispatched fire-and-forget.
     *
     * @var array<int, string>
     */
    protected array $kindMap = [];

    public function register(string $expression, ?string $kind = null): int
    {
        if (isset($this->expressionMap[$expression])) {
            $id = $this->expressionMap[$expression];
        } else {
            $id = self::$globalNextId++;
            $this->expressionMap[$expression] = $id;
            $this->map[$id] = self::parse($expression);
        }

        if ($kind !== null) {
            $this->kindMap[$id] = $kind;
        }

        return $id;
    }

    public function resolve(int $id): ?array
    {
        return $this->map[$id] ?? null;
    }

    public function kind(int $id): ?string
    {
        return $this->kindMap[$id] ?? null;
    }

    /** Look up a callback ID by its expression string. */
    public function lookup(string $expression): ?int
    {
        return $this->expressionMap[$expression] ?? null;
    }

    /**
     * All registered expressions, keyed by expression → id. Used by the
     * testing harness to resolve a method name to its callback id.
     *
     * @return array<string, int>
     */
    public function expressions(): array
    {
        return $this->expressionMap;
    }

    /**
     * Soft reset — keep ID mappings stable across frames.
     * Same expression always gets the same ID.
     */
    public function reset(): void
    {
        // Keep expressionMap and map intact for stable IDs.
    }

    /**
     * Store a navigation config and return a content-addressed key.
     * Same config always produces the same key for stable callback IDs.
     */
    public function registerNavigation(array $config): string
    {
        $key = 'n'.substr(md5(json_encode($config)), 0, 8);
        $this->navigationConfigs[$key] = $config;

        return $key;
    }

    public function resolveNavigation(string $key): ?array
    {
        return $this->navigationConfigs[$key] ?? null;
    }

    /**
     * Parse a `method('arg', ...)` expression into method + literal args.
     * Public because NativeComponent::emit() reuses it for tag-level
     * `@event="method(...)"` bindings on child-component tags.
     */
    public static function parse(string $expression): array
    {
        if (! str_contains($expression, '(')) {
            return ['method' => $expression, 'args' => []];
        }

        $parenPos = strpos($expression, '(');
        $method = substr($expression, 0, $parenPos);
        $argsString = trim(substr($expression, $parenPos + 1, -1));

        if ($argsString === '') {
            return ['method' => $method, 'args' => []];
        }

        // Convert single quotes to double for JSON compatibility
        $json = '['.str_replace("'", '"', $argsString).']';
        $args = json_decode($json, true);

        return ['method' => $method, 'args' => $args ?? []];
    }
}
