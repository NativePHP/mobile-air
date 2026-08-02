<?php

namespace Native\Mobile\Edge;

class CallbackRegistry
{
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

    /**
     * IDs are content-addressed: a pure function of the expression string
     * (fnv1a32). Same expression always yields the same id — in any
     * process, any request, any registry instance. That removes every
     * positional-determinism assumption from the protocol: no counter,
     * no render-order dependence, nothing to replay to rebuild ids.
     *
     * Distinct expressions across components get hash-distinct ids, so
     * the old cross-component collision hazard (two counters both
     * handing out id 5) can't occur. Two components sharing an
     * expression share an id — semantically safe: same expression means
     * same method + literal args.
     */
    public function register(string $expression, ?string $kind = null): int
    {
        if (isset($this->expressionMap[$expression])) {
            $id = $this->expressionMap[$expression];
        } else {
            $id = $this->deriveId($expression);
            $this->expressionMap[$expression] = $id;
            $this->map[$id] = self::parse($expression);
        }

        if ($kind !== null) {
            $this->kindMap[$id] = $kind;
        }

        return $id;
    }

    /**
     * Hash an expression to a callback id.
     *
     * Masked to 31 bits: the Kotlin side reads callback ids as signed
     * Int (`getInt("on_press")`, `sendPressEvent(callbackId: Int, …)`),
     * so the sign bit must stay clear or full-u32 ids wrap negative on
     * the round trip and `resolve()` misses. fnv1a32 never returns 0,
     * but the mask can produce 0 — nudge to 1 (0 is the "no callback"
     * sentinel on the native side).
     *
     * Cross-expression hash collision (different expression, same id —
     * ~1 in 2^31): rehash with a salt suffix until free. The result is
     * deterministic given insertion order, and the wire expression map
     * preserves insertion order across requests, so re-registration
     * reproduces identical ids.
     */
    protected function deriveId(string $expression): int
    {
        $id = Element::fnv1a32($expression) & 0x7FFFFFFF;

        if ($id === 0) {
            $id = 1;
        }

        for ($salt = 1; isset($this->map[$id]); $salt++) {
            $id = Element::fnv1a32($expression."\x00".$salt) & 0x7FFFFFFF;

            if ($id === 0) {
                $id = 1;
            }
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
     * All stored navigation configs, keyed by content-addressed key.
     * The web runner ships these over the wire so `__navigate` presses
     * resolve on update without a pre-dispatch render; re-registering a
     * config recomputes the identical key.
     *
     * @return array<string, array>
     */
    public function navigations(): array
    {
        return $this->navigationConfigs;
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
