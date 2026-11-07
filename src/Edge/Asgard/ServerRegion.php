<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\System;

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
 * ## Asking for fragments
 *
 * After a frame in which a region painted its placeholder, the component
 * that rendered it asks the bound `FragmentSource` for one, once per
 * component instance (requestMissing()). refresh() asks again. The
 * source answers later through deliver(), which hands the fragment to
 * the right component and flags a repaint.
 *
 * ## What is deliberately NOT here
 *
 * HTTP, sessions, crypto, and event dispatch back to a server (§10) all
 * belong to other packages and later workstreams. This class holds
 * fragments, paints them and says when one is wanted. It never fetches
 * one itself.
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

    /**
     * Fragments held per owning component: `owner => [region => nodes]`.
     *
     * Keyed by component instance, not by region name alone, so two
     * screens (or two mounts of one child component) that each declare a
     * `sales-summary` region never paint each other's fragment. An
     * instance is a stricter key than the component's address: two
     * `<native:sales-card>` children share an address but not their
     * state, and the server rendered each fragment for one state. A
     * WeakMap also lets a fragment go when its component does.
     *
     * @var \WeakMap<NativeComponent, array<string, array>>|null
     */
    protected static ?\WeakMap $fragments = null;

    /**
     * Fragments for a region rendered outside any component (a bare
     * `view()->render()`, which only tests do). Nothing can request
     * these; they arrive through applyFragment() without an owner.
     */
    protected static array $detached = [];

    /** Per-region callback registries, keyed by region name. */
    protected static array $registries = [];

    /**
     * Regions recorded in the current frame, in render order. Each is
     * `['name', 'key_path', 'has_fragment', 'owner' => ?WeakReference]`.
     */
    protected static array $frame = [];

    /** Names of the regions currently open, outermost first. */
    protected static array $open = [];

    /** Per-frame duplicate counters, keyed by owner and the name as authored. */
    protected static array $duplicates = [];

    /**
     * The latest request id per owner and region:
     * `owner => [region => id]`. A region with an entry has been asked
     * for, so the next frame does not ask again. An answer whose id is
     * not the latest one is stale and dropped.
     *
     * @var \WeakMap<NativeComponent, array<string, string>>|null
     */
    protected static ?\WeakMap $requested = null;

    /**
     * Regions refreshServerRegions() asked to fetch again after the next
     * frame: `owner => [region => true]`, or `owner => ['*' => true]`.
     *
     * @var \WeakMap<NativeComponent, array<string, true>>|null
     */
    protected static ?\WeakMap $refreshing = null;

    /** Requests in flight: `id => ['owner' => WeakReference, 'region' => name]`. */
    protected static array $pending = [];

    /** Set when a fragment lands; cleared when the next frame begins. */
    protected static bool $repaint = false;

    protected static int $sequence = 0;

    /**
     * Open a region. Returns true when the caller should render the
     * placeholder body, false when a fragment was painted in its place.
     *
     * The region belongs to whichever component is rendering, which is a
     * child component when the block sits in a child's view.
     *
     * A duplicate name in one component's frame is a bug in the view: the
     * name addresses a server component (§4) and a fragment is stored
     * under it, so two regions sharing one would paint each other's
     * content. With `app.debug` on it throws; otherwise the later regions
     * are suffixed (`sales-summary#2`) so they resolve to nothing and keep
     * their placeholders while the first region still works. That is the
     * same "loud in debug, degrade in production" trade §5.3 makes for
     * prop drift.
     */
    public static function open(string $name, array $attrs = []): bool
    {
        $owner = NativeElementCollector::owner();
        $name = static::deduplicate($name, $owner);

        // Extra attrs are the author's (`@server('x', ['class' => '…'])`),
        // but the name and the key it derives are not theirs to override.
        NativeElementCollector::open('server_region', array_merge($attrs, [
            'region' => $name,
            'native-key' => $name,
        ]));

        $fragment = static::fragmentFor($name, $owner);

        static::$frame[] = [
            'name' => $name,
            'key_path' => static::keyPath($name),
            'has_fragment' => $fragment !== null,
            'owner' => $owner !== null ? \WeakReference::create($owner) : null,
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
     * Hand a region the fragment it should paint from now on.
     *
     * `$owner` is the component that renders the region. Leave it null
     * only for a region rendered outside any component. A transport
     * answering a request should use deliver() instead, which knows the
     * owner and drops stale answers.
     *
     * The tree is logical: type names, props, children, relative keys
     * and callback expressions (§9). It may be the decoded array or the
     * raw `tree/json` body. No ids, and any this class finds are ignored
     * rather than trusted (§9.1).
     *
     * Applying does not repaint on its own. The fragment is held, and
     * the region paints it the next time the screen renders. A live
     * element tree is only one of the two render paths (the streaming
     * path has no tree to mutate), so "store, then render" is the one
     * path that works in both. It does flag that a repaint is wanted,
     * which the runloop reads so a fragment that lands outside an event
     * still reaches the screen.
     *
     * Returns false for a fragment the client cannot render, in which
     * case the region keeps its placeholder. Throws instead when
     * `app.debug` is on, because in development an unrenderable fragment
     * is a mismatch between the two halves and silence would hide it.
     */
    public static function applyFragment(string $region, array|string $tree, ?NativeComponent $owner = null): bool
    {
        $nodes = is_string($tree) ? static::decode($tree) : static::normalize($tree);

        $rejection = $nodes === null
            ? 'the body is not a JSON tree'
            : static::rejectionReason($nodes);

        if ($rejection !== null) {
            if (config('app.debug')) {
                throw new \RuntimeException(
                    "Asgard fragment for region '{$region}' cannot be rendered: {$rejection}"
                );
            }

            return false;
        }

        if ($owner === null) {
            static::$detached[$region] = $nodes;
        } else {
            $held = static::fragments()[$owner] ?? [];
            $held[$region] = $nodes;
            static::fragments()[$owner] = $held;
        }

        static::$repaint = true;

        return true;
    }

    /**
     * Deliver the answer to a request: the seam a FragmentSource calls.
     *
     * The fragment goes to the component that rendered the region, which
     * is not necessarily the one an AsyncTask `->finished()` callback is
     * bound to (that is always the screen). It is dropped, and false
     * returned, when the request is unknown, when a newer request for the
     * same region has gone out since, or when the component has gone.
     * Otherwise this is applyFragment() for that component.
     */
    public static function deliver(RegionRequest $request, array|string $tree): bool
    {
        $entry = static::$pending[$request->id] ?? null;

        if ($entry === null) {
            NativeRouter::debugLog("Asgard: fragment for request [{$request->id}] is not one in flight — dropped.");

            return false;
        }

        $owner = $entry['owner']->get();

        if ($owner === null) {
            unset(static::$pending[$request->id]);
            NativeRouter::debugLog("Asgard: fragment for [{$request->region}] arrived after its component was gone — dropped.");

            return false;
        }

        $latest = (static::requested()[$owner] ?? [])[$entry['region']] ?? null;

        unset(static::$pending[$request->id]);

        if ($latest !== $request->id) {
            NativeRouter::debugLog("Asgard: fragment for [{$request->region}] was superseded by a newer request — dropped.");

            return false;
        }

        return static::applyFragment($entry['region'], $tree, $owner);
    }

    /** Drop a region's fragment; it goes back to its placeholder. */
    public static function clearFragment(string $region, ?NativeComponent $owner = null): void
    {
        if ($owner === null) {
            unset(static::$detached[$region]);

            return;
        }

        $held = static::fragments()[$owner] ?? [];
        unset($held[$region]);
        static::fragments()[$owner] = $held;
    }

    public static function hasFragment(string $region, ?NativeComponent $owner = null): bool
    {
        return static::fragmentFor($region, $owner) !== null;
    }

    /**
     * The regions of the current frame:
     * `name => ['name' => …, 'key_path' => …, 'has_fragment' => bool]`.
     *
     * Pass a component to see only the regions it rendered. Without one,
     * every region of the frame is listed, and a name two components
     * share shows the later one.
     *
     * The record survives until the next frame begins, so reading it
     * straight after a render gives that render's regions.
     *
     * The key path is built from the enclosing regions' names, which is
     * the node's real key path unless the view also keyed an ancestor of
     * the region by hand. The path then gains that prefix, and only the
     * encoder, which walks the actual tree, sees it.
     */
    public static function rendered(?NativeComponent $owner = null): array
    {
        $regions = [];

        foreach (static::$frame as $record) {
            if ($owner !== null && $record['owner']?->get() !== $owner) {
                continue;
            }

            unset($record['owner']);
            $regions[$record['name']] = $record;
        }

        return $regions;
    }

    /**
     * Start a new frame: drop the previous record and the per-frame
     * duplicate counters. Called by `NativeComponent` at the top of a
     * render, which is the only place that reliably knows a frame is
     * starting — a screen can render without touching the collector at
     * all, and one that does must read as having no regions rather than
     * inheriting the last screen's.
     *
     * The frame about to be drawn paints every fragment held so far, so
     * any repaint a delivery asked for is satisfied by it.
     */
    public static function beginFrame(): void
    {
        static::$frame = [];
        static::$open = [];
        static::$duplicates = [];
        static::$repaint = false;
    }

    /**
     * Ask for what the frame just drawn is missing. Called by
     * `NativeComponent` once a frame has rendered, on both render paths.
     *
     * A region that painted its placeholder is requested once per
     * component instance, so later frames do not ask again. A region
     * refreshServerRegions() named is requested even though it holds a
     * fragment, which stays on screen until the new one lands. A region
     * rendered outside any component has nothing to address and is
     * never requested.
     *
     * A source that throws is logged and otherwise ignored: a fetch that
     * could not start is a region that keeps its placeholder, not a
     * broken screen.
     */
    public static function requestMissing(): void
    {
        foreach (static::$frame as $record) {
            $owner = $record['owner']?->get();

            if ($owner === null) {
                continue;
            }

            $name = $record['name'];
            $refreshing = static::refreshing()[$owner] ?? [];
            $wanted = isset($refreshing['*']) || isset($refreshing[$name]);

            if (! $wanted && ($record['has_fragment'] || isset((static::requested()[$owner] ?? [])[$name]))) {
                continue;
            }

            unset($refreshing[$name]);
            static::refreshing()[$owner] = $refreshing;

            static::request($owner, $name);
        }

        // A refresh of everything covers this frame's regions only.
        $everything = [];

        foreach (static::refreshing() as $owner => $refreshing) {
            if (isset($refreshing['*'])) {
                $everything[] = $owner;
            }
        }

        foreach ($everything as $owner) {
            $refreshing = static::refreshing()[$owner];
            unset($refreshing['*']);
            static::refreshing()[$owner] = $refreshing;
        }
    }

    /**
     * Fetch a component's regions again after its next frame: one region
     * by name, or every region it renders. The fragment on screen stays
     * until the new one arrives, and an answer still in flight for an
     * earlier request is dropped when it lands.
     *
     * Deferred to the end of the frame rather than sent now, so the
     * request carries the state the calling action leaves behind, not
     * the state halfway through it.
     */
    public static function refresh(NativeComponent $owner, ?string $region = null): void
    {
        $refreshing = static::refreshing()[$owner] ?? [];
        $refreshing[$region ?? '*'] = true;
        static::refreshing()[$owner] = $refreshing;
    }

    /** Whether a fragment has landed since the current frame was drawn. */
    public static function repaintPending(): bool
    {
        return static::$repaint;
    }

    /**
     * Forget a component's requests. Called from `unmount()`, so an
     * answer that lands later is dropped rather than held for nobody.
     */
    public static function forget(NativeComponent $owner): void
    {
        foreach (static::$pending as $id => $entry) {
            $held = $entry['owner']->get();

            if ($held === null || $held === $owner) {
                unset(static::$pending[$id]);
            }
        }

        unset(static::requested()[$owner], static::refreshing()[$owner], static::fragments()[$owner]);
    }

    /**
     * The registry a region's fragment callbacks are derived in.
     *
     * Scoped by region name alone. Ids stay content-addressed within
     * that scope, so a re-render of the same fragment reproduces them.
     */
    public static function registryFor(string $region): CallbackRegistry
    {
        return static::$registries[$region] ??= new CallbackRegistry('server:'.$region);
    }

    /** Forget every fragment, request, registry and frame record. */
    public static function flush(): void
    {
        static::$fragments = null;
        static::$detached = [];
        static::$registries = [];
        static::$frame = [];
        static::$open = [];
        static::$duplicates = [];
        static::$requested = null;
        static::$refreshing = null;
        static::$pending = [];
        static::$repaint = false;
    }

    /**
     * A component's public props, kept to what JSON can carry (see
     * RegionRequest). An uninitialised typed prop is skipped rather than
     * read, as ServerMethods does.
     */
    public static function stateOf(NativeComponent $component): array
    {
        $state = [];

        foreach ((new \ReflectionClass($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || ! $prop->isInitialized($component)) {
                continue;
            }

            $value = $prop->getValue($component);

            if (static::jsonSafe($value)) {
                $state[$prop->getName()] = $value;
            }
        }

        return $state;
    }

    // ── Internals ────────────────────────────────────

    protected static function deduplicate(string $name, ?NativeComponent $owner): string
    {
        $counter = ($owner !== null ? spl_object_id($owner) : 0).'|'.$name;
        $seen = static::$duplicates[$counter] ?? 0;
        static::$duplicates[$counter] = $seen + 1;

        if ($seen === 0) {
            return $name;
        }

        if (config('app.debug')) {
            throw new \RuntimeException(
                "Duplicate Asgard region '{$name}' in one component. Region names address a "
                .'server component and hold its fragment, so they must be unique per component.'
            );
        }

        return $name.'#'.($seen + 1);
    }

    /** Send one request for a region of a component, and remember it. */
    protected static function request(NativeComponent $owner, string $region): void
    {
        $request = new RegionRequest(
            id: 'region-'.(++static::$sequence),
            component: ComponentAddress::for($owner),
            region: $region,
            state: static::stateOf($owner),
            platform: static::platform(),
            componentClass: $owner::class,
        );

        $requested = static::requested()[$owner] ?? [];
        $requested[$region] = $request->id;
        static::requested()[$owner] = $requested;

        static::$pending[$request->id] = [
            'owner' => \WeakReference::create($owner),
            'region' => $region,
        ];

        try {
            app(FragmentSource::class)->request($request);
        } catch (\Throwable $e) {
            NativeRouter::debugLog("Asgard: request for region [{$region}] could not start: ".$e->getMessage());
        }
    }

    /**
     * The platform the request is rendered for. Off a device (tests, a
     * web server) System reports none, and the request says `unknown`
     * rather than guessing.
     */
    protected static function platform(): string
    {
        try {
            return app(System::class)->platform() ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    protected static function fragmentFor(string $region, ?NativeComponent $owner): ?array
    {
        if ($owner === null) {
            return static::$detached[$region] ?? null;
        }

        return (static::fragments()[$owner] ?? [])[$region] ?? null;
    }

    /** @return \WeakMap<NativeComponent, array<string, array>> */
    protected static function fragments(): \WeakMap
    {
        return static::$fragments ??= new \WeakMap;
    }

    /** @return \WeakMap<NativeComponent, array<string, string>> */
    protected static function requested(): \WeakMap
    {
        return static::$requested ??= new \WeakMap;
    }

    /** @return \WeakMap<NativeComponent, array<string, true>> */
    protected static function refreshing(): \WeakMap
    {
        return static::$refreshing ??= new \WeakMap;
    }

    /** Scalars, null, and arrays of those, with no NaN or infinity. */
    protected static function jsonSafe(mixed $value): bool
    {
        if (is_float($value)) {
            return is_finite($value);
        }

        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! static::jsonSafe($item)) {
                return false;
            }
        }

        return true;
    }

    /** A `tree/json` body as a node list, or null when it is not one. */
    protected static function decode(string $json): ?array
    {
        $tree = json_decode($json, true);

        return is_array($tree) ? static::normalize($tree) : null;
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
