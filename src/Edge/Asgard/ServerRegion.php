<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\NativeElementCollector;

/**
 * The client half of an Asgard region (docs/ASGARD-PROTOCOL.md §3).
 *
 * `@server('sales-summary') ... @endserver` compiles to open()/close()
 * around the placeholder body. open() emits the `server_region` node and
 * answers one question: does the placeholder render, or is there a
 * fragment to paint instead?
 *
 *     if (ServerRegion::open('sales-summary')) { ...placeholder... }
 *     ServerRegion::close();
 *
 * Everything here is client-side. There is no transport: a fragment
 * arrives through applyFragment() from whatever fetches it, and until
 * one does the placeholder is what the screen shows. That is the
 * intended degraded state, not an error (§3).
 *
 * ## Where the namespacing happens
 *
 * Nowhere in this class, on purpose. The region node is keyed with the
 * region name, and the two existing key-path mechanisms take it from
 * there: `Element::toArray()` builds each child's path from its
 * parent's, and the collector's streaming path pushes the same segment
 * onto its key-path stack. A fragment node whose relative key is
 * `total` therefore lands at `/sales-summary/total` in both paths
 * without this class hashing anything (§9.1).
 *
 * Callback ids are namespaced the other way, because they are derived
 * from a registry scope rather than a key path: fragment nodes register
 * into a `CallbackRegistry` scoped `server:<region>`, exactly as a child
 * component's nodes register into one scoped by its identity key. That
 * scope is applied by pinning it on the region node, which
 * `Element::toArray()` then hands down to every descendant.
 *
 * ## What is deliberately NOT here
 *
 * HTTP, sessions, crypto, and event dispatch back to a server (§10) all
 * belong to later workstreams. This class knows how to hold a fragment
 * and paint it. It never fetches one.
 */
class ServerRegion
{
    /**
     * Wire callback slot => the collector attr that carries an
     * expression. Servers send expressions, never ids (§9.1) — the two
     * sides derive ids in different scopes, so an id from a server is
     * meaningless here.
     */
    private const CALLBACK_ATTRS = [
        'on_press' => '_press',
        'on_long_press' => '_longPress',
        'on_double_tap' => '_doubleTap',
        'on_change' => '_change',
        'on_submit' => '_submit',
    ];

    /**
     * Element types a fragment may not use.
     *
     * Chrome is screen-level and a region is a subtree (§3), so a server
     * has no business emitting a nav bar, a tab bar or a FAB inside one.
     * `server_region` is here too: regions are declared by the client
     * view, so a server cannot open one.
     *
     * Today the chrome hoists in NativeComponent are shallow enough that
     * these would not escape the region anyway. The rule is about who is
     * allowed to say what, not about which hoist happens to be shallow.
     */
    private const DENIED_TYPES = [
        'top_bar', 'top_bar_action', 'top_bar_title',
        'bottom_nav', 'bottom_nav_item', 'bottom_bar',
        'side_nav', 'side_nav_item', 'side_nav_group', 'side_nav_header',
        'fab', 'server_region',
    ];

    /** Fragments held per region name, as normalized node lists. */
    protected static array $fragments = [];

    /** Per-region callback registries, keyed by region name. */
    protected static array $registries = [];

    /** Regions recorded in the current frame, keyed by name. */
    protected static array $frame = [];

    /** Names of the regions currently open, outermost first. */
    protected static array $open = [];

    /** Per-frame duplicate counters, keyed by the name as authored. */
    protected static array $duplicates = [];

    /**
     * Open a region. Returns true when the caller should render the
     * placeholder body, false when a fragment was painted in its place.
     *
     * A duplicate name in one frame is a bug in the view: the name
     * addresses a server component (§4) and a fragment is stored under
     * it, so two regions sharing one would paint each other's content.
     * With `app.debug` on it throws; otherwise the later regions are
     * suffixed (`sales-summary#2`) so they resolve to nothing and keep
     * their placeholders while the first region still works. That is
     * the same "loud in debug, degrade in production" trade §5.3 makes
     * for prop drift.
     */
    public static function open(string $name, array $attrs = []): bool
    {
        $name = static::deduplicate($name);

        // Extra attrs are the author's (`@server('x', ['class' => '…'])`),
        // but the name and the key it derives are not theirs to override.
        NativeElementCollector::open('server_region', array_merge($attrs, [
            'region' => $name,
            'native-key' => $name,
        ]));

        $fragment = static::$fragments[$name] ?? null;

        static::$frame[$name] = [
            'name' => $name,
            'key_path' => static::keyPath($name),
            'has_fragment' => $fragment !== null,
        ];

        static::$open[] = $name;

        if ($fragment === null) {
            return true;
        }

        $callbacks = static::registryFor($name);

        // Element-tree path: pinning the region node is enough. toArray()
        // resolves `ownedCallbacks` first and passes the result down, so
        // the whole fragment registers in the region's scope.
        NativeElementCollector::openElement()?->ownCallbacks($callbacks);

        // Streaming path: there is no element to pin — nodes are written
        // straight to C and register against whatever the collector has
        // bound — so swap the bound registry for the length of the emit.
        $bound = NativeElementCollector::callbacks();
        NativeElementCollector::setCallbacks($callbacks);

        try {
            static::emit($fragment);
        } finally {
            NativeElementCollector::setCallbacks($bound);
        }

        return false;
    }

    /** Close the region opened by the matching open(). */
    public static function close(): void
    {
        array_pop(static::$open);

        NativeElementCollector::close();
    }

    /**
     * Hand a region the fragment it should paint from now on — the seam
     * a transport calls once it has a `tree/json` body (§9).
     *
     * The tree is logical: type names, props, children, relative keys
     * and callback expressions. No ids, and any this class finds are
     * ignored rather than trusted (§9.1).
     *
     * Applying does not repaint on its own. The fragment is held, and
     * the region paints it the next time the screen renders. A live
     * element tree is only one of the two render paths — the streaming
     * path has no tree to mutate — and a fragment always arrives between
     * frames anyway, so "store, then render" is the one path that works
     * in both.
     *
     * Returns false for a fragment the client cannot render, in which
     * case the region keeps its placeholder. Throws instead when
     * `app.debug` is on, because in development an unrenderable fragment
     * is a mismatch between the two halves and silence would hide it.
     */
    public static function applyFragment(string $region, array $tree): bool
    {
        $nodes = static::normalize($tree);

        if (($rejection = static::rejectionReason($nodes)) !== null) {
            if (config('app.debug')) {
                throw new \RuntimeException(
                    "Asgard fragment for region '{$region}' cannot be rendered: {$rejection}"
                );
            }

            return false;
        }

        static::$fragments[$region] = $nodes;

        return true;
    }

    /** Drop a region's fragment; it goes back to its placeholder. */
    public static function clearFragment(string $region): void
    {
        unset(static::$fragments[$region]);
    }

    public static function hasFragment(string $region): bool
    {
        return isset(static::$fragments[$region]);
    }

    /**
     * The regions of the current frame:
     * `name => ['name' => …, 'key_path' => …, 'has_fragment' => bool]`.
     *
     * This is the list a transport reads to know what to ask for. It
     * survives until the next frame begins, so reading it straight after
     * a render gives that render's regions.
     *
     * The key path is built from the enclosing regions' names, which is
     * the node's real key path unless the view also keyed an ancestor of
     * the region by hand. The path then gains that prefix, and only the
     * encoder, which walks the actual tree, sees it.
     */
    public static function rendered(): array
    {
        return static::$frame;
    }

    /**
     * Start a new frame: drop the previous record and the per-frame
     * duplicate counters. Called by `NativeComponent` at the top of a
     * render, which is the only place that reliably knows a frame is
     * starting — a screen can render without touching the collector at
     * all, and one that does must read as having no regions rather than
     * inheriting the last screen's.
     */
    public static function beginFrame(): void
    {
        static::$frame = [];
        static::$open = [];
        static::$duplicates = [];
    }

    /**
     * The registry a region's fragment callbacks are derived in.
     *
     * Scoped by region name alone, which is enough because names are
     * unique per frame (see open()). Ids stay content-addressed within
     * that scope, so a re-render of the same fragment reproduces them.
     */
    public static function registryFor(string $region): CallbackRegistry
    {
        return static::$registries[$region] ??= new CallbackRegistry('server:'.$region);
    }

    /** Forget every fragment, registry and frame record. */
    public static function flush(): void
    {
        static::$fragments = [];
        static::$registries = [];
        static::$frame = [];
        static::$open = [];
        static::$duplicates = [];
    }

    // ── Internals ────────────────────────────────────

    protected static function deduplicate(string $name): string
    {
        $seen = static::$duplicates[$name] ?? 0;
        static::$duplicates[$name] = $seen + 1;

        if ($seen === 0) {
            return $name;
        }

        if (config('app.debug')) {
            throw new \RuntimeException(
                "Duplicate Asgard region '{$name}' in one screen. Region names address a "
                .'server component and hold its fragment, so they must be unique per screen.'
            );
        }

        return $name.'#'.($seen + 1);
    }

    /** The region's key path: its own name under any enclosing regions'. */
    protected static function keyPath(string $name): string
    {
        return '/'.implode('/', [...static::$open, $name]);
    }

    /**
     * Emit a normalized node list through the collector's public
     * open()/leaf()/close(), which is what makes a fragment work
     * identically in the Element-tree and streaming paths.
     */
    protected static function emit(array $nodes): void
    {
        foreach ($nodes as $node) {
            $type = (string) $node['type'];
            $attrs = static::attrsFor($node);
            $children = static::normalize($node['children'] ?? []);

            if ($children === []) {
                NativeElementCollector::leaf($type, $attrs);

                continue;
            }

            NativeElementCollector::open($type, $attrs);
            static::emit($children);
            NativeElementCollector::close();
        }
    }

    /**
     * Translate one logical node into the attribute bag the collector
     * already speaks — the same vocabulary a `<native:*>` tag compiles
     * to, so fragments and client markup go down one code path.
     *
     * Only known keys are read. A `props` bag is an untrusted map from a
     * server, and the collector drops attrs it does not recognize, so
     * nothing in it can reach a node field the element itself does not
     * expose. The compiler-internal spellings are stripped first: `_*`
     * is how a compiled `@tap` / `@navigate` binding travels, and a
     * server that could write those would be choosing which client
     * method runs (or navigating the app) rather than naming a handler
     * of its own.
     */
    protected static function attrsFor(array $node): array
    {
        $attrs = is_array($node['props'] ?? null) ? $node['props'] : [];

        foreach (array_keys($attrs) as $key) {
            $key = (string) $key;

            if (str_starts_with($key, '_') || str_starts_with($key, 'native-') || str_starts_with($key, 'native:')) {
                unset($attrs[$key]);
            }
        }

        if (isset($node['class']) && is_string($node['class'])) {
            $attrs['class'] = $node['class'];
        }

        // Relative key (§9.1) — the collector hashes it against the
        // region's path, so the server never sees a node id.
        if (isset($node['key']) && (is_string($node['key']) || is_int($node['key']))) {
            $attrs['native-key'] = (string) $node['key'];
        }

        // Callback slots carry an expression string. Anything else in one
        // (an id, a number, an array) is dropped rather than registered.
        foreach (self::CALLBACK_ATTRS as $slot => $attr) {
            if (isset($node[$slot]) && is_string($node[$slot]) && $node[$slot] !== '') {
                $attrs[$attr] = $node[$slot];
            }
        }

        // Ids are the client's (§9.1) — never take one off the wire.
        unset($attrs['id'], $attrs['node_id']);

        return $attrs;
    }

    /** Accept a single node or a list of them; always return a list. */
    protected static function normalize(array $tree): array
    {
        if ($tree === []) {
            return [];
        }

        return isset($tree['type']) ? [$tree] : array_values($tree);
    }

    /**
     * Why this fragment cannot be rendered, or null if it can.
     *
     * An unregistered type would throw out of createElement() mid-render
     * and take the whole screen with it, which is the opposite of §3's
     * "a region that never resolves keeps its placeholder". Checking at
     * the seam means a bad fragment is never stored.
     */
    protected static function rejectionReason(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['type']) || ! is_string($node['type'])) {
                return 'a node has no type';
            }

            $type = $node['type'];

            if (in_array($type, self::DENIED_TYPES, true)) {
                return "'{$type}' is chrome, which is screen-level and not a region's to place";
            }

            if (! ElementRegistry::has($type)) {
                return "'{$type}' is not an element type this build has";
            }

            $children = $node['children'] ?? [];

            if (! is_array($children)) {
                return "'{$type}' has children that are not a list";
            }

            if (($reason = static::rejectionReason(static::normalize($children))) !== null) {
                return $reason;
            }
        }

        return null;
    }
}
