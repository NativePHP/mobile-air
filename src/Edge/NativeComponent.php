<?php

namespace Native\Mobile\Edge;

abstract class NativeComponent
{
    protected CallbackRegistry $callbacks;

    protected bool $running = true;

    protected ?NavigationIntent $navigationIntent = null;

    protected ?NativeRouter $router = null;

    protected array $params = [];

    protected array $navigationData = [];

    abstract public function render(): Element;

    protected function view(string $name, array $data = []): Element
    {
        NativeElementCollector::reset();

        $viewData = array_merge($this->getPublicProperties(), $data);

        view("native.{$name}", $viewData)->render();

        return NativeElementCollector::collect();
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

    /**
     * Full standalone lifecycle — init, mount, loop, unmount, shutdown.
     * Used when running without the NativeRouter.
     */
    public function run(): void
    {
        $this->callbacks = new CallbackRegistry;

        nativephp_ui_init();

        $this->mount();

        while ($this->running) {
            $this->callbacks->reset();

            $tree = $this->render()->toArray($this->callbacks);

            nativephp_ui_render($tree);

            $event = nativephp_ui_wait_event(-1);

            if ($event === null) {
                continue;
            }

            $this->dispatch($event);
        }

        $this->unmount();

        nativephp_ui_shutdown();
    }

    /**
     * Just the render/event loop — no init/shutdown.
     * Used by NativeRouter for hot-swap navigation.
     */
    public function runLoop(): void
    {
        $this->callbacks = new CallbackRegistry;
        $this->running = true;
        $this->navigationIntent = null;

        while ($this->running) {
            $this->callbacks->reset();

            try {
                $tree = $this->render()->toArray($this->callbacks);
            } catch (\Throwable $e) {
                NativeRouter::debugLog("render() FAILED in " . static::class . ": " . $e->getMessage() . "\n" . $e->getTraceAsString());
                $this->running = false;
                break;
            }

            nativephp_ui_render($tree);

            $event = nativephp_ui_wait_event(-1);

            if ($event === null) {
                continue;
            }

            // System back button (type 8) — call onBackPressed() or default to back()
            if (($event['type'] ?? -1) === 8) {
                $this->onBackPressed();
                continue;
            }

            $this->dispatch($event);
        }
    }

    public function getNavigationIntent(): ?NavigationIntent
    {
        return $this->navigationIntent;
    }

    // ── Navigation methods ──────────────────────────

    public function navigate(string $uri, array $data = []): void
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::NAVIGATE, $uri, $data);
        $this->stop();
    }

    public function back(): void
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::BACK);
        $this->stop();
    }

    public function replace(string $uri, array $data = []): void
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::REPLACE, $uri, $data);
        $this->stop();
    }

    public function exitToWeb(string $uri): void
    {
        $this->navigationIntent = new NavigationIntent(NavigationIntent::EXIT_WEB, $uri);
        $this->stop();
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

    // ── Event dispatch ──────────────────────────────

    protected function dispatch(array $event): void
    {
        $method = $this->callbacks->resolve($event['callback_id'] ?? 0);

        if ($method === null || ! method_exists($this, $method)) {
            return;
        }

        $type = $event['type'] ?? -1;

        match ($type) {
            0, 1    => $this->$method(),                          // PRESS, LONG_PRESS
            2, 4    => $this->$method($event['text'] ?? ''),      // TEXT_CHANGE, SUBMIT
            3, 10   => $this->$method((bool) ($event['value'] ?? false)),  // TOGGLE_CHANGE, CHECKBOX_CHANGE
            9       => $this->$method((float) ($event['value'] ?? 0.0)),   // SLIDER_CHANGE
            11, 12  => $this->$method((string) ($event['value'] ?? '')),   // RADIO_CHANGE, SELECT_CHANGE
            13      => $this->$method((int) ($event['value'] ?? 0)),       // TAB_CHANGE
            14      => $this->$method(),                                    // SHEET_DISMISS
            default => $this->$method(),
        };
    }
}
