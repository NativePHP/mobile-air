<?php

namespace Native\Mobile\Edge\Web\Protocol;

use Illuminate\Http\Request;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Edge\Web\Bridge\WebBridge;
use Native\Mobile\Edge\Web\Renderer\WebRenderer;
use Native\Mobile\Testing\FakeBridge;

/**
 * POC web target for EDGE screens ("React Native Web" mode).
 *
 * Runs the same headless mount → render → dispatch cycle the testing
 * harness proved out, but stateless per HTTP request, Livewire-style:
 *
 *   GET  (Route::native fallthrough)  boot + mount + render
 *        → full HTML page with the rendered tree and a state snapshot.
 *   POST /_edge/update                boot + rehydrate snapshot (no
 *        mount — the snapshot already carries mount's state; only the
 *        {type:'lazy'} boot event runs the deferred mount),
 *        rebuild the CallbackRegistry from the wire state, dispatch the
 *        UI event through NativeComponent::dispatch(), render once
 *        → JSON {html, snapshot} (or {redirect}/{back} on a navigation
 *        intent).
 *
 * Callback ids are content-addressed (fnv1a32 of the expression string),
 * so there is no render-order dependence to replay: the snapshot carries
 * the registry's expression map (`callbacks`) and navigation configs
 * (`nav`), and update() re-registers them into a fresh registry before
 * dispatch — no pre-dispatch render frame for SCREEN-owned callbacks.
 * Child components (nested `<native:*>` component tags) are the one
 * exception: they mount DURING render and own their callbacks in
 * per-child registries, so the snapshot also carries a merged
 * `childCallbacks` map and update() runs one extra mounting render
 * before dispatching a child-owned event (see update()). FakeBridge
 * absorbs every bridge call so no native runtime is needed.
 *
 * The snapshot travels SEALED (EdgeSnapshot): `{data: {component, uri,
 * params, props, callbacks, childCallbacks, nav}, checksum}` with an HMAC over the whole
 * data unit, so the client can only echo it back verbatim — update()
 * trusts the sealed component/uri/params over anything else in the POST
 * body, and any tamper 419s before hydration. Props are typed-dehydrated
 * into the sealed data and re-hydrated on the way in (enums, datetimes,
 * Collections — see EdgeSnapshot::dehydrate()).
 *
 * screen() also answers a JSON navigation mode (header `X-Edge-Nav: 1`
 * or query `_edge_nav=1`) for the SPA client: `{html, state, title}`
 * instead of the full shell.
 *
 * Reactivity attributes map onto the request cycle like so: #[Computed]
 * and updatedX() hooks need nothing here (computed props resolve via
 * __get during the render frame; updated hooks fire inside
 * __syncProperty, which native:model dispatches as an ordinary
 * callback). #[Poll] / `native:poll` advertise their intervals in the
 * advisory `polls` state key and the client ticks a `{type: 'poll'}`
 * update; #[Lazy] screens serve their placeholder on GET (`lazy: true`,
 * no mount()) and the client immediately posts `{type: 'lazy'}` for the
 * real first frame — see update() for both string-typed events.
 */
class WebScreenRunner implements \Native\Mobile\Edge\Contracts\WebRunner
{
    public function screen(string $componentClass)
    {
        $path = '/'.ltrim(request()->path(), '/');
        $resolved = NativeRouter::resolve($path);
        $params = $resolved['params'] ?? [];
        $layout = $resolved['layout'] ?? null;

        $preview = request()->query('_platform');

        // #[Lazy] screens serve the placeholder frame WITHOUT running
        // mount() — mount is the slow part being deferred. The client sees
        // `lazy: true`, immediately POSTs a `{type: 'lazy'}` event, and
        // update()'s normal boot (which always mounts) produces the real
        // first frame.
        $lazy = static::isLazyClass($componentClass);

        $component = static::boot($componentClass, $params, $layout, $path, $preview, mount: ! $lazy);

        // mount() may redirect (auth gates) — honor it before rendering.
        // (A lazy screen never mounted here, so there is no intent yet;
        // a mount()-time redirect surfaces on the {type:'lazy'} update.)
        if (! $lazy) {
            if ($response = static::intentResponse($component->getNavigationIntent(), false)) {
                return $response;
            }
        }

        $tree = $lazy ? static::placeholderTree($component) : static::renderTree($component);

        \Native\Mobile\Edge\Recording\TreeRecorder::tree($tree, $path);

        $html = WebRenderer::render($tree);
        $title = static::title($component);
        $state = [
            'component' => $componentClass,
            'uri' => $path,
            'params' => $params,
            'snapshot' => $lazy
                ? static::sealedPlaceholderSnapshot($componentClass, $path, $params)
                : static::sealedSnapshot($component, $componentClass, $path, $params),
            'preview' => $preview,
            // Advisory poll intervals (ms) for the client's re-render
            // timers — deliberately OUTSIDE the sealed snapshot: a
            // tampered/forged poll tick can only do what any
            // {type:'poll'} update can (run #[Poll] methods + one
            // render), so the intervals are not security-relevant.
            // Empty while lazy: the placeholder must not poll — the
            // real intervals arrive on the {type:'lazy'} update response.
            'polls' => $lazy ? [] : static::pollIntervals($component),
            'lazy' => $lazy,
        ];

        // Client effects queued during mount/render (dialogs, vibrate,
        // open-url, …) — drained once, delivered with this response.
        $effects = WebBridge::current()?->drainEffects() ?? [];

        // SPA navigation mode: the client fetches the next screen as JSON
        // and swaps it in without a full page load. `state` is exactly
        // what would have gone into #edge-state (csrf included).
        if (request()->headers->get('X-Edge-Nav') === '1' || request()->query('_edge_nav') === '1') {
            return response()->json([
                'html' => $html,
                'state' => [...$state, 'csrf' => csrf_token(), 'endpoint' => EdgeEndpoint::updatePath(), 'uploadEndpoint' => EdgeEndpoint::uploadPath()],
                'title' => $title,
                'effects' => $effects,
            ]);
        }

        return response(WebShell::page(html: $html, state: [...$state, 'effects' => $effects], title: $title));
    }

    public function update(Request $request)
    {
        // Raw body, NOT $request->input(): the TransformsRequest middleware
        // (ConvertEmptyStringsToNull, TrimStrings) would mangle snapshot
        // values — an empty-string prop becomes null and fatals on typed
        // properties during rehydration.
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        // Verify the seal FIRST: everything security-relevant (component,
        // uri, params, props, callback/nav maps) lives inside the sealed
        // data — the top-level POST keys are ignored as untrusted echoes.
        // Tampered or missing snapshot → 419 right here.
        $data = EdgeSnapshot::unseal((array) ($payload['snapshot'] ?? []));

        $componentClass = (string) ($data['component'] ?? '');

        if (! is_subclass_of($componentClass, NativeComponent::class)
            || ! in_array($componentClass, static::registeredClasses(), true)) {
            abort(400, 'Unknown component.');
        }

        $path = '/'.ltrim((string) ($data['uri'] ?? '/'), '/');
        $params = (array) ($data['params'] ?? []);
        $resolved = NativeRouter::resolve($path);
        $layout = $resolved['layout'] ?? null;

        $props = (array) ($data['props'] ?? []);
        $callbacks = (array) ($data['callbacks'] ?? []);
        $childCallbacks = (array) ($data['childCallbacks'] ?? []);
        $navConfigs = (array) ($data['nav'] ?? []);

        $event = (array) ($payload['event'] ?? []);
        $callbackId = (int) ($event['callback_id'] ?? 0);
        $eventType = $event['type'] ?? null;

        // mount() runs on a screen's INITIAL request only — and for a
        // #[Lazy] screen that initial request is the {type:'lazy'} update
        // (its placeholder GET skipped mount). Every other update is a
        // rehydration: the sealed snapshot already carries the state
        // mount() produced, so re-running it would charge mount's cost
        // (queries, API calls — exactly what #[Lazy] exists to defer)
        // to every click and poll tick.
        $component = static::boot($componentClass, $params, $layout, $path, $payload['preview'] ?? null, mount: $eventType === 'lazy');

        static::applySnapshot($component, $props);

        // Rebuild the SCREEN's callback registry from the wire maps — no
        // render frame needed. Ids are content-addressed (fnv1a32 of the
        // expression), so re-registering each expression reproduces the
        // exact ids baked into the client's DOM; iterating in wire order
        // also replays any salt-rehashed collision identically. Child-owned
        // expressions (`childCallbacks`) are deliberately NOT registered
        // here: dispatch() resolves ownership via each owner's registry,
        // and putting a child's expression in the screen registry would
        // make the screen claim it (mis-dispatching on method-name
        // collisions, silently no-oping otherwise).
        static::scoped($component, function () use ($callbacks, $navConfigs) {
            /** @var NativeComponent $this */
            foreach ($callbacks as $expression => $id) {
                $recomputed = $this->nativeCallbacks->register((string) $expression);
                assert($recomputed === (int) $id, "EDGE callback id drift for '{$expression}'");
            }

            foreach ($navConfigs as $config) {
                if (is_array($config)) {
                    $this->nativeCallbacks->registerNavigation($config);
                }
            }
        });

        // String-typed PROTOCOL events ('poll' / 'lazy') carry no
        // callback_id and are handled here INSTEAD of dispatch() (whose
        // element event types are ints; a string type with id 0 would
        // just drop silently there):
        //
        //   'poll' — stateless web keeps no live instance between
        //     requests, so nothing tracks per-interval deadlines the way
        //     the device run loop does (runDuePolls() reschedules `next`
        //     on a persistent instance). Semantic difference, by design:
        //     EVERY poll tick invokes EVERY method-level #[Poll] method
        //     unconditionally, then falls through to the single render.
        //     Class-level #[Poll] and Blade `native:poll` need no method
        //     call — the re-render below IS their poll effect. A forged
        //     tick can only run methods the dev already declared to run
        //     unconditionally on a timer, so nothing beyond a normal
        //     update is reachable. Child-class #[Poll] methods don't run
        //     here — parity with device, where only the screen's run
        //     loop calls runDuePolls().
        //
        //   'lazy' — the placeholder GET skipped mount(); boot() above
        //     has just run the real mount() (and applySnapshot() was a
        //     no-op on the placeholder's empty props, so mount()'s values
        //     stand), so the event is satisfied by falling through to
        //     the render. A mount()-time navigation intent is honored by
        //     the intentResponse() check below, same as any dispatch.
        if ($eventType === 'poll' || $eventType === 'lazy') {
            \Native\Mobile\Edge\Recording\TreeRecorder::event($event, $eventType);

            if ($eventType === 'poll') {
                static::scoped($component, function () {
                    /** @var NativeComponent $this */
                    foreach ($this->pollDefinitions() as $def) {
                        if ($def['method'] !== null && method_exists($this, $def['method'])) {
                            $this->{$def['method']}();
                        }
                    }
                });
            }
        } else {
            // TWO dispatch paths, because child components only come into
            // existence DURING a render (mountChildComponent runs inside the
            // frame) and own their callbacks in per-child registries:
            //
            //   1. Screen-owned (the common case): the id resolves in the
            //      registry just rebuilt — dispatch with no pre-render, then
            //      render once. Fast path, identical to before.
            //   2. Child-owned: pre-render there is no owner to dispatch to
            //      (findCallbackOwner returns null — the child instances
            //      don't exist yet), so the event would drop silently. When
            //      the screen registry misses but the SEALED wire map says a
            //      descendant registered the id, run one mounting render
            //      first (output discarded): children remount and re-register
            //      their expressions, and because ids are expression hashes
            //      each child registry re-derives exactly the ids baked into
            //      the client DOM. dispatch() then walks to the owning child.
            //
            // Ids in neither map are stale/foreign and still drop safely
            // inside dispatch(), exactly as before.
            $screenOwned = static::scoped($component, function () use ($callbackId) {
                /** @var NativeComponent $this */
                return $this->nativeCallbacks->resolve($callbackId) !== null;
            });

            if (! $screenOwned && in_array($callbackId, array_map('intval', array_values($childCallbacks)), true)) {
                static::renderTree($component);
            }

            // Recording: annotate the event with its resolved method name so
            // the JSONL is self-describing (ids are deterministic but opaque).
            // Owner-aware: a child-owned id resolves through the child's
            // registry (children are mounted by now on that path).
            $method = static::scoped($component, function () use ($callbackId) {
                /** @var NativeComponent $this */
                $owner = $this->findCallbackOwner($callbackId);
                $cb = $owner === null ? null : $owner->nativeCallbacks->resolve($callbackId);

                return $cb['method'] ?? null;
            });
            \Native\Mobile\Edge\Recording\TreeRecorder::event($event, $method);

            static::scoped($component, function () use ($event) {
                /** @var NativeComponent $this */
                $this->dispatch($event);
            });
        }

        if ($response = static::intentResponse($component->getNavigationIntent(), true)) {
            \Native\Mobile\Edge\Recording\TreeRecorder::nav($response->getData(true));

            return $response;
        }

        $tree = static::renderTree($component);

        \Native\Mobile\Edge\Recording\TreeRecorder::tree($tree, $path);

        return response()->json([
            'html' => WebRenderer::render($tree),
            'snapshot' => static::sealedSnapshot($component, $componentClass, $path, $params),
            'effects' => WebBridge::current()?->drainEffects() ?? [],
            // Refreshed advisory intervals: Blade `native:poll` timers can
            // appear/disappear with state (conditional markup), and the
            // {type:'lazy'} update is where a lazy screen's intervals
            // surface for the first time — the client should resync its
            // timers to this list after every update.
            'polls' => static::pollIntervals($component),
        ]);
    }

    // ── Boot / render cycle ─────────────────────────

    protected static function boot(string $componentClass, array $params, ?string $layout, string $uri, ?string $preview = null, bool $mount = true): NativeComponent
    {
        // Real per-method web bridge (drivers + client effects). A test
        // that pre-bound a plain FakeBridge stays in charge — its scripted
        // responses must keep intercepting.
        $bridge = FakeBridge::current() ?? WebBridge::enable();
        NativeElementCollector::setWebMode(true);

        // Dev preview: `?_platform=mobile` makes @mobile/@web (System::
        // isMobile via Device.GetInfo) resolve as if on device, so the
        // mobile chrome can be eyeballed in a browser tab.
        WebRenderer::setMobilePreview($preview === 'mobile');

        if ($preview === 'mobile') {
            $bridge->respondTo('Device.GetInfo', [
                'info' => json_encode(['platform' => 'android', 'model' => 'Web Preview']),
            ]);
        }

        // Resolve platform-variant icons to their Android (Material) names —
        // they match the Material Symbols web font, so `:android` icon enums
        // render on web for free. (`ios:`/`android:` Tailwind variants are
        // unknown to browser Tailwind and drop out harmlessly.)
        \Native\Mobile\Platform::set(\Native\Mobile\Platform::ANDROID);

        /** @var NativeComponent $component */
        $component = new $componentClass;
        $component->setParams($params);
        $component->setData([]);

        if ($layout !== null) {
            $component->setLayout($layout);
        }

        // Attach a router with this screen on its stack so currentUri()-
        // driven chrome (tab highlighting etc.) behaves as on device.
        $router = new NativeRouter;
        \Closure::bind(function () use ($component, $uri, $params) {
            /** @var NativeRouter $this */
            $this->stack[] = ['component' => $component, 'uri' => $uri, 'params' => $params];
        }, $router, NativeRouter::class)();
        $component->setRouter($router);

        static::scoped($component, function () {
            /** @var NativeComponent $this */
            $this->nativeCallbacks ??= new CallbackRegistry;
            $this->registerNativeEventListeners();
        });

        // `$mount = false` is the #[Lazy] placeholder GET: everything above
        // (bridge, platform, router, callback registry, event listeners) is
        // still needed to render the placeholder, but mount() — the slow
        // work being deferred — is skipped until the {type:'lazy'} update.
        if ($mount) {
            $component->mount();
        }

        return $component;
    }

    /** One render frame → the wire tree array (every node emitted FULL). */
    protected static function renderTree(NativeComponent $component): array
    {
        return static::scoped($component, function () {
            /** @var NativeComponent $this */
            $this->nativeCallbacks->reset();
            $this->resetComputedCache();

            $element = $this->renderToElement();

            $nextId = 1;
            $emitted = [];
            $throwaway = [];

            return $element->toArray($this->nativeCallbacks, $nextId, '', 0, $emitted, $throwaway);
        });
    }

    // ── Polling (#[Poll] / native:poll) ─────────────

    /**
     * Effective poll intervals (ms) after a render frame: #[Poll]
     * attribute declarations (class- and method-level, via
     * pollDefinitions()) merged with the Blade `native:poll` timers the
     * frame just synced (syncBladePolls runs inside view()/fromView(),
     * so bladePollDeadlines reflects exactly the intervals present in
     * the markup this frame). Sorted unique — advisory only, lives
     * OUTSIDE the sealed snapshot (see the `polls` state key).
     */
    protected static function pollIntervals(NativeComponent $component): array
    {
        return static::scoped($component, function () {
            /** @var NativeComponent $this */
            $intervals = [];

            foreach ($this->pollDefinitions() as $def) {
                $intervals[] = (int) $def['ms'];
            }

            foreach (array_keys($this->bladePollDeadlines) as $ms) {
                $intervals[] = (int) $ms;
            }

            $intervals = array_values(array_unique(array_filter($intervals, fn ($ms) => $ms > 0)));
            sort($intervals);

            return $intervals;
        });
    }

    // ── Lazy placeholder (#[Lazy]) ──────────────────

    /** Whether the component class carries #[Lazy] — static check, no instance needed. */
    protected static function isLazyClass(string $componentClass): bool
    {
        return ! empty((new \ReflectionClass($componentClass))->getAttributes(\Native\Mobile\Attributes\Lazy::class));
    }

    /**
     * The placeholder frame for a #[Lazy] screen → wire tree array.
     * Mirrors renderTree() but renders placeholder() instead of the
     * render() cycle — the same builder publishPlaceholder() uses on
     * device, minus the nativephp_element_publish() call (web returns
     * trees, it never publishes). mount() has NOT run when this runs.
     */
    protected static function placeholderTree(NativeComponent $component): array
    {
        return static::scoped($component, function () {
            /** @var NativeComponent $this */
            $this->nativeCallbacks->reset();
            $this->resetComputedCache();

            $result = $this->placeholder();
            $element = $result instanceof \Illuminate\View\View
                ? $this->fromView($result)
                : $result;

            $nextId = 1;
            $emitted = [];
            $throwaway = [];

            return $element->toArray($this->nativeCallbacks, $nextId, '', 0, $emitted, $throwaway);
        });
    }

    // ── Snapshot (public props, Livewire-style) ─────

    protected static function snapshot(NativeComponent $component): array
    {
        return static::scoped($component, function () {
            /** @var NativeComponent $this */
            return $this->getPublicProperties();
        });
    }

    /**
     * The snapshot as sent over the wire, HMAC-sealed as one unit:
     *
     *   {data: {
     *      component: FQCN,
     *      uri:       string,
     *      params:    object,
     *      props:     object   (typed-dehydrated public props),
     *      callbacks: object   (SCREEN-owned expression => int id;
     *                           insertion order preserved — update()
     *                           re-registers in this order),
     *      childCallbacks:
     *                 object   (expression => int id merged from every
     *                           mounted child component's registry,
     *                           recursively; NOT re-registered into the
     *                           screen registry — update() only uses it
     *                           to recognize child-owned event ids and
     *                           take the mounting-render dispatch path),
     *      nav:       list     (navigation configs, screen + children;
     *                           keys recomputed content-addressed by
     *                           registerNavigation),
     *    }, checksum: hex}
     *
     * Registry maps are captured AFTER the render — which is exactly why
     * the child maps are available here at all: children mount during the
     * render frame. Because everything is inside one checksum, none of it
     * can be tampered with independently; update() 419s on any mismatch
     * (EdgeSnapshot::unseal()).
     */
    protected static function sealedSnapshot(NativeComponent $component, string $componentClass, string $uri, array $params): array
    {
        $props = [];
        foreach (static::snapshot($component) as $name => $value) {
            $props[$name] = EdgeSnapshot::dehydrate($value, $name);
        }

        [$callbacks, $childCallbacks, $nav] = static::scoped($component, function () {
            /** @var NativeComponent $this */
            $callbacks = $this->nativeCallbacks->expressions();
            $navByKey = $this->nativeCallbacks->navigations();

            // Child components pin their elements to their OWN per-child
            // registries (ownCallbacks), so their expressions never appear
            // in the screen registry. Walk every mounted descendant
            // (children can nest) and merge, so child-owned ids reach the
            // wire. Ids are content-addressed (fnv1a32 of the expression),
            // so the same expression carries the same id in every registry
            // and cross-registry merging is collision-consistent; an
            // expression the screen already owns stays screen-owned (that
            // matches dispatch(): findCallbackOwner checks self first).
            $childCallbacks = [];

            $walk = function (NativeComponent $c) use (&$walk, &$callbacks, &$childCallbacks, &$navByKey) {
                foreach ($c->nativeChildComponents as $child) {
                    foreach ($child->nativeCallbacks->expressions() as $expression => $id) {
                        assert(($callbacks[$expression] ?? $childCallbacks[$expression] ?? $id) === $id,
                            "EDGE cross-registry callback id divergence for '{$expression}'");

                        if (! isset($callbacks[$expression])) {
                            $childCallbacks[$expression] ??= $id;
                        }
                    }

                    foreach ($child->nativeCallbacks->navigations() as $key => $config) {
                        $navByKey[$key] ??= $config;
                    }

                    $walk($child);
                }
            };
            $walk($this);

            // A genuine fnv1a32 collision ACROSS registries (two different
            // expressions hashing to one id in registries that never saw
            // each other, so neither salt-rehashed) would make the id
            // ambiguous on the wire. ~1 in 2^31 per pair — assert so dev
            // surfaces it immediately.
            assert((function () use ($callbacks, $childCallbacks) {
                $seen = [];
                foreach ([$callbacks, $childCallbacks] as $map) {
                    foreach ($map as $expression => $id) {
                        if (isset($seen[$id]) && $seen[$id] !== $expression) {
                            return false;
                        }
                        $seen[$id] = $expression;
                    }
                }

                return true;
            })(), 'EDGE callback id collision across component registries');

            return [$callbacks, $childCallbacks, array_values($navByKey)];
        });

        return EdgeSnapshot::seal([
            'component' => $componentClass,
            'uri' => $uri,
            'params' => $params,
            'props' => $props,
            'callbacks' => $callbacks,
            'childCallbacks' => $childCallbacks,
            'nav' => $nav,
        ]);
    }

    /**
     * The sealed snapshot for a #[Lazy] placeholder GET: component/uri/
     * params identify the screen, but props and the callback/nav maps
     * are EMPTY — mount() hasn't run, so there is no state worth
     * echoing, and empty props make applySnapshot() a no-op on the
     * {type:'lazy'} update so the values mount() computes there stand
     * untouched (sealed defaults would clobber them). Placeholder-frame
     * callbacks are deliberately not carried either: the lazy update
     * renders the REAL frame, whose response re-registers everything
     * the swapped-in DOM references.
     */
    protected static function sealedPlaceholderSnapshot(string $componentClass, string $uri, array $params): array
    {
        return EdgeSnapshot::seal([
            'component' => $componentClass,
            'uri' => $uri,
            'params' => $params,
            'props' => [],
            'callbacks' => [],
            'childCallbacks' => [],
            'nav' => [],
        ]);
    }

    /**
     * Assign verified snapshot props back onto the component, reversing
     * the typed dehydration. #[Locked] props are restored like any other:
     * the HMAC seal is what prevents client tampering (see the Locked
     * attribute docblock), so no per-prop comparison is needed here.
     */
    protected static function applySnapshot(NativeComponent $component, array $props): void
    {
        $ref = new \ReflectionObject($component);

        foreach ($props as $key => $value) {
            if (! $ref->hasProperty($key)) {
                continue;
            }

            $prop = $ref->getProperty($key);

            if (! $prop->isPublic() || $prop->isStatic()) {
                continue;
            }

            try {
                $component->{$key} = EdgeSnapshot::hydrate($value);
            } catch (\TypeError) {
                // Snapshot value doesn't fit the property's declared type
                // (e.g. JSON turned a float into an int) — keep mount()'s
                // value rather than 500ing the whole update.
            }
        }
    }

    // ── Navigation intents → HTTP semantics ─────────

    protected static function intentResponse(?NavigationIntent $intent, bool $asJson)
    {
        if ($intent === null) {
            return null;
        }

        $payload = match ($intent->type) {
            NavigationIntent::BACK => ['back' => true],
            default => ['redirect' => $intent->uri ?? '/'],
        };

        if ($asJson) {
            return response()->json($payload);
        }

        return isset($payload['redirect']) ? redirect($payload['redirect']) : redirect('/');
    }

    /** Document <title> / SPA title: navTitle() with the app name as fallback. */
    protected static function title(NativeComponent $component): string
    {
        try {
            $title = trim((string) $component->navTitle());
        } catch (\Throwable) {
            $title = '';
        }

        return $title !== '' ? $title : (string) config('app.name', 'App');
    }

    // ── Helpers ─────────────────────────────────────

    /** Run a closure bound inside the component (private access), like the test harness. */
    protected static function scoped(NativeComponent $component, \Closure $fn): mixed
    {
        return \Closure::bind($fn, $component, NativeComponent::class)();
    }

    /** @return string[] component classes registered via Route::native() */
    protected static function registeredClasses(): array
    {
        $classes = [];

        foreach (NativeRouter::registeredRoutes() as $route) {
            if (is_array($route) && isset($route['class'])) {
                $classes[] = $route['class'];
            } elseif (is_string($route)) {
                $classes[] = $route;
            }
        }

        return $classes;
    }
}
