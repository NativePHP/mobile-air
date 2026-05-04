<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Attributes\OnNative;
use Symfony\Component\VarDumper\VarDumper;

abstract class NativeComponent
{
    const EVENT_HOT_RELOAD = 15;

    const EVENT_NATIVE = 20;

    private static bool $dumpHandlerRegistered = false;

    private ?NativeDumpException $dumpException = null;

    private ?\Throwable $errorException = null;

    private int $overlayFontSize = 12;

    private array $overlayCallbackIds = [];

    /** @var array<string, string> event name → method name */
    private array $nativeEventListeners = [];

    protected CallbackRegistry $callbacks;

    protected bool $hasError = false;

    protected bool $running = true;

    protected ?NavigationIntent $navigationIntent = null;

    protected ?NativeRouter $router = null;

    protected array $params = [];

    protected array $navigationData = [];

    /** Layout class for this screen (set by router from route metadata). */
    protected ?string $layout = null;

    /** Imperative navbar overrides — merged onto layout's NavBar at render time. */
    protected array $pendingNavBarState = [];

    /** Imperative tabbar overrides — merged onto layout's TabBar at render time. */
    protected array $pendingTabBarState = [];

    public function render(): Element
    {
        return $this->view(static::inferViewName());
    }

    /**
     * Infer the Blade view name from the class name, Livewire-style.
     * e.g. Explore → explore, UserProfile → user-profile
     */
    public static function inferViewName(): string
    {
        return \Illuminate\Support\Str::kebab(class_basename(static::class));
    }

    /**
     * Override to render directly into the C flat buffer via streaming.
     * Call streamView() here instead of view().
     * Return true to skip legacy toArray/publish path.
     */
    protected function renderStreaming(): bool
    {
        return false;
    }

    protected function view(string $name, array $data = []): Element
    {
        $viewData = array_merge($this->getPublicProperties(), $data);

        NativeElementCollector::reset();
        NativeElementCollector::setCallbacks($this->callbacks);

        $this->renderBladeBoundToSelf("native.{$name}", $viewData);

        $content = NativeElementCollector::collect();

        return $this->wrapWithChrome($content);
    }

    /**
     * Wrap the screen's element tree with chrome from its layout.
     *
     * - Looks up the layout class declared by the route or the component.
     * - Asks the layout for a NavBar / TabBar.
     * - Merges in the screen's navigationOptions() and pendingNavBarState.
     * - Skips chrome the screen overrode inline in its blade
     *   (i.e., a top-level <native:top-bar> or <native:bottom-nav>).
     * - Wraps everything in a Column.fill().safeArea() with the chrome
     *   stacked above and below the content.
     */
    protected function wrapWithChrome(Element $content): Element
    {
        if ($this->layout === null || ! class_exists($this->layout)) {
            return $content;
        }

        /** @var \Native\Mobile\Edge\Layouts\NativeLayout $layout */
        $layout = new ($this->layout)();

        // If the screen blade already contains TopBar / BottomNav at the
        // root level, the dev took manual control — skip layout chrome
        // for those slots.
        $hasInlineNavBar = $this->treeContainsType($content, 'top_bar');
        $hasInlineTabBar = $this->treeContainsType($content, 'bottom_nav');

        $navBar = null;
        if (! $hasInlineNavBar) {
            $navBar = $layout->navBar($this);
            if ($navBar !== null) {
                $navBar->mergeOptions($this->navigationOptions());
                if (! empty($this->pendingNavBarState)) {
                    $navBar->mergeState($this->pendingNavBarState);
                }
            }
        }

        $tabBar = null;
        if (! $hasInlineTabBar) {
            $tabBar = $layout->tabBar($this);
            if ($tabBar !== null) {
                $currentUri = $this->router?->currentUri();
                if ($currentUri !== null) {
                    $tabBar->highlight($currentUri);
                }
            }
        }

        // Nothing to inject — return the screen content untouched.
        if ($navBar === null && $tabBar === null) {
            return $content;
        }

        // Native chrome path: when a layout opts in via
        // `usesNativeChrome()`, emit a `native_root_*` sentinel element
        // carrying the bar config as serialized props instead of a
        // Column of [navBar, content, tabBar]. The native iOS / Android
        // renderers for those types take over and use NavigationStack /
        // TabView / NavHost / Scaffold to render chrome system-natively.
        if ($layout->usesNativeChrome()) {
            return $this->wrapWithNativeChrome($content, $navBar, $tabBar, $layout);
        }

        // Pick the right safe-area variant based on which bars own which
        // edges. When a TabBar exists at the bottom, it handles its own
        // home-indicator inset internally so its bg can reach the screen
        // edge — the wrapper frees the bottom edge by using `safeAreaTop()`.
        // Same logic mirrored for the top edge when a NavBar exists.
        // When both bars exist, the wrapper applies neither edge — both
        // bars handle their own.
        $wrapper = \Native\Mobile\Edge\Elements\Column::make()->fill();
        if ($navBar !== null && $tabBar === null) {
            $wrapper->safeAreaBottom();   // navBar owns top, wrapper owns bottom
        } elseif ($tabBar !== null && $navBar === null) {
            $wrapper->safeAreaTop();      // tabBar owns bottom, wrapper owns top
        } elseif ($navBar === null && $tabBar === null) {
            $wrapper->safeArea();         // no chrome — wrapper handles both
        }
        // Both bars present: neither edge applied at the wrapper level.

        if ($navBar !== null) {
            $wrapper->addChild($navBar->toElement());
        }

        // Force the content slot to flex-grow so it gets a bounded height
        // (= screen − chrome) inside the wrapper column. Without this, a
        // SwiftUI ScrollView at the blade root reports its intrinsic content
        // height, FlexContainer gives it that much, and scrolling never
        // engages because viewport == content.
        //
        // NOTE: do NOT also apply ->fillWidth() here. fillWidth maps to
        // .frame(maxWidth: .infinity), which eats SwiftUI's height proposal
        // on the way through to the inner ScrollView and re-creates the
        // "no scroll" symptom. flex-grow alone is enough — FlexContainer's
        // place(at:proposal:) gives the content the right height directly.
        $content->flexGrow(1);
        $wrapper->addChild($content);

        if ($tabBar !== null) {
            $wrapper->addChild($tabBar->toElement());
        }

        return $wrapper;
    }

    /**
     * Native-chrome path. Emits a `NativeRootStack` or `NativeRootTabs`
     * sentinel element instead of a custom Column-of-bars layout. The
     * iOS / Android renderers for those types route to NavigationStack /
     * TabView / NavHost / Scaffold.
     *
     * Layout, in either case:
     *   - Bar config serialized as flat element props
     *   - Tabs (when present) emitted as `bottom_nav_item` children
     *   - NavBar actions (when present) emitted as `top_bar_action` children
     *   - Screen content appended as the final child
     */
    protected function wrapWithNativeChrome(
        Element $content,
        ?\Native\Mobile\Edge\Layouts\Builders\NavBar $navBar,
        ?\Native\Mobile\Edge\Layouts\Builders\TabBar $tabBar,
        \Native\Mobile\Edge\Layouts\NativeLayout $layout,
    ): Element {
        if ($tabBar !== null) {
            $root = \Native\Mobile\Edge\Elements\NativeRootTabs::make();
            $attrs = $tabBar->toRootProps();

            // Fold NavBar config in via nav-prefixed keys when both exist
            // (each tab hosts its own NavigationStack natively).
            if ($navBar !== null) {
                foreach ($navBar->toRootProps() as $key => $value) {
                    $attrs['nav' . ucfirst($key)] = $value;
                }
            }
            // Active tab's screen URI — used by the iOS bridge's per-URI
            // diff to keep tab-switch animations smooth.
            $attrs['currentUri'] = $this->router?->currentUri() ?? '';
            $root->applyAttributes($attrs);

            // Tab items as bottom_nav_item children.
            foreach ($tabBar->getTabs() as $tab) {
                $root->addChild($tab->toElement());
            }
            // NavBar actions (if any) as top_bar_action children.
            if ($navBar !== null) {
                foreach ($navBar->getActions() as $action) {
                    $root->addChild($action->toElement());
                }
            }
            // Optional persistent accessory pinned above the tab bar
            // (Apple's MiniPlayer pattern). Wrapped in a `TabAccessory`
            // marker element so the renderer can pick it out of children
            // alongside tabs and screen content.
            $accessory = $layout->tabBarAccessory($this);
            if ($accessory !== null) {
                $wrapper = \Native\Mobile\Edge\Elements\TabAccessory::make();
                $wrapper->addChild($accessory);
                $root->addChild($wrapper);
            }
            // Optional bottom-pinned content (chat input, search bar,
            // contextual menu). Wrapped in a `BottomBar` marker so the
            // renderer can pick it out and pin via `.safeAreaInset(.bottom)`.
            $bottomBar = $layout->bottomBar($this);
            if ($bottomBar !== null) {
                $bottomWrapper = \Native\Mobile\Edge\Elements\BottomBar::make();
                $bottomWrapper->addChild($bottomBar);
                $root->addChild($bottomWrapper);
            }
            // Active screen content as the final child.
            $root->addChild($content);

            return $root;
        }

        if ($navBar !== null) {
            $root = \Native\Mobile\Edge\Elements\NativeRootStack::make();
            $attrs = $navBar->toRootProps();
            // The per-URI tree cache on iOS keys off this so the
            // NavigationCoordinator can route push / pop / no-op
            // correctly across publishes.
            $attrs['currentUri'] = $this->router?->currentUri() ?? '';
            $root->applyAttributes($attrs);
            foreach ($navBar->getActions() as $action) {
                $root->addChild($action->toElement());
            }
            // Optional bottom-pinned content — same shape as the tabs
            // path above so a stack-only layout (no tab bar) can still
            // pin a chat input / search bar above the keyboard.
            $bottomBar = $layout->bottomBar($this);
            if ($bottomBar !== null) {
                $bottomWrapper = \Native\Mobile\Edge\Elements\BottomBar::make();
                $bottomWrapper->addChild($bottomBar);
                $root->addChild($bottomWrapper);
            }
            $root->addChild($content);

            return $root;
        }

        return $content;
    }

    /**
     * Walk the root element (and one level of children if it's an implicit
     * Column wrapper) looking for an element of $type.
     *
     * NativeElementCollector::collect() wraps multi-root trees in an
     * implicit Column, so checking only $tree's direct children doesn't
     * catch top-level <native:top-bar> when the blade also has siblings.
     */
    protected function treeContainsType(Element $tree, string $type): bool
    {
        if ($tree->getType() === $type) {
            return true;
        }
        foreach ($tree->getChildren() as $child) {
            if ($child->getType() === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Override to provide a default screen title that the layout's
     * NavBar can read.
     */
    public function navTitle(): string
    {
        return '';
    }

    /**
     * Override to provide structured per-screen NavBar overrides that
     * merge onto the layout's NavBar.
     */
    public function navigationOptions(): ?\Native\Mobile\Edge\Layouts\Builders\NavBarOptions
    {
        return null;
    }

    /**
     * Imperative override: mutate the navbar at any time during the
     * runloop. The next render reads the merged result.
     */
    public function setNavBar(array $options): void
    {
        $this->pendingNavBarState = array_merge($this->pendingNavBarState, $options);
    }

    /**
     * Imperative override: mutate the tabbar.
     */
    public function setTabBar(array $options): void
    {
        $this->pendingTabBarState = array_merge($this->pendingTabBarState, $options);
    }

    /**
     * Set by the router from the resolved route's metadata so the
     * component knows which layout class wraps it.
     */
    public function setLayout(?string $layoutClass): void
    {
        $this->layout = $layoutClass;
    }

    /**
     * Render a Blade view with `$this` bound to the component instance, so
     * templates can call methods and read properties on the component
     * directly — same convenience Livewire components offer.
     *
     * Mirrors what Livewire's `ExtendedCompilerEngine::evaluatePath()` does
     * for its components, but applies it to NativeComponent renders. Without
     * this, `$this` evaluates to the view engine (or nothing in some paths)
     * and bare `$this->property` from the blade fails with "Using $this when
     * not in object context."
     */
    private function renderBladeBoundToSelf(string $name, array $data): void
    {
        // `view()` with no args returns the Factory itself; `view($name, $data)`
        // returns a View. We need the View to access its engine + path.
        $view = view($name, $data);
        $engine = $view->getEngine();

        if (! $engine instanceof \Illuminate\View\Engines\CompilerEngine) {
            // Non-blade engine — fall back to the standard render path.
            // $this won't be bound, but at least the view still runs.
            $view->render();

            return;
        }

        $compiler = $engine->getCompiler();
        $bladePath = $view->getPath();

        if ($compiler->isExpired($bladePath)) {
            $compiler->compile($bladePath);
        }
        $compiledPath = $compiler->getCompiledPath($bladePath);

        // Use the View's full data set — Factory injects `$__env` and other
        // helpers compiled views depend on (`@include`, `@yield`, the loop
        // stack, etc.). Skipping that produces "Undefined variable $__env".
        $viewData = $view->gatherData();

        // Closure::bind ties `$this` inside the include to the component
        // instance and grants access to protected/private members via the
        // class-scope second argument.
        \Closure::bind(function () use ($compiledPath, $viewData) {
            extract($viewData, EXTR_SKIP);
            include $compiledPath;
        }, $this, static::class)();
    }

    /**
     * Streaming view — renders Blade directly into C flat buffer.
     * No Element objects, no toArray(), no intermediate PHP arrays.
     */
    protected function streamView(string $name, array $data = []): void
    {
        $viewData = array_merge($this->getPublicProperties(), $data);

        NativeElementCollector::setCallbacks($this->callbacks);
        NativeElementCollector::setStreaming(true);

        nphp_frame_begin();

        try {
            $t0 = microtime(true);
            $this->renderBladeBoundToSelf("native.{$name}", $viewData);
            $t1 = microtime(true);

            nphp_frame_end();
            $t2 = microtime(true);

            NativeRouter::debugLog(sprintf(
                'PERF streamView(%s) blade=%.1fms frame_end=%.1fms total=%.1fms',
                $name, ($t1 - $t0) * 1000, ($t2 - $t1) * 1000, ($t2 - $t0) * 1000
            ));
        } finally {
            NativeElementCollector::setStreaming(false);
        }
    }

    private function getPublicProperties(): array
    {
        $reflect = new \ReflectionClass($this);
        $props = [];

        foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic()) {
                $props[$prop->getName()] = $prop->getValue($this);
            }
        }

        return $props;
    }

    /**
     * Scan this component's methods for #[OnNative] attributes
     * and build the event name → method map.
     */
    private function registerNativeEventListeners(): void
    {
        $reflect = new \ReflectionClass($this);

        foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(OnNative::class);

            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $this->nativeEventListeners[$instance->event] = $method->getName();
            }
        }
    }

    /**
     * Handle a native event (type 20) by looking up #[OnNative] listeners.
     */
    protected function dispatchNativeEvent(array $event): void
    {
        $eventName = $event['event'] ?? '';
        $payload = $event['payload'] ?? [];

        $method = $this->nativeEventListeners[$eventName]
            ?? $this->nativeEventListeners['native:'.$eventName]
            ?? null;

        if ($method === null || ! method_exists($this, $method)) {
            return;
        }

        if (is_array($payload)) {
            $reflect = new \ReflectionMethod($this, $method);
            $args = [];
            foreach ($reflect->getParameters() as $param) {
                $name = $param->getName();
                if (array_key_exists($name, $payload)) {
                    $value = $payload[$name];

                    // Coerce the value to match the parameter's type hint
                    $type = $param->getType();
                    if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                        $value = match ($type->getName()) {
                            'int' => (int) $value,
                            'float' => (float) $value,
                            'string' => (string) $value,
                            'bool' => (bool) $value,
                            default => $value,
                        };
                    }

                    $args[] = $value;
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                }
            }
            $this->$method(...$args);
        } else {
            $this->$method($payload);
        }
    }

    public function mount(): void
    {
        //
    }

    public function unmount(): void
    {
        //
    }

    public function onResume(): void
    {
        //
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Called when the device back button is pressed.
     * Override in subclasses for custom behavior.
     * Default: navigate back.
     */
    public function onBackPressed(): void
    {
        $this->back();
    }

    public static function registerDumpHandler(): void
    {
        if (self::$dumpHandlerRegistered) {
            return;
        }

        self::$dumpHandlerRegistered = true;

        VarDumper::setHandler(function ($var) {
            $trace = debug_backtrace(0, 10);

            $ddFrame = null;
            foreach ($trace as $frame) {
                if (($frame['function'] ?? '') === 'dd') {
                    $ddFrame = $frame;
                    break;
                }
            }

            if ($ddFrame !== null) {
                // dd() call — grab all args and throw immediately
                $args = $ddFrame['args'] ?? [$var];
                $file = $ddFrame['file'] ?? 'unknown';
                $line = $ddFrame['line'] ?? 0;

                throw new NativeDumpException($args, $file, $line);
            }

            // Plain dump() call — log to file without throwing
            $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner;
            $dumper = new \Symfony\Component\VarDumper\Dumper\CliDumper;
            $dumper->setColors(false);

            $data = $cloner->cloneVar($var);
            $formatted = $dumper->dump($data, true);

            $logPath = storage_path('logs/edge-nav.log');
            @file_put_contents($logPath, "[dump] " . $formatted . "\n", FILE_APPEND);
        });
    }

    /**
     * Full standalone lifecycle — init, mount, loop, unmount, shutdown.
     * Used when running without the NativeRouter.
     */
    public function run(): void
    {
        static::registerDumpHandler();

        $this->callbacks = new CallbackRegistry;
        $this->registerNativeEventListeners();

        nativephp_element_init();

        try {
            $this->mount();
        } catch (NativeDumpException $e) {
            $this->renderDumpScreen($e);
        } catch (\Throwable $e) {
            NativeRouter::debugLog("mount() FAILED in " . static::class . ": " . $e->getMessage());
            $this->renderErrorScreen($e);
        }

        while ($this->running) {
            $this->callbacks->reset();

            if (! $this->hasError) {
                try {
                    if (! $this->renderStreaming()) {
                        $element = $this->render();
                        $tree = $element->toArray($this->callbacks);
                        nativephp_element_publish($tree);
                    }
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog("render() FAILED in " . static::class . ": " . $e->getMessage());
                    $this->renderErrorScreen($e);
                }
            }

            $event = nativephp_element_wait_event(-1);

            if ($event === null) {
                continue;
            }

            // Hot reload: write restart signal and exit so Kotlin re-executes with fresh PHP
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->flushCompiledViews();
                $uri = '/'.ltrim(request()->path(), '/');
                @file_put_contents(
                    storage_path('framework/.hot_restart'),
                    json_encode(['uri' => $uri, 'ts' => time()])
                );
                $this->stop();

                continue;
            }

            // Native event from bridge function — dispatch to #[OnNative] listeners
            if (($event['type'] ?? -1) === self::EVENT_NATIVE) {
                try {
                    $this->dispatchNativeEvent($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog("dispatchNativeEvent() FAILED in " . static::class . ": " . $e->getMessage());
                    $this->renderErrorScreen($e);
                }
                continue;
            }

            // Don't dispatch UI events while showing the error/dump screen
            // (except overlay controls like font size buttons)
            if (! $this->hasError) {
                try {
                    $this->dispatch($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog("dispatch() FAILED in " . static::class . ": " . $e->getMessage());
                    $this->renderErrorScreen($e);
                }
            } elseif (in_array($event['callback_id'] ?? 0, $this->overlayCallbackIds)) {
                $this->dispatch($event);
            }
        }

        $this->unmount();

        nativephp_element_shutdown();
    }

    /**
     * Just the render/event loop — no init/shutdown.
     * Used by NativeRouter for hot-swap navigation.
     */
    public function runLoop(): void
    {
        static::registerDumpHandler();

        $this->callbacks ??= new CallbackRegistry;
        $this->running = true;
        $this->navigationIntent = null;

        if (empty($this->nativeEventListeners)) {
            $this->registerNativeEventListeners();
        }

        while ($this->running) {
            $this->callbacks->reset();

            if (! $this->hasError) {
                try {
                    $t0 = microtime(true);

                    if ($this->renderStreaming()) {
                        // Explicit streaming path
                        $this->router?->flushDeferredTransition();
                        $t3 = microtime(true);
                        NativeRouter::debugLog(sprintf(
                            'PERF [%s] streaming total=%.1fms',
                            static::class, ($t3 - $t0) * 1000
                        ));
                    } else {
                        $element = $this->render();

                        $t1 = microtime(true);
                        $tree = $element->toArray($this->callbacks);
                        $t2 = microtime(true);

                        $this->router?->flushDeferredTransition();

                        nativephp_element_publish($tree);

                        $t3 = microtime(true);
                        NativeRouter::debugLog(sprintf(
                            'PERF [%s] render=%.1fms toArray=%.1fms publish=%.1fms total=%.1fms',
                            static::class, ($t1 - $t0) * 1000, ($t2 - $t1) * 1000,
                            ($t3 - $t2) * 1000, ($t3 - $t0) * 1000
                        ));
                    }
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog("render() FAILED in " . static::class . ": " . $e->getMessage() . "\n" . $e->getTraceAsString());
                    $this->renderErrorScreen($e);
                }
            }

            $event = nativephp_element_wait_event(-1);

            if ($event === null) {
                continue;
            }

            // Hot reload: write restart signal and exit so Kotlin re-executes with fresh PHP
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->flushCompiledViews();
                $uri = '/'.ltrim(request()->path(), '/');
                @file_put_contents(
                    storage_path('framework/.hot_restart'),
                    json_encode(['uri' => $uri, 'ts' => time()])
                );
                NativeRouter::debugLog("HOT_RELOAD: wrote restart signal for $uri");
                $this->navigationIntent = new NavigationIntent(NavigationIntent::RESTART, $uri);
                $this->stop();

                continue;
            }

            // System back button (type 8)
            if (($event['type'] ?? -1) === 8) {
                if ($this->hasError) {
                    // Dismiss error/dump screen and re-render the component
                    $this->hasError = false;
                    $this->dumpException = null;
                    $this->errorException = null;
                    $this->overlayCallbackIds = [];
                    continue;
                }
                $this->onBackPressed();
                continue;
            }

            // Native event from bridge function — dispatch to #[OnNative] listeners
            if (($event['type'] ?? -1) === self::EVENT_NATIVE) {
                try {
                    $this->dispatchNativeEvent($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog("dispatchNativeEvent() FAILED in " . static::class . ": " . $e->getMessage());
                    $this->renderErrorScreen($e);
                }
                continue;
            }

            // Don't dispatch UI events while showing the error/dump screen
            // (except overlay controls like font size buttons)
            if (! $this->hasError) {
                try {
                    $this->dispatch($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog("dispatch() FAILED in " . static::class . ": " . $e->getMessage());
                    $this->renderErrorScreen($e);
                }
            } elseif (in_array($event['callback_id'] ?? 0, $this->overlayCallbackIds)) {
                $this->dispatch($event);
            }
        }
    }

    public function getNavigationIntent(): ?NavigationIntent
    {
        return $this->navigationIntent;
    }

    // ── Navigation methods ──────────────────────────

    public function navigate(string $uri, array $data = []): static
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::NAVIGATE, $uri, $data);
        $this->stop();

        return $this;
    }

    public function back(): static
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::BACK);
        $this->stop();

        return $this;
    }

    public function replace(string $uri, array $data = []): static
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::REPLACE, $uri, $data);
        $this->stop();

        return $this;
    }

    public function transition(Transition $type): static
    {
        if ($this->navigationIntent) {
            $this->navigationIntent = new NavigationIntent(
                $this->navigationIntent->type,
                $this->navigationIntent->uri,
                $this->navigationIntent->data,
                $type,
            );
        }

        return $this;
    }

    public function exitToWeb(string $uri): void
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::EXIT_WEB, $uri);
        $this->stop();
    }

    // ── Route helper ────────────────────────────────

    /**
     * Resolve a named route to a URI path for native navigation.
     *
     *   $this->navigate($this->route('listing.show', ['id' => 5]));
     */
    public function route(string $name, mixed $parameters = []): string
    {
        return \Illuminate\Support\Facades\URL::route($name, $parameters, absolute: false);
    }

    // ── Parameter / data access ─────────────────────

    public function param(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    public function data(string $key, $default = null)
    {
        return $this->navigationData[$key] ?? $default;
    }

    // ── Injection (called by NativeRouter) ──────────

    public function setRouter(NativeRouter $router): void
    {
        $this->router = $router;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function setData(array $data): void
    {
        $this->navigationData = $data;
    }

    // ── Element resolution helper ──────────────────

    private function resolveElement(string $type, array $attrs = []): ?Element
    {
        $el = ElementRegistry::resolve($type);

        if ($el !== null) {
            $el->applyAttributes($attrs);
        }

        return $el;
    }

    // ── Error screen ────────────────────────────────

    public function renderErrorScreen(\Throwable $e): void
    {
        $this->hasError = true;
        $this->errorException = $e;
        $this->callbacks ??= new CallbackRegistry;

        try {
            $screen = Elements\ScrollView::make()
                ->fill()
                ->bg('#FEF2F2')
                ->safeArea();

            $content = Elements\Column::make()
                ->fillWidth()
                ->padding(20, 20, 40, 20)
                ->gap(10);

            $title = $this->resolveElement('text', ['text' => 'Exception', 'fontSize' => 22, 'fontWeight' => 7, 'color' => '#991B1B']);
            if ($title) {
                $content->addChild($title);
            }

            $file = str_replace(base_path() . '/', '', $e->getFile());
            $location = $this->resolveElement('text', ['text' => "{$file}:{$e->getLine()}", 'fontSize' => 12, 'color' => '#9CA3AF']);
            if ($location) {
                $content->addChild($location);
            }

            // Font size slider
            $slider = $this->resolveElement('slider', ['value' => (float) $this->overlayFontSize, 'min' => 6, 'max' => 40, 'step' => 2, 'color' => '#DC2626', 'trackColor' => '#991B1B']);
            if ($slider) {
                if (method_exists($slider, 'onChange')) {
                    $slider->onChange('__overlaySetFontSize');
                }
                $content->addChild($slider->fillWidth());
            }

            $divider = $this->resolveElement('divider');
            if ($divider) {
                $content->addChild($divider->fillWidth());
            }

            $className = $this->resolveElement('text', ['text' => static::class, 'fontSize' => 13, 'color' => '#B91C1C']);
            if ($className) {
                $content->addChild($className);
            }

            $message = $this->resolveElement('text', ['text' => $e->getMessage(), 'fontSize' => $this->overlayFontSize, 'fontWeight' => 5, 'color' => '#DC2626']);
            if ($message) {
                $content->addChild($message);
            }

            // Show a condensed stack trace
            $trace = $e->getTraceAsString();
            $trace = str_replace(base_path() . '/', '', $trace);
            $traceLines = explode("\n", $trace);
            $shortTrace = implode("\n", array_slice($traceLines, 0, 15));
            if (count($traceLines) > 15) {
                $shortTrace .= "\n... (" . count($traceLines) . " frames total)";
            }

            $traceText = $this->resolveElement('text', ['text' => $shortTrace, 'fontSize' => $this->overlayFontSize, 'color' => '#6B7280']);
            if ($traceText) {
                $content->addChild($traceText);
            }

            $screen->addChild($content);

            $errorTree = $screen->toArray($this->callbacks);

            $this->overlayCallbackIds = array_filter([
                $this->callbacks->lookup('__overlaySetFontSize'),
            ]);

            $this->router?->flushDeferredTransition();
            nativephp_element_publish($errorTree);
        } catch (\Throwable $renderError) {
            NativeRouter::debugLog("Error screen render failed: " . $renderError->getMessage());
        }
    }

    // ── Dump screen (dd) ─────────────────────────────

    public function renderDumpScreen(NativeDumpException $e): void
    {
        $this->hasError = true;
        $this->dumpException = $e;
        $this->callbacks ??= new CallbackRegistry;

        try {
            $screen = Elements\ScrollView::make()
                ->fill()
                ->bg('#0F172A')
                ->safeArea();

            $content = Elements\Column::make()
                ->fillWidth()
                ->padding(20, 20, 40, 20)
                ->gap(10);

            $title = $this->resolveElement('text', ['text' => 'dd()', 'fontSize' => 22, 'fontWeight' => 7, 'color' => '#22D3EE']);
            if ($title) {
                $content->addChild($title);
            }

            $file = str_replace(base_path() . '/', '', $e->getSourceFile());
            $location = $this->resolveElement('text', ['text' => "{$file}:{$e->getSourceLine()}", 'fontSize' => 12, 'color' => '#64748B']);
            if ($location) {
                $content->addChild($location);
            }

            // Font size slider
            $slider = $this->resolveElement('slider', ['value' => (float) $this->overlayFontSize, 'min' => 6, 'max' => 40, 'step' => 2, 'color' => '#22D3EE', 'trackColor' => '#164E63']);
            if ($slider) {
                if (method_exists($slider, 'onChange')) {
                    $slider->onChange('__overlaySetFontSize');
                }
                $content->addChild($slider->fillWidth());
            }

            $divider = $this->resolveElement('divider');
            if ($divider) {
                $content->addChild($divider->fillWidth());
            }

            $dumpText = $this->resolveElement('text', ['text' => $e->getFormattedDumps(), 'fontSize' => $this->overlayFontSize, 'color' => '#E2E8F0']);
            if ($dumpText) {
                $content->addChild($dumpText);
            }

            $screen->addChild($content);

            $dumpTree = $screen->toArray($this->callbacks);

            $this->overlayCallbackIds = array_filter([
                $this->callbacks->lookup('__overlaySetFontSize'),
            ]);

            $this->router?->flushDeferredTransition();
            nativephp_element_publish($dumpTree);
        } catch (\Throwable $renderError) {
            NativeRouter::debugLog("Dump screen render failed: " . $renderError->getMessage());
        }
    }

    // ── Overlay font size control (shared by dump + error screens) ──

    public function __overlaySetFontSize(float $size): void
    {
        $this->overlayFontSize = (int) max(6, min(40, $size));

        if ($this->dumpException) {
            $this->renderDumpScreen($this->dumpException);
        } elseif ($this->errorException) {
            $this->renderErrorScreen($this->errorException);
        }
    }

    // ── Hot reload ──────────────────────────────────

    protected function flushCompiledViews(): void
    {
        $viewPath = storage_path('framework/views');

        if (is_dir($viewPath)) {
            foreach (glob("{$viewPath}/*.php") as $file) {
                // Skip .blade.php source files — these are created by
                // Laravel's createBladeViewFromString() for inline component
                // views (e.g. self-closing components returning ''). Deleting
                // them causes "View [hash] not found" errors.
                if (str_ends_with($file, '.blade.php')) {
                    continue;
                }

                @unlink($file);
            }
        }

        // Critical: clear stat cache AFTER deleting files so PHP sees
        // the deletions. Long-running processes cache stat() results,
        // and Blade's isExpired() uses file_exists() / filemtime().
        clearstatcache();

        // Clear the view finder cache so Blade re-discovers templates
        if (function_exists('app') && app()->bound('view')) {
            app('view')->getFinder()->flush();
        }

        // Reset OPcache if available — the long-running process may
        // have cached bytecode for the old compiled views
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    // ── Declarative navigation ────────────────────

    public function __navigate(string $key): void
    {
        $config = $this->callbacks->resolveNavigation($key);

        if ($config === null) {
            return;
        }

        match ($config['type']) {
            'back' => $this->back(),
            'replace' => $this->replace($config['uri'] ?? '', $config['data'] ?? []),
            'exitToWeb' => $this->exitToWeb($config['uri'] ?? ''),
            default => $this->navigate($config['uri'] ?? '', $config['data'] ?? []),
        };

        if (($config['transition'] ?? null) !== null) {
            $this->transition(Transition::from($config['transition']));
        }
    }

    // ── Model binding ──────────────────────────────

    public function __syncProperty(string $property, mixed $value): void
    {
        if (! property_exists($this, $property)) {
            return;
        }

        $this->{$property} = $value;

        $hook = 'updated'.ucfirst($property);

        if (method_exists($this, $hook)) {
            $this->{$hook}($value);
        }
    }

    // ── Event dispatch ──────────────────────────────

    protected function dispatch(array $event): void
    {
        $callback = $this->callbacks->resolve($event['callback_id'] ?? 0);

        if ($callback === null) {
            return;
        }

        $method = $callback['method'];
        $args = $callback['args'];

        if (! method_exists($this, $method)) {
            return;
        }

        $type = $event['type'] ?? -1;

        $eventArgs = match ($type) {
            2, 4    => [$event['text'] ?? ''],                      // TEXT_CHANGE, SUBMIT
            3, 10   => [(bool) ($event['value'] ?? false)],          // TOGGLE_CHANGE, CHECKBOX_CHANGE
            9       => [(float) ($event['value'] ?? 0.0)],           // SLIDER_CHANGE
            11, 12  => [(string) ($event['value'] ?? '')],           // RADIO_CHANGE, SELECT_CHANGE
            13      => [(int) ($event['value'] ?? 0)],               // TAB_CHANGE
            default => [],                                           // PRESS, LONG_PRESS, SHEET_DISMISS
        };

        $this->$method(...[...$args, ...$eventArgs]);
    }
}
