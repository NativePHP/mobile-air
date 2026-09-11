<?php

namespace Native\Mobile\Testing;

use Illuminate\Support\Traits\Macroable;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeDumpException;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Platform;
use Native\Mobile\Support\NativeCallbacks;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * Livewire-style test harness for NativeComponent screens.
 *
 *     Native::test(Dashboard::class)
 *         ->assertSee('Welcome back')
 *         ->tap('Refresh')
 *         ->assertSet('count', 1)
 *         ->tap('View profile')
 *         ->assertNavigatedTo('/profile/5');
 *
 * The harness drives the component's real lifecycle — mount, Blade/Element
 * render, callback dispatch, native-event dispatch, re-render — entirely
 * in-process. The published wire trees are captured by a FakeBridge instead
 * of shared memory, so no device or simulator is involved. Rendering
 * fidelity (SwiftUI / Compose) is intentionally out of scope, exactly as
 * Livewire's test harness asserts on rendered HTML rather than a browser.
 *
 * Interactions dispatch through the same code paths the on-device runloop
 * uses: taps resolve a callback id from the published tree and go through
 * NativeComponent::dispatch(); native events go through
 * dispatchNativeEvent(); after every interaction the component re-renders,
 * mirroring the render → wait → dispatch loop. Exceptions thrown by the
 * component bubble to the test instead of painting the error screen; a
 * dd() inside the component becomes a readable failure with the dumps.
 *
 * Flows mirror the router's live navigation stack: follow() pushes the
 * destination while the current screen stays alive underneath; goBack()
 * pops back onto it, firing onResume() and re-rendering with preserved
 * state — exactly what NativeRouter::loop() does on device.
 */
class TestableComponent
{
    // Plugins register element-specific test vocabulary here (a date plugin's
    // pickDate(), a chart plugin's assertSeries(), ...). FakeBridge macros
    // can't serve UI elements: the bridge holds no reference back to the
    // component, so a macro there can't read the tree or fire node events.
    //
    // Aliased because this class defines its own __call for FakeBridge
    // forwarding — a class method beats a trait method, so without the alias
    // the trait's __call would never run and macros would be invisible.
    use Macroable {
        __call as macroCall;
    }

    // Wire event type codes — must match EventType on the native side.
    public const EVENT_PRESS = 0;

    public const EVENT_LONG_PRESS = 1;

    public const EVENT_TEXT_CHANGE = 2;

    public const EVENT_TOGGLE_CHANGE = 3;

    public const EVENT_SUBMIT = 4;

    public const EVENT_SLIDER_CHANGE = 9;

    public const EVENT_CHECKBOX_CHANGE = 10;

    public const EVENT_RADIO_CHANGE = 11;

    public const EVENT_SELECT_CHANGE = 12;

    public const EVENT_TAB_CHANGE = 13;

    public const EVENT_SHEET_DISMISS = 14;

    /** Wire prop key(s) an event type dispatches through, for ref targeting. */
    protected const EVENT_CALLBACK_KEYS = [
        self::EVENT_PRESS => ['on_press'],
        self::EVENT_LONG_PRESS => ['on_long_press'],
        self::EVENT_TEXT_CHANGE => ['on_change', 'on_swipe'],
        self::EVENT_TOGGLE_CHANGE => ['on_change'],
        self::EVENT_SUBMIT => ['on_submit'],
        self::EVENT_SLIDER_CHANGE => ['on_change', 'on_pinch_end'],
        self::EVENT_CHECKBOX_CHANGE => ['on_change'],
        self::EVENT_RADIO_CHANGE => ['on_change'],
        self::EVENT_SELECT_CHANGE => ['on_change'],
        self::EVENT_TAB_CHANGE => ['on_change'],
        self::EVENT_SHEET_DISMISS => ['on_dismiss'],
    ];

    protected NativeComponent $component;

    /** The wire tree from the most recent publish this harness observed. */
    protected ?array $lastTree = null;

    protected FakeBridge $bridge;

    /** Platform for ios:/android: Tailwind variants (null = neither). */
    protected ?string $platform;

    /** The screen this one was pushed from (flow stack), if any. */
    protected ?TestableComponent $parent = null;

    /** True while a followed screen sits on top of this one. */
    protected bool $suspended = false;

    /** Frames this harness has rendered (excludes placeholder/final-state publishes). */
    protected int $frameCount = 0;

    /** Frame count snapshot taken when the last interaction started. */
    protected int $framesBeforeInteraction = 0;

    /** Per-test snapshot sequence numbers, keyed by file+test. */
    protected static array $snapshotSequence = [];

    // ── Entry points ────────────────────────────────

    /**
     * Mount a component class and render its first frame.
     *
     * @param  array  $params  Route parameters (read via $this->param()).
     * @param  array  $data  Navigation data (read via $this->data()).
     * @param  string|null  $layout  Layout class providing chrome; when
     *                               testing via visit() this comes from the
     *                               route registration automatically.
     * @param  string|null  $platform  'ios' or 'android' — activates the
     *                                 matching ios:/android: Tailwind
     *                                 variants for this render.
     */
    public static function test(string $componentClass, array $params = [], array $data = [], ?string $layout = null, ?string $platform = null): static
    {
        return new static($componentClass, $params, $data, $layout, $platform);
    }

    /**
     * Mount the component registered for a native route URI, with the
     * route's params and layout resolved exactly as navigation would.
     * Requires the app's Route::native() registrations to be loaded.
     */
    public static function visit(string $uri, array $data = [], ?string $platform = null): static
    {
        $resolved = NativeRouter::resolve($uri);

        Assert::assertNotNull(
            $resolved,
            "No native route registered for [{$uri}]. Register it with Route::native() or test the component class directly."
        );

        return new static($resolved['class'], $resolved['params'], $data, $resolved['layout'], $platform, $uri);
    }

    protected function __construct(string $componentClass, array $params, array $data, ?string $layout, ?string $platform = null, ?string $uri = null)
    {
        $this->bridge = FakeBridge::enable();
        $this->platform = $platform;

        // dd() in a component throws instead of killing the test process;
        // guard() converts it into a readable failure below.
        TestDumpHandler::register();

        // ios:/android: Tailwind variants. Always set (null clears a
        // previous test's platform); the parse cache isn't platform-keyed.
        TailwindParser::setPlatform($platform);
        TailwindParser::clearCache();

        // Same for platform-resolved icons (IconResolver & friends read
        // Platform::current()) — `platform:` means "pretend we're on that
        // OS" everywhere, not just for Tailwind variants.
        Platform::set($platform);

        $component = new $componentClass;

        if (! $component instanceof NativeComponent) {
            Assert::fail("[$componentClass] is not a NativeComponent.");
        }

        $this->component = $component;
        $component->setParams($params);
        $component->setData($data);

        if ($layout !== null) {
            $component->setLayout($layout);
        }

        // When the URI is known (visit()/follow()), attach a router with
        // this screen on its stack so currentUri()-driven chrome behaves
        // as on device — tab highlighting, per-URI diff props, etc.
        if ($uri !== null) {
            $router = new NativeRouter;
            \Closure::bind(function () use ($component, $uri, $params) {
                /** @var NativeRouter $this */
                $this->stack[] = ['component' => $component, 'uri' => $uri, 'params' => $params];
            }, $router, NativeRouter::class)();
            $component->setRouter($router);
        }

        // Same preamble the runloop performs before its first frame.
        $this->scoped(function () {
            /** @var NativeComponent $this */
            $this->nativeCallbacks ??= new CallbackRegistry;
            $this->registerNativeEventListeners();
        });

        $this->guard(function () use ($component) {
            // #[Lazy] components paint a placeholder before mount() on
            // device; keep that behavior so the publish is observable.
            $component->publishPlaceholder();

            $component->mount();

            // A redirect from mount() (e.g. an auth gate) skips the first
            // render, exactly like runLoop() honoring a pre-set intent.
            if ($component->getNavigationIntent() === null) {
                $this->renderFrame();
            } else {
                $this->lastTree = $this->bridge->lastPublish();
            }
        });
    }

    // ── State & interaction ─────────────────────────

    /**
     * Set a public property through the same model-binding path a native
     * input uses (__syncProperty), firing any updatedFoo() hook, then
     * re-render.
     */
    public function set(string $property, mixed $value): static
    {
        $this->startInteraction();

        Assert::assertTrue(
            property_exists($this->component, $property),
            'Public property [$'.$property.'] does not exist on '.get_class($this->component).'.'
        );

        $this->guard(fn () => $this->component->__syncProperty($property, $value));

        return $this->afterInteraction();
    }

    /** Call a method on the component directly, then re-render. */
    public function call(string $method, mixed ...$args): static
    {
        $this->startInteraction();

        Assert::assertTrue(
            method_exists($this->component, $method),
            'Method ['.$method.'] does not exist on '.get_class($this->component).'.'
        );

        $this->guard(fn () => $this->component->{$method}(...$args));

        return $this->afterInteraction();
    }

    /**
     * Tap the pressable element carrying the given `ref`, or — when no
     * ref matches — the nearest pressable whose subtree shows the given
     * visible text. Dispatches a real press event at its callback id.
     */
    public function tap(string $target): static
    {
        $this->startInteraction();

        $callbackId = $this->pressableIdByRef($this->tree(), $target)
            ?? $this->findPressableByText($this->tree(), $target);

        Assert::assertNotNull(
            $callbackId,
            "No pressable element with ref or visible text [{$target}] found in the rendered tree."
        );

        return $this->dispatchUiEvent(['type' => self::EVENT_PRESS, 'callback_id' => $callbackId]);
    }

    /** Press the element bound to a method name, expression, or ref. */
    public function press(string $target): static
    {
        return $this->fireEvent($target, self::EVENT_PRESS);
    }

    public function longPress(string $target): static
    {
        return $this->fireEvent($target, self::EVENT_LONG_PRESS);
    }

    /**
     * Touch-down on the element bound to a method name or ref
     * (`@tapDown`). Down/up ride the PRESS wire event with their own
     * callback ids in the props dict, so dispatch is a plain press at
     * the `on_press_down` id.
     */
    public function pressDown(string $target): static
    {
        return $this->firePropsPress($target, 'on_press_down');
    }

    /** Touch-up counterpart of pressDown() (`@tapUp`). */
    public function pressUp(string $target): static
    {
        return $this->firePropsPress($target, 'on_press_up');
    }

    /** Fire EVENT_PRESS at the callback id carried in the given props key. */
    protected function firePropsPress(string $target, string $key): static
    {
        $this->startInteraction();

        $callbackId = $this->callbackIdFor($target)
            ?? $this->propsCallbackIdByRef($this->tree(), $target, $key);

        Assert::assertNotNull(
            $callbackId,
            "No callback registered for [{$target}] in the last render. Registered: ".
            (implode(', ', array_keys($this->callbacks()->expressions())) ?: '(none)')
        );

        return $this->dispatchUiEvent(['type' => self::EVENT_PRESS, 'callback_id' => $callbackId]);
    }

    /**
     * Type into an input bound to a method, model property, or ref.
     *
     * When caret/selection offsets are given AND the target element
     * registered `@selectionChange` (an `on_selection_change` callback id
     * in its props), the harness also fires the selection event after the
     * text change — the two frames a device sends for one keystroke.
     * Offsets are Unicode code points; omitting `$selectionEnd` means a
     * collapsed caret at `$selectionStart`.
     */
    public function input(string $target, string $text, ?int $selectionStart = null, ?int $selectionEnd = null): static
    {
        // Resolve the selection callback from the CURRENT tree, before the
        // text change re-renders it (ids are stable across frames, so
        // dispatching after the re-render is safe).
        $selectionId = $selectionStart === null ? null : $this->selectionCallbackIdFor($target);

        $this->fireEvent($target, self::EVENT_TEXT_CHANGE, ['text' => $text]);

        // Mirror the runloop: once the text-change handler navigated away
        // or stopped the component, no further events are delivered.
        if ($selectionId !== null && $this->component->getNavigationIntent() === null && $this->isRunning()) {
            $this->startInteraction();

            return $this->dispatchUiEvent([
                'type' => self::EVENT_TEXT_CHANGE,
                'callback_id' => $selectionId,
                'text' => self::packSelection($text, $selectionStart, $selectionEnd),
            ]);
        }

        return $this;
    }

    /**
     * Move the caret (or select a range) in an input carrying
     * `@selectionChange`, without changing its text — fires only the
     * selection event. `$end` defaults to a collapsed caret at `$start`;
     * `$text` defaults to the element's current `value` prop when
     * resolvable, else ''.
     */
    public function moveCaret(string $target, int $start, ?int $end = null, ?string $text = null): static
    {
        $this->startInteraction();

        $node = $this->nodeFor($target);
        $callbackId = $node['props']['on_selection_change'] ?? null;
        $callbackId = is_int($callbackId) ? $callbackId : null;

        Assert::assertNotNull(
            $callbackId,
            "No @selectionChange callback registered for [{$target}] in the last render."
        );

        $text ??= (string) ($node['props']['value'] ?? '');

        return $this->dispatchUiEvent([
            'type' => self::EVENT_TEXT_CHANGE,
            'callback_id' => $callbackId,
            'text' => self::packSelection($text, $start, $end),
        ]);
    }

    public function submit(string $target, string $text = ''): static
    {
        return $this->fireEvent($target, self::EVENT_SUBMIT, ['text' => $text]);
    }

    public function toggle(string $target, bool $value): static
    {
        return $this->fireEvent($target, self::EVENT_TOGGLE_CHANGE, ['value' => $value]);
    }

    public function check(string $target, bool $value = true): static
    {
        return $this->fireEvent($target, self::EVENT_CHECKBOX_CHANGE, ['value' => $value]);
    }

    public function slide(string $target, float $value): static
    {
        return $this->fireEvent($target, self::EVENT_SLIDER_CHANGE, ['value' => $value]);
    }

    /**
     * Fire a gesture-area swipe bound to `@swipe`. Direction is one of
     * "left", "right", "up", "down" — delivered to the handler as a
     * string, exactly as the device sends it.
     */
    public function swipe(string $target, string $direction = 'left'): static
    {
        return $this->fireEvent($target, self::EVENT_TEXT_CHANGE, ['text' => $direction]);
    }

    /**
     * Fire a gesture-area pinch-end bound to `@pinchEnd`, delivering the
     * final scale factor (1.0 = identity) as a float.
     */
    public function pinch(string $target, float $scale): static
    {
        return $this->fireEvent($target, self::EVENT_SLIDER_CHANGE, ['value' => $scale]);
    }

    public function selectRadio(string $target, string $value): static
    {
        return $this->fireEvent($target, self::EVENT_RADIO_CHANGE, ['value' => $value]);
    }

    public function select(string $target, string $value): static
    {
        return $this->fireEvent($target, self::EVENT_SELECT_CHANGE, ['value' => $value]);
    }

    public function changeTab(string $target, int $index): static
    {
        return $this->fireEvent($target, self::EVENT_TAB_CHANGE, ['value' => $index]);
    }

    public function dismissSheet(string $target): static
    {
        return $this->fireEvent($target, self::EVENT_SHEET_DISMISS);
    }

    /**
     * Dispatch a wire event at the callback registered for $target — a
     * method name ('save'), a full expression ("save('draft')"), a
     * model-bound property name, or an element `ref`. This is the generic
     * primitive behind the input/toggle/slide/... sugar.
     */
    public function fireEvent(string $target, int $type, array $fields = []): static
    {
        $this->startInteraction();

        $callbackId = $this->callbackIdFor($target)
            ?? $this->callbackIdByRef($this->tree(), $target, $type);

        Assert::assertNotNull(
            $callbackId,
            "No callback registered for [{$target}] in the last render. Registered: ".
            (implode(', ', array_keys($this->callbacks()->expressions())) ?: '(none)')
        );

        return $this->dispatchUiEvent(['type' => $type, 'callback_id' => $callbackId, ...$fields]);
    }

    /**
     * Deliver a native event (wire type 20) — what the device sends when a
     * bridge API completes or a plugin pushes an event. Fires #[On]
     * listeners, fluent ->on() closures, and pending then()/catch()
     * callbacks, then re-renders.
     *
     *     ->emitNative(LocationUpdated::class, ['latitude' => 40.7, ...])
     */
    public function emitNative(string $event, array $payload = []): static
    {
        $this->startInteraction();

        $this->guard(fn () => $this->scoped(function () use ($event, $payload) {
            /** @var NativeComponent $this */
            $this->dispatchNativeEvent(['event' => $event, 'payload' => $payload]);
        }));

        return $this->afterInteraction();
    }

    /** Simulate the system back gesture / hardware back button. */
    public function pressBack(): static
    {
        $this->startInteraction();

        $this->guard(fn () => $this->component->handleSystemBack());

        return $this->afterInteraction();
    }

    /**
     * Fire every #[Poll]-declared method immediately (regardless of its
     * interval) and re-render — the deterministic equivalent of an idle
     * tick where all timers came due. Blade `native:poll` timers carry no
     * method; the re-render itself is their effect.
     */
    public function firePolls(): static
    {
        $this->startInteraction();

        $this->guard(fn () => $this->scoped(function () {
            /** @var NativeComponent $this */
            foreach ($this->pollDefinitions() as $def) {
                if ($def['method'] !== null && method_exists($this, $def['method'])) {
                    $this->{$def['method']}();
                }
            }
        }));

        return $this->afterInteraction();
    }

    /** Fire a single #[Poll] method by name, then re-render. */
    public function firePoll(string $method): static
    {
        $this->startInteraction();

        $defined = $this->scoped(function () {
            /** @var NativeComponent $this */
            return array_values(array_filter(array_column($this->pollDefinitions(), 'method')));
        });

        Assert::assertContains(
            $method,
            $defined,
            "No #[Poll] method [{$method}] on ".get_class($this->component).'. Declared: '.(implode(', ', $defined) ?: '(none)')
        );

        $this->guard(fn () => $this->component->{$method}());

        return $this->afterInteraction();
    }

    /**
     * Run a search query through the screen's onSearchQuery() handler —
     * the same capture the runloop performs for `search_query`-kinded
     * callbacks — and stage the results for the next frame's corpus.
     */
    public function search(string $query): static
    {
        $this->startInteraction();

        $overridden = $this->scoped(function () {
            /** @var NativeComponent $this */
            return $this->hasOnSearchQueryOverride();
        });

        Assert::assertTrue(
            $overridden,
            get_class($this->component).' does not override onSearchQuery() — there is no dynamic search to drive.'
        );

        $this->guard(fn () => $this->scoped(function () use ($query) {
            /** @var NativeComponent $this */
            $result = $this->onSearchQuery($query);
            if (is_array($result)) {
                $this->pendingSearchResults = array_values($result);
            }
        }));

        return $this->afterInteraction();
    }

    /** The staged results from the last search() call. */
    public function searchResults(): array
    {
        return $this->scoped(function () {
            /** @var NativeComponent $this */
            return $this->pendingSearchResults ?? [];
        });
    }

    // ── Flows ───────────────────────────────────────

    /**
     * Continue a flow across screens: resolve the pending navigate/replace
     * intent through the route registry and return a new harness mounted on
     * the destination — carrying the intent's data, params, and layout.
     *
     * Mirrors the router: on navigate the current screen stays ALIVE
     * underneath (goBack() returns to it, state intact, onResume() fired);
     * on replace it unmounts and drops out of the stack.
     */
    public function followNavigation(): static
    {
        $intent = $this->component->getNavigationIntent();

        Assert::assertNotNull($intent, 'No navigation occurred — there is nothing to follow.');
        Assert::assertContains(
            $intent->type,
            [NavigationIntent::NAVIGATE, NavigationIntent::REPLACE],
            "Cannot follow a [{$intent->type}] intent — only navigate/replace push a new screen."
        );

        $resolved = NativeRouter::resolve($intent->uri);

        Assert::assertNotNull(
            $resolved,
            "Navigated to [{$intent->uri}], but no native route is registered for it (it would exit to the web)."
        );

        // The router consumes the intent so a resumed screen doesn't
        // re-fire it (NativeRouter::loop does exactly this).
        $this->component->resetNavigationIntent();

        $next = new static($resolved['class'], $resolved['params'], $intent->data, $resolved['layout'], $this->platform, $intent->uri);

        if ($intent->type === NavigationIntent::REPLACE) {
            // Replaced screens leave the stack entirely.
            $this->component->unmount();
            $next->parent = $this->parent;
            $this->suspended = true;
        } else {
            $next->parent = $this;
            $this->suspended = true;
        }

        return $next;
    }

    /** Alias for followNavigation(). */
    public function follow(): static
    {
        return $this->followNavigation();
    }

    /**
     * Pop this screen and return to the one below it in the flow — the
     * previous harness with its LIVE component, resumed exactly as the
     * router resumes it: intent consumed, onResume() fired, re-rendered.
     * Triggers the back press itself if no back intent is pending yet.
     */
    public function goBack(): TestableComponent
    {
        if ($this->component->getNavigationIntent() === null) {
            $this->guard(fn () => $this->component->handleSystemBack());
        }

        $intent = $this->component->getNavigationIntent();

        Assert::assertNotNull($intent, 'Back press did not produce a navigation intent — the screen handled it internally.');
        Assert::assertSame(
            NavigationIntent::BACK,
            $intent->type,
            "Expected a back navigation, got [{$intent->type} → {$intent->uri}]. Use follow() for forward navigation."
        );

        Assert::assertNotNull(
            $this->parent,
            'No previous screen in this flow. Build the stack with follow(), or use pressBack()->assertWentBack() on a standalone screen.'
        );

        $this->component->unmount();
        $this->suspended = true;

        $this->parent->resumeFromFlow();

        return $this->parent;
    }

    /** Assert which component class this harness is currently driving. */
    public function assertScreen(string $componentClass): static
    {
        Assert::assertSame(
            $componentClass,
            get_class($this->component),
            'Expected to be on ['.$componentClass.'], but this screen is ['.get_class($this->component).'].'
        );

        return $this;
    }

    /** Router-style resume after the screen above popped. @internal */
    protected function resumeFromFlow(): void
    {
        $this->suspended = false;
        $this->framesBeforeInteraction = $this->frameCount;

        $this->scoped(function () {
            /** @var NativeComponent $this */
            $this->nativeRunning = true;
        });

        $this->guard(function () {
            $this->component->onResume();
            $this->renderFrame();
        });
    }

    // ── Assertions ──────────────────────────────────

    /** Assert the given text appears anywhere in the rendered wire tree. */
    public function assertSee(string $text): static
    {
        Assert::assertTrue(
            $this->treeContainsText($this->tree(), $text),
            "Did not see [{$text}] in the rendered tree.\nVisible text: ".implode(' | ', $this->collectText($this->tree()))
        );

        return $this;
    }

    public function assertDontSee(string $text): static
    {
        Assert::assertFalse(
            $this->treeContainsText($this->tree(), $text),
            "Saw unexpected [{$text}] in the rendered tree."
        );

        return $this;
    }

    /** Assert a public or #[Computed] property's current value. */
    public function assertSet(string $property, mixed $expected): static
    {
        Assert::assertEquals(
            $expected,
            $this->get($property),
            "Property [\${$property}] does not match."
        );

        return $this;
    }

    public function assertNotSet(string $property, mixed $value): static
    {
        Assert::assertNotEquals($value, $this->get($property), "Property [\${$property}] unexpectedly matches.");

        return $this;
    }

    /**
     * Assert an element of the given wire type exists — optionally
     * narrowed by a matcher receiving the wire node array.
     *
     *     ->assertElement('toggle', fn ($n) => ($n['props']['value'] ?? null) === true)
     */
    public function assertElement(string $type, ?callable $matcher = null): static
    {
        Assert::assertTrue(
            $this->findElement($this->tree(), $type, $matcher) !== null,
            "No [{$type}] element".($matcher ? ' matching the given callback' : '').' found in the rendered tree.'
        );

        return $this;
    }

    public function assertMissingElement(string $type, ?callable $matcher = null): static
    {
        Assert::assertNull(
            $this->findElement($this->tree(), $type, $matcher),
            "Unexpected [{$type}] element found in the rendered tree."
        );

        return $this;
    }

    public function assertNavigatedTo(string $uri): static
    {
        return $this->assertIntent(NavigationIntent::NAVIGATE, $uri);
    }

    public function assertReplacedWith(string $uri): static
    {
        return $this->assertIntent(NavigationIntent::REPLACE, $uri);
    }

    public function assertWentBack(): static
    {
        return $this->assertIntent(NavigationIntent::BACK);
    }

    public function assertExitedToWeb(string $uri): static
    {
        return $this->assertIntent(NavigationIntent::EXIT_WEB, $uri);
    }

    /**
     * Assert the pending navigation carries the given transition. Accepts a
     * Transition case or its backing string value (e.g. 'slide_from_bottom').
     */
    public function assertTransition(Transition|string $transition): static
    {
        $expected = $transition instanceof Transition ? $transition : Transition::from($transition);

        $intent = $this->component->getNavigationIntent();

        Assert::assertNotNull($intent, 'Expected a navigation carrying a transition, but none occurred.');

        $actual = $intent->transition instanceof Transition
            ? $intent->transition
            : ($intent->transition !== null ? Transition::from($intent->transition) : null);

        Assert::assertSame(
            $expected,
            $actual,
            "Expected transition [{$expected->value}], got [".($actual?->value ?? 'none').'].'
        );

        return $this;
    }

    public function assertNoNavigation(): static
    {
        $intent = $this->component->getNavigationIntent();

        Assert::assertNull(
            $intent,
            'Expected no navigation, but the component intends to ['.($intent?->type ?? '').' → '.($intent?->uri ?? '').'].'
        );

        return $this;
    }

    /** Assert a native bridge API was invoked (e.g. 'Haptics.Vibrate'). */
    public function assertNativeCalled(string $method, ?callable $paramsFilter = null): static
    {
        $this->bridge->assertCalled($method, $paramsFilter);

        return $this;
    }

    public function assertNativeNotCalled(string $method): static
    {
        $this->bridge->assertNotCalled($method);

        return $this;
    }

    public function assertNativeCalledTimes(string $method, int $times): static
    {
        $this->bridge->assertCalledTimes($method, $times);

        return $this;
    }

    /** Assert bridge methods were called in this (not necessarily contiguous) order. */
    public function assertNativeCallOrder(array $methods): static
    {
        $this->bridge->assertCallOrder($methods);

        return $this;
    }

    /**
     * Assert a fluent callback is registered and waiting for this native
     * event — i.e. the component actually chained ->photoTaken(...) /
     * ->locationReceived(...) on its bridge call.
     */
    public function assertAwaitingNativeEvent(string $eventClass): static
    {
        Assert::assertNotNull(
            NativeCallbacks::resolveByEvent($eventClass),
            "No pending native callback awaits [{$eventClass}]. Did the component chain the fluent handler onto its bridge call?"
        );

        return $this;
    }

    /** Inverse of assertAwaitingNativeEvent — e.g. after the one-shot fired. */
    public function assertNotAwaitingNativeEvent(string $eventClass): static
    {
        Assert::assertNull(
            NativeCallbacks::resolveByEvent($eventClass),
            "A pending native callback still awaits [{$eventClass}] — expected it to be consumed."
        );

        return $this;
    }

    // ── Render-count assertions ─────────────────────

    /** Frames this harness has rendered so far (initial mount = 1). */
    public function renderCount(): int
    {
        return $this->frameCount;
    }

    public function assertRenderCount(int $count): static
    {
        Assert::assertSame(
            $count,
            $this->frameCount,
            "Expected exactly {$count} rendered frame(s), got {$this->frameCount}."
        );

        return $this;
    }

    /** Assert the last interaction caused a re-render. */
    public function assertRerendered(): static
    {
        Assert::assertGreaterThan(
            $this->framesBeforeInteraction,
            $this->frameCount,
            'Expected the last interaction to re-render, but no new frame was published.'
        );

        return $this;
    }

    public function assertNotRerendered(): static
    {
        Assert::assertSame(
            $this->framesBeforeInteraction,
            $this->frameCount,
            'Expected no re-render, but the last interaction published a new frame.'
        );

        return $this;
    }

    // ── Chrome assertions ───────────────────────────

    /** Assert the navigation bar title, across all chrome paths. */
    public function assertNavTitle(string $title): static
    {
        $titles = $this->navTitles($this->tree());

        Assert::assertContains(
            $title,
            $titles,
            "Nav title [{$title}] not found. Titles present: ".(implode(', ', $titles) ?: '(none — does the screen have a layout with a NavBar?)')
        );

        return $this;
    }

    /** Assert the screen renders native tab chrome at all. */
    public function assertHasTabBar(): static
    {
        Assert::assertNotNull(
            $this->findElement($this->tree(), 'native_root_tabs'),
            'No native tab chrome rendered — the layout provides no TabBar (or does not use native chrome).'
        );

        return $this;
    }

    public function assertTabBarHidden(): static
    {
        $tabs = $this->findElement($this->tree(), 'native_root_tabs');

        Assert::assertNotNull($tabs, 'No native tab chrome rendered — nothing to be hidden.');
        Assert::assertTrue(
            (bool) ($tabs['props']['hide_tab_bar'] ?? false),
            'Expected the tab bar to be hidden on this screen, but hide_tab_bar is not set.'
        );

        return $this;
    }

    public function assertTabBarVisible(): static
    {
        $tabs = $this->findElement($this->tree(), 'native_root_tabs');

        Assert::assertNotNull($tabs, 'No native tab chrome rendered.');
        Assert::assertFalse(
            (bool) ($tabs['props']['hide_tab_bar'] ?? false),
            'Expected the tab bar to be visible, but hide_tab_bar is set.'
        );

        return $this;
    }

    /**
     * Assert the nav bar is hidden on this screen (`$hidesNavBar` /
     * `navigationOptions()->hidden()`), across both chrome paths: on the
     * native-chrome path the sentinel carries `hide_nav_bar`; on the
     * custom-Column path the bar is simply not rendered.
     */
    public function assertNavBarHidden(): static
    {
        $root = $this->findElement($this->tree(), 'native_root_tabs')
            ?? $this->findElement($this->tree(), 'native_root_stack');

        if ($root !== null) {
            Assert::assertTrue(
                (bool) ($root['props']['hide_nav_bar'] ?? false),
                'Expected the nav bar to be hidden on this screen, but hide_nav_bar is not set.'
            );

            return $this;
        }

        Assert::assertNull(
            $this->findElement($this->tree(), 'top_bar'),
            'Expected the nav bar to be hidden on this screen, but a top_bar element was rendered.'
        );

        return $this;
    }

    public function assertNavBarVisible(): static
    {
        $root = $this->findElement($this->tree(), 'native_root_tabs')
            ?? $this->findElement($this->tree(), 'native_root_stack');

        if ($root !== null) {
            Assert::assertFalse(
                (bool) ($root['props']['hide_nav_bar'] ?? false),
                'Expected the nav bar to be visible, but hide_nav_bar is set.'
            );

            return $this;
        }

        Assert::assertNotNull(
            $this->findElement($this->tree(), 'top_bar'),
            'Expected a visible nav bar, but no chrome rendered one — does the screen have a layout with a NavBar?'
        );

        return $this;
    }

    /** Assert a tab with the given label exists in the tab bar. */
    public function assertHasTab(string $label): static
    {
        Assert::assertNotNull(
            $this->findElement($this->tree(), 'bottom_nav_item', fn ($n) => ($n['props']['label'] ?? null) === $label),
            "No tab labelled [{$label}] found. Tabs present: ".implode(', ', $this->tabLabels())
        );

        return $this;
    }

    /** Assert the tab with the given label is the active one. */
    public function assertTabActive(string $label): static
    {
        $this->assertHasTab($label);

        Assert::assertNotNull(
            $this->findElement(
                $this->tree(),
                'bottom_nav_item',
                fn ($n) => ($n['props']['label'] ?? null) === $label && (bool) ($n['props']['active'] ?? false)
            ),
            "Tab [{$label}] exists but is not active."
        );

        return $this;
    }

    // ── Accessibility assertions ────────────────────

    /**
     * Assert the rendered tree contains no screen-reader accessibility
     * violations. Walks the wire tree the way the a11y audit rules read
     * it — the same props VoiceOver/TalkBack get:
     *
     *   - icon-only buttons, chips, tabs, and top-bar actions without an
     *     `a11y-label`
     *   - clickable icons and images without `a11y-label` / `alt`
     *   - pressables with neither visible text nor an `a11y-label`
     *   - gesture areas without an `a11y-label`
     *   - form controls (inputs, toggle, checkbox, radio, select, slider)
     *     with no label, placeholder, or `a11y-label`
     *   - list items whose trailing icon button has no `trailing-a11y-label`
     *
     * The failure message lists every violation with the offending node's
     * identity (ref, icon name, or nearest text) so it can be located in
     * the Blade view.
     */
    public function assertAccessible(): static
    {
        $violations = $this->accessibilityViolations();

        Assert::assertTrue(
            $violations === [],
            "Accessibility violations in the rendered tree:\n  - ".implode("\n  - ", $violations)
                ."\nAdd a11y-label / alt / trailing-a11y-label attributes (see the native-ui Accessibility docs)."
        );

        return $this;
    }

    /**
     * The a11y violations in the current tree, as human-readable strings.
     * Public so tests can allow-list known exceptions before asserting.
     */
    public function accessibilityViolations(): array
    {
        $violations = [];
        $this->collectA11yViolations($this->tree(), $violations);

        return $violations;
    }

    /** Apply the audit rules to one node, then recurse. */
    protected function collectA11yViolations(array $node, array &$violations): void
    {
        $type = $node['type'] ?? '';
        $props = $node['props'] ?? [];

        $prop = fn (string $key): string => is_string($props[$key] ?? null) ? $props[$key] : '';
        $hasA11yLabel = $prop('a11y_label') !== '';
        $interactive = false;

        foreach (['on_press', 'on_long_press', 'on_double_tap'] as $key) {
            if (is_int($node[$key] ?? $props[$key] ?? null)) {
                $interactive = true;
                break;
            }
        }

        $where = $this->a11yNodeIdentity($node);

        switch ($type) {
            case 'button':
                $iconOnly = $prop('label') === ''
                    && ($prop('leading_icon') !== '' || $prop('trailing_icon') !== '');
                if ($iconOnly && ! $hasA11yLabel) {
                    $violations[] = "icon-only <button>{$where} has no a11y-label";
                }
                break;

            case 'chip':
            case 'tab':
            case 'top_bar_action':
                // Divider sentinels render as separator lines, not buttons.
                if (! empty($props['divider'])) {
                    break;
                }
                if ($prop('label') === '' && $prop('title') === '' && ! $hasA11yLabel) {
                    $violations[] = "icon-only <{$type}>{$where} has no a11y-label";
                }
                break;

            case 'icon':
                if ($interactive && ! $hasA11yLabel) {
                    $violations[] = "clickable <icon>{$where} has no a11y-label";
                }
                break;

            case 'image':
                if ($interactive && $prop('alt') === '' && ! $hasA11yLabel) {
                    $violations[] = "clickable <image>{$where} has no alt text";
                }
                break;

            case 'pressable':
                if ($interactive && ! $hasA11yLabel
                    && $this->collectText($node) === []
                    && ! $this->subtreeHasA11yContent($node)) {
                    $violations[] = "<pressable>{$where} has neither visible text nor an a11y-label";
                }
                break;

            case 'gesture_area':
                if (! $hasA11yLabel) {
                    $violations[] = "<gesture_area>{$where} has no a11y-label";
                }
                break;

            case 'text_input':
            case 'bare_text_input':
            case 'filled_text_input':
            case 'outlined_text_input':
                if ($prop('label') === '' && $prop('placeholder') === '' && ! $hasA11yLabel) {
                    $violations[] = "<{$type}>{$where} has no label, placeholder, or a11y-label";
                }
                break;

            case 'toggle':
            case 'checkbox':
            case 'radio':
            case 'select':
            case 'slider':
            case 'radio_group':
                if ($prop('label') === '' && ! $hasA11yLabel) {
                    $violations[] = "<{$type}>{$where} has no label or a11y-label";
                }
                break;

            case 'list_item':
                if ($prop('trailing_type') === 'icon_button' && $prop('trailing_a11y_label') === '') {
                    $violations[] = "<list_item>{$where} trailing icon button has no trailing-a11y-label";
                }
                break;
        }

        foreach ($node['children'] ?? [] as $child) {
            $this->collectA11yViolations($child, $violations);
        }
    }

    /** True when any descendant carries an a11y_label or alt — the
     *  container is announced through its child (e.g. a pressable
     *  wrapping an image with alt text). */
    protected function subtreeHasA11yContent(array $node): bool
    {
        foreach ($node['children'] ?? [] as $child) {
            $props = $child['props'] ?? [];
            if (($props['a11y_label'] ?? '') !== '' || ($props['alt'] ?? '') !== '') {
                return true;
            }
            if ($this->subtreeHasA11yContent($child)) {
                return true;
            }
        }

        return false;
    }

    /** A short locator for a violating node: ref, icon, or nearby text. */
    protected function a11yNodeIdentity(array $node): string
    {
        $props = $node['props'] ?? [];

        if (is_string($node['ref'] ?? null) && $node['ref'] !== '') {
            return " [ref={$node['ref']}]";
        }

        foreach (['leading_icon', 'trailing_icon', 'name', 'icon', 'src', 'headline', 'trailing_value'] as $key) {
            if (is_string($props[$key] ?? null) && $props[$key] !== '') {
                return " [{$key}={$props[$key]}]";
            }
        }

        $text = $this->collectText($node);

        return $text === [] ? '' : ' [near "'.substr($text[0], 0, 40).'"]';
    }

    // ── Snapshots ───────────────────────────────────

    /**
     * Assert the current wire tree matches its committed snapshot.
     * Volatile fields (node ids, content hashes) are stripped and
     * callback ids are replaced with their registered expressions, so
     * snapshots are stable across runs and readable in review.
     *
     * First run writes the snapshot (tests/__snapshots__/<file>/<test>.json)
     * and passes; set UPDATE_SNAPSHOTS=1 to rewrite after intended changes.
     */
    public function assertMatchesSnapshot(?string $name = null): static
    {
        $normalized = $this->normalizeForSnapshot($this->tree());
        $path = $this->snapshotPath($name);

        if (! is_file($path) || getenv('UPDATE_SNAPSHOTS')) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            Assert::assertTrue(true); // snapshot (re)created — counts as an assertion

            return $this;
        }

        $expected = json_decode(file_get_contents($path), true);

        Assert::assertEquals(
            $expected,
            $normalized,
            "Wire tree does not match snapshot [{$path}]. Run with UPDATE_SNAPSHOTS=1 if the change is intended."
        );

        return $this;
    }

    // ── Inspection ──────────────────────────────────

    /** The live component instance. */
    public function instance(): NativeComponent
    {
        return $this->component;
    }

    /** Read a public or #[Computed] property. */
    public function get(string $property): mixed
    {
        return $this->component->{$property};
    }

    /** The most recently published wire tree. */
    public function tree(): array
    {
        Assert::assertNotNull($this->lastTree, 'The component has not published a frame.');

        return $this->lastTree;
    }

    /** The FakeBridge capturing this test's native traffic. */
    public function bridge(): FakeBridge
    {
        return $this->bridge;
    }

    public function navigationIntent(): ?NavigationIntent
    {
        return $this->component->getNavigationIntent();
    }

    /** Dump the current wire tree (debug aid). */
    public function dumpTree(): static
    {
        dump($this->tree());

        return $this;
    }

    // ── Bridge delegation ───────────────────────────

    /**
     * Forward unknown methods to this test's FakeBridge — its built-in
     * helpers and any macros plugins registered (e.g. a clipboard plugin's
     * assertCopied()). When the bridge answers fluently (returns itself),
     * the harness returns $this instead so the test chain stays on the
     * component.
     */
    public function __call(string $method, array $arguments): mixed
    {
        // Component-level macros win over bridge forwarding — they're the
        // more specific registration, and a plugin naming one after a bridge
        // method means it wants the component behaviour.
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $arguments);
        }

        if (FakeBridge::hasMacro($method) || method_exists($this->bridge, $method)) {
            $result = $this->bridge->{$method}(...$arguments);

            return $result === $this->bridge ? $this : $result;
        }

        throw new \BadMethodCallException(sprintf(
            'Method %s::%s does not exist, and the FakeBridge has no method or macro named [%s].',
            static::class,
            $method,
            $method
        ));
    }

    // ── Internals ───────────────────────────────────

    /**
     * Run a closure with $this bound to the component at NativeComponent
     * scope, granting the harness access to the same private lifecycle
     * units the runloop uses — without widening the production API.
     */
    protected function scoped(\Closure $fn): mixed
    {
        return \Closure::bind($fn, $this->component, NativeComponent::class)();
    }

    /**
     * Convert a component dd() into a readable test failure instead of a
     * raw exception (or a dead PHPUnit process).
     */
    protected function guard(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (NativeDumpException $e) {
            Assert::fail(
                'Component called dd() at '.$e->getSourceFile().':'.$e->getSourceLine()."\n".$e->getFormattedDumps()
            );
        }
    }

    protected function callbacks(): CallbackRegistry
    {
        return $this->scoped(function () {
            /** @var NativeComponent $this */
            return $this->nativeCallbacks;
        });
    }

    /**
     * One render → publish cycle: exactly what a runloop iteration does
     * before blocking on the next event (minus the error screen — render
     * exceptions bubble to the test).
     */
    protected function renderFrame(): void
    {
        $this->lastTree = $this->scoped(function () {
            /** @var NativeComponent $this */
            $this->nativeCallbacks->reset();
            $this->resetComputedCache();

            $element = $this->renderToElement();
            $tree = $this->memoizedToArray($element);

            nativephp_element_publish($tree);

            return $tree;
        });

        $this->frameCount++;
    }

    /** Dispatch a UI event through NativeComponent::dispatch(). */
    protected function dispatchUiEvent(array $event): static
    {
        $this->guard(fn () => $this->scoped(function () use ($event) {
            /** @var NativeComponent $this */
            $this->dispatch($event);
        }));

        return $this->afterInteraction();
    }

    /** Interaction preamble: activity guard + render-count snapshot. */
    protected function startInteraction(): void
    {
        $this->ensureActive();
        $this->framesBeforeInteraction = $this->frameCount;
    }

    /**
     * Post-interaction re-render, mirroring the runloop: a component that
     * set a navigation intent has stopped (and may have published a farewell
     * frame as part of that navigation); otherwise render the next frame.
     */
    protected function afterInteraction(): static
    {
        if ($this->component->getNavigationIntent() === null && $this->isRunning()) {
            $this->guard(fn () => $this->renderFrame());
        } else {
            $this->lastTree = $this->bridge->lastPublish() ?? $this->lastTree;
        }

        return $this;
    }

    protected function isRunning(): bool
    {
        return $this->scoped(function () {
            /** @var NativeComponent $this */
            return $this->nativeRunning;
        });
    }

    protected function ensureActive(): void
    {
        if ($this->suspended) {
            Assert::fail(
                'Cannot interact: this screen is suspended beneath a followed screen. Interact with the harness follow() returned, or goBack() to it.'
            );
        }

        $intent = $this->component->getNavigationIntent();

        if ($intent !== null) {
            Assert::fail(
                "Cannot interact: the component navigated away [{$intent->type} → {$intent->uri}]. ".
                'Use followNavigation() to continue the flow on the next screen.'
            );
        }
    }

    /**
     * Resolve a target to a callback id from the last render. Accepts a
     * full expression ("save('draft')"), a bare method name ('save'), or
     * a model-bound property name ('query' → __syncProperty binding).
     * Searches the screen's registry first, then every mounted child
     * component's — dispatch routes the event to the owning instance.
     */
    protected function callbackIdFor(string $target): ?int
    {
        foreach ($this->componentRegistries() as $registry) {
            if (($id = $registry->lookup($target)) !== null) {
                return $id;
            }

            foreach ($registry->expressions() as $expression => $id) {
                if (str_starts_with($expression, $target.'(')
                    || str_starts_with($expression, "__syncProperty('{$target}'")) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * The screen's CallbackRegistry plus every mounted child component's,
     * breadth-first down the child tree.
     *
     * @return array<int, CallbackRegistry>
     */
    protected function componentRegistries(): array
    {
        return $this->scoped(function () {
            /** @var NativeComponent $this */
            $registries = [];
            $queue = [$this];

            while ($queue !== []) {
                $component = array_shift($queue);
                if (isset($component->nativeCallbacks)) {
                    $registries[] = $component->nativeCallbacks;
                }
                foreach ($component->nativeChildComponents as $child) {
                    $queue[] = $child;
                }
            }

            return $registries;
        });
    }

    /** Press callback id of the node with the given ref, if any. */
    protected function pressableIdByRef(array $node, string $ref): ?int
    {
        return $this->callbackIdByRef($node, $ref, self::EVENT_PRESS);
    }

    /**
     * Callback id in a specific props key on the node with the given ref —
     * for props-dict callbacks that share a wire event type (press-down/up
     * both ride EVENT_PRESS), where EVENT_CALLBACK_KEYS can't discriminate.
     */
    protected function propsCallbackIdByRef(array $node, string $ref, string $key): ?int
    {
        if (($node['ref'] ?? null) === $ref) {
            $id = $node['props'][$key] ?? null;

            return is_int($id) ? $id : null;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($id = $this->propsCallbackIdByRef($child, $ref, $key)) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Find the node carrying `ref` and return the callback id the given
     * event type dispatches through — node-level (on_press/on_long_press)
     * or in the props map, wherever the element put it.
     */
    protected function callbackIdByRef(array $node, string $ref, int $type): ?int
    {
        if (($node['ref'] ?? null) === $ref) {
            foreach (self::EVENT_CALLBACK_KEYS[$type] ?? [] as $key) {
                $id = $node[$key] ?? $node['props'][$key] ?? null;
                if (is_int($id)) {
                    return $id;
                }
            }

            return null;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($id = $this->callbackIdByRef($child, $ref, $type)) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Pack a caret/selection payload for the TEXT_CHANGE wire frame:
     * "{start},{end}\x1F{text}" — header before the first U+001F unit
     * separator, exactly as the device sends it. A well-behaved device
     * never sends negative or inverted offsets, so the harness normalizes
     * them here; dispatch() additionally clamps to the text's code-point
     * length.
     */
    protected static function packSelection(string $text, int $start, ?int $end): string
    {
        $start = max(0, $start);
        $end = max($start, $end ?? $start);

        return "{$start},{$end}\x1F{$text}";
    }

    /** The target element's `on_selection_change` callback id, if any. */
    protected function selectionCallbackIdFor(string $target): ?int
    {
        $id = $this->nodeFor($target)['props']['on_selection_change'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * The rendered node a target resolves to: the node carrying `ref` ===
     * target, else the node owning the callback id the target's method /
     * expression / model binding registered.
     */
    protected function nodeFor(string $target): ?array
    {
        if (($node = $this->nodeByRef($this->tree(), $target)) !== null) {
            return $node;
        }

        if (($id = $this->callbackIdFor($target)) !== null) {
            return $this->nodeOwningCallback($this->tree(), $id);
        }

        return null;
    }

    /** The node in the subtree carrying the given ref, if any. */
    protected function nodeByRef(array $node, string $ref): ?array
    {
        if (($node['ref'] ?? null) === $ref) {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($found = $this->nodeByRef($child, $ref)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The node whose callback entries (node-level `on_*` keys or the
     * props map) carry the given callback id, wherever the element put it.
     */
    protected function nodeOwningCallback(array $node, int $id): ?array
    {
        foreach ([$node, $node['props'] ?? []] as $bag) {
            foreach ($bag as $key => $value) {
                if (is_string($key) && str_starts_with($key, 'on_') && $value === $id) {
                    return $node;
                }
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($found = $this->nodeOwningCallback($child, $id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Find the innermost node carrying a press callback (node-level or in
     * props, e.g. buttons) whose subtree contains the given visible text.
     */
    protected function findPressableByText(array $node, string $text): ?int
    {
        // Prefer a match deeper in the tree — the button itself rather
        // than a pressable ancestor wrapping half the screen.
        foreach ($node['children'] ?? [] as $child) {
            if (($id = $this->findPressableByText($child, $text)) !== null) {
                return $id;
            }
        }

        $pressId = $node['on_press'] ?? $node['props']['on_press'] ?? null;

        if (is_int($pressId) && $this->treeContainsText($node, $text)) {
            return $pressId;
        }

        return null;
    }

    protected function findElement(array $node, string $type, ?callable $matcher = null): ?array
    {
        if (($node['type'] ?? null) === $type && ($matcher === null || $matcher($node) === true)) {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($found = $this->findElement($child, $type, $matcher)) !== null) {
                return $found;
            }
        }

        return null;
    }

    protected function treeContainsText(array $node, string $text): bool
    {
        foreach ($this->collectText($node) as $value) {
            if (str_contains($value, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prop keys whose values are user-visible / announced text. collectText()
     * harvests only these, so string props that merely configure appearance
     * (colors, icon names, font names) don't count as "visible text" for
     * assertSee(), tap()-by-text, or the a11y audit — a styled icon-only
     * pressable used to slip past the audit because its `dark_bg_color`
     * string satisfied the visible-text check.
     *
     * The *_json keys carry serialized swipe-action / badge specs whose
     * `label` fields are user-visible; the whole blob is searched so
     * assertSee('Archive') keeps matching swipe actions.
     *
     * @var list<string>
     */
    protected const TEXT_PROP_KEYS = [
        'text', 'label', 'title', 'subtitle', 'placeholder', 'value',
        'alt', 'a11y_label', 'a11y_hint', 'headline', 'supporting',
        'overline', 'heading', 'options', 'leading_value', 'trailing_value',
        'nav_title', 'nav_subtitle', 'search_placeholder', 'nav_search_placeholder',
        'leading_actions_json', 'trailing_actions_json', 'trailing_badges_json',
    ];

    /** Every announced-text prop value in the subtree (visible text, titles, …). */
    protected function collectText(array $node, array &$found = []): array
    {
        $walkProps = function ($value) use (&$walkProps, &$found): void {
            if (is_string($value) && $value !== '') {
                $found[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $inner) {
                    $walkProps($inner);
                }
            }
        };

        foreach (self::TEXT_PROP_KEYS as $key) {
            if (isset($node['props'][$key])) {
                $walkProps($node['props'][$key]);
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            $this->collectText($child, $found);
        }

        return $found;
    }

    protected function assertIntent(string $type, ?string $uri = null): static
    {
        $intent = $this->component->getNavigationIntent();

        Assert::assertNotNull($intent, "Expected a [{$type}] navigation, but none occurred.");
        Assert::assertSame($type, $intent->type, "Expected a [{$type}] navigation, got [{$intent->type}].");

        if ($uri !== null) {
            Assert::assertSame($uri, $intent->uri, "Expected navigation to [{$uri}], got [{$intent->uri}].");
        }

        return $this;
    }

    /** Nav titles across the three chrome paths. */
    protected function navTitles(array $node, array &$titles = []): array
    {
        $type = $node['type'] ?? null;

        $title = match ($type) {
            'native_root_stack', 'top_bar' => $node['props']['title'] ?? null,
            'native_root_tabs' => $node['props']['nav_title'] ?? null,
            default => null,
        };

        if (is_string($title) && $title !== '') {
            $titles[] = $title;
        }

        foreach ($node['children'] ?? [] as $child) {
            $this->navTitles($child, $titles);
        }

        return $titles;
    }

    protected function tabLabels(): array
    {
        $labels = [];
        $walk = function (array $node) use (&$walk, &$labels): void {
            if (($node['type'] ?? null) === 'bottom_nav_item' && isset($node['props']['label'])) {
                $labels[] = $node['props']['label'];
            }
            foreach ($node['children'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($this->tree());

        return $labels;
    }

    /** Strip volatile fields; replace callback ids with their expressions. */
    protected function normalizeForSnapshot(array $node): array
    {
        $idToExpression = array_flip($this->callbacks()->expressions());

        $walk = function (array $node) use (&$walk, $idToExpression): array {
            unset($node['id'], $node['_hash']);

            foreach (['on_press', 'on_long_press'] as $key) {
                if (isset($node[$key]) && is_int($node[$key])) {
                    $node[$key] = '@'.($idToExpression[$node[$key]] ?? 'callback');
                }
            }

            foreach ($node['props'] ?? [] as $key => $value) {
                if (str_starts_with($key, 'on_') && is_int($value)) {
                    $node['props'][$key] = '@'.($idToExpression[$value] ?? 'callback');
                }
            }

            if (isset($node['children'])) {
                $node['children'] = array_map($walk, $node['children']);
            }

            return $node;
        };

        return $walk($node);
    }

    /** Snapshot file path derived from the calling test file + test name. */
    protected function snapshotPath(?string $name): string
    {
        $testFile = null;
        $testName = 'snapshot';

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? '';
            if ($testFile === null
                && $file !== ''
                && str_contains($file, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)
                && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                $testFile = $file;
            }

            $object = $frame['object'] ?? null;
            if ($object instanceof TestCase) {
                $testName = method_exists($object, 'name') ? $object->name() : $object->getName();
                break;
            }
        }

        Assert::assertNotNull($testFile, 'Could not locate the calling test file for snapshot storage.');

        $testName = preg_replace('/^__pest_evaluable_/', '', $testName);
        $slug = trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name ?? $testName), '-');

        // Multiple unnamed snapshots in one test get a sequence suffix.
        if ($name === null) {
            $key = $testFile.'::'.$testName;
            $seq = static::$snapshotSequence[$key] = (static::$snapshotSequence[$key] ?? 0) + 1;
            if ($seq > 1) {
                $slug .= '-'.$seq;
            }
        }

        return dirname($testFile)
            .DIRECTORY_SEPARATOR.'__snapshots__'
            .DIRECTORY_SEPARATOR.pathinfo($testFile, PATHINFO_FILENAME)
            .DIRECTORY_SEPARATOR.$slug.'.json';
    }
}
