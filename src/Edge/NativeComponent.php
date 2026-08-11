<?php

namespace Native\Mobile\Edge;

use Livewire\Features\SupportEvents\BaseOn;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Edge\Concerns\ClaimsJumpSession;
use Native\Mobile\Edge\Concerns\InteractsWithAppShell;
use Native\Mobile\Edge\Concerns\RestartsOnHotReload;
use Native\Mobile\Edge\Concerns\WrapsScreensInNativeChrome;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Platform;
use SupaNative\Core\Edge\CallbackRegistry;
use SupaNative\Core\Edge\NativeComponent as CoreNativeComponent;
use SupaNative\Core\Edge\NativeDumpException;
use SupaNative\Core\Edge\TailwindParser;
use SupaNative\Core\Edge\Transition;
use SupaNative\Core\Edge\TreeObservers;

/**
 * The mobile screen base — what an app's `class Dashboard extends
 * NativeComponent` actually extends.
 *
 * Almost all of what used to be here is now in
 * `SupaNative\Core\Edge\NativeComponent`: the render pass, the `#[Computed]` /
 * `#[Poll]` / `#[Lazy]` / `#[On]` machinery, event dispatch, nested child
 * components, the failure and `dd()` overlays. What stayed is what only makes
 * sense on a phone, gathered into four traits by concern, plus the
 * router-driven `runLoop()` and the handful of overrides below that answer
 * core's seams with mobile's answers.
 *
 * This is deliberately a real subclass and not an alias. Every screen ever
 * written names this class in an `extends` clause, and every plugin and doc
 * page does too; keeping a class here means none of them had to change, and
 * means mobile can go on adding to the screen API without asking core's
 * permission.
 */
abstract class NativeComponent extends CoreNativeComponent
{
    use ClaimsJumpSession;
    use InteractsWithAppShell;
    use RestartsOnHotReload;
    use WrapsScreensInNativeChrome;

    // The two shell event types live here rather than in the traits that
    // handle them. PHP 8.2 would allow a trait constant, but PHPStan cannot
    // see one from a SUBCLASS of the using class — so every
    // `self::EVENT_HOT_RELOAD` in BenchmarkComponent, and the same read in any
    // app's own component, would be reported as an undefined constant. They
    // are also documented API that a reader expects to find on the class, so
    // this is the better home either way. Core's own EVENT_NATIVE = 20 is
    // inherited from the parent.

    /** Dev-time hot reload: the runtime reboots and replays the stack. */
    const EVENT_HOT_RELOAD = 15;

    /**
     * App teardown (activity destroyed while the process lives on — e.g. a
     * plugin foreground service pins it). The Kotlin side posts this before
     * waiting on persistent-runtime shutdown so the runloop exits and frees
     * the PHP executor thread; without it, shutdown queues behind a wait
     * that never returns and the main thread hangs (ANR).
     */
    const EVENT_SHUTDOWN = 16;

    /**
     * The host as this package's concrete router.
     *
     * Core types `$nativeRouter` to its own [NavigationHost] contract, which
     * names only the two things core's render path needs — `currentUri()` and
     * `isRootScreen()`. The traits above legitimately reach past that for
     * nav-stack state (`flushDeferredTransition()`, `getStackEntries()`), so
     * they come through here rather than by widening a contract that a desktop
     * router would then have to implement for no reason.
     */
    protected function router(): ?NativeRouter
    {
        return $this->nativeRouter instanceof NativeRouter ? $this->nativeRouter : null;
    }

    /**
     * Keep core's Tailwind parser in step with the detected platform.
     *
     * The parser used to call [Platform::current()] itself on every class
     * it parsed. It can't any more — it lives in supanative/core now, and
     * platform detection is a native-bridge round trip that only this
     * package knows how to make. So the parser holds an injected string and
     * mobile pushes to it: once at service-provider boot, and again at the
     * top of every render pass here.
     *
     * The re-push is not redundant. Detection can legitimately fail and
     * later succeed: in Jump dev mode the first probe of a session races
     * the device's WebSocket attach (see Platform's docblock — failures are
     * deliberately not cached, and retried on a 2s throttle). A runloop
     * request stays open for the whole life of a screen, so a one-shot feed
     * at boot would leave `ios:` / `android:` variant classes silently dead
     * for that entire screen. Re-pushing per render restores the old
     * self-healing behaviour.
     *
     * Guarded on the value actually changing, because setPlatform() clears
     * the parsed-class cache — and this sits on the hot render path.
     */
    protected function syncTailwindPlatform(): void
    {
        $platform = Platform::current();

        if ($platform !== TailwindParser::platform()) {
            TailwindParser::setPlatform($platform);
        }
    }

    /**
     * Also honour the legacy `#[OnNative]` attribute.
     *
     * `On` is the Livewire-free attribute core scans for; `OnNative` is kept
     * for backward compatibility and extends Livewire's `BaseOn`. Since
     * IS_INSTANCEOF autoloads the filter class itself, the legacy scan must be
     * skipped entirely when Livewire isn't installed or every component fatals
     * with "BaseOn not found" — which is exactly why core cannot do this scan
     * and this override exists.
     */
    protected function nativeEventAttributes(\ReflectionMethod $method): array
    {
        return [
            ...parent::nativeEventAttributes($method),
            ...(class_exists(BaseOn::class)
                ? $method->getAttributes(OnNative::class, \ReflectionAttribute::IS_INSTANCEOF)
                : []),
        ];
    }

    /**
     * Mobile marks its globally-broadcast events with an interface, so a
     * theme flip or an orientation change reaches `Event::listen` handlers
     * anywhere in the app and not just the active screen's `#[On]`.
     */
    protected function eventBroadcastsGlobally(string $eventClass): bool
    {
        return is_subclass_of($eventClass, BroadcastsGlobally::class);
    }

    /**
     * Hot reload and app teardown are dev-loop / lifecycle plumbing, so tree
     * observers never see them as user actions.
     *
     * @return list<int>
     */
    protected function systemEventTypes(): array
    {
        return [self::EVENT_HOT_RELOAD, self::EVENT_SHUTDOWN];
    }

    /**
     * The shell's own event types, claimed before the generic loop sees them.
     *
     * Order matches what `runLoop()` checked before these became traits: a
     * hot-reload signal, then app teardown, then the hardware back button.
     * Whichever claims the event returns true and the loop goes straight to
     * its next iteration.
     */
    protected function handlePlatformEvent(array $event): bool
    {
        return $this->handleHotReloadEvent($event)
            || $this->handleShutdownEvent($event)
            || $this->handleBackButtonEvent($event);
    }

    /**
     * Mint intents as THIS package's [NavigationIntent] rather than core's
     * base class, so `navigate()` / `back()` / `replace()` keep handing back
     * the type every screen, plugin and test in the ecosystem already names —
     * and so a mobile intent can carry RESTART or EXIT_WEB without core having
     * heard of either.
     */
    protected function makeNavigationIntent(
        string $type,
        ?string $uri = null,
        array $data = [],
        Transition|string|null $transition = null,
    ): NavigationIntent {
        return new NavigationIntent($type, $uri, $data, $transition);
    }

    /**
     * Arm the router's staged screen transition. Core calls this immediately
     * before a publish (including the failure overlays', so a broken screen
     * still animates in rather than snapping).
     */
    protected function flushDeferredTransition(): void
    {
        $this->router()?->flushDeferredTransition();
    }

    /**
     * Full standalone lifecycle — init, mount, loop, unmount, shutdown.
     * Used when running without the NativeRouter.
     */
    public function run(): void
    {
        static::registerDumpHandler();

        $this->nativeCallbacks = new CallbackRegistry;
        $this->registerNativeEventListeners();

        // Phase 2 — every (re-)entry is a fresh session from the native
        // reader's perspective; discard any prior memo hashes.
        $this->lastNodeHashes = [];
        $this->publishCount = 0;

        nativephp_element_init();

        // For #[Lazy] screens, paint the placeholder before the
        // (potentially slow) mount() so the first frame is instant.
        $this->publishPlaceholder();

        try {
            $this->mount();
        } catch (NativeDumpException $e) {
            $this->renderDumpScreen($e);
        } catch (\Throwable $e) {
            NativeRouter::debugLog('mount() FAILED in '.static::class.': '.$e->getMessage());
            $this->renderErrorScreen($e);
        }

        while ($this->nativeRunning) {
            $this->nativeCallbacks->reset();
            $this->resetComputedCache();
            $this->syncTailwindPlatform();

            if (! $this->nativeHasError) {
                try {
                    if (! $this->renderStreaming()) {
                        $element = $this->renderToElement();
                        $tree = $this->memoizedToArray($element);
                        nativephp_element_publish($tree);
                        TreeObservers::tree(
                            $tree, $this->nativeRouter?->currentUri() ?? '/'
                        );
                    }
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('render() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }
            }

            $event = nativephp_element_wait_event($this->nextEventTimeout());

            if ($event === null) {
                // Idle tick (poll interval elapsed, or no event yet) —
                // fire any due polls, then loop back to re-render.
                $this->runDuePolls();

                continue;
            }

            // Broadcast user-facing frames to observers; system frames like
            // hot reload / shutdown are dev-loop noise, not user actions.
            if (TreeObservers::any()
                && ! in_array($event['type'] ?? -1, [self::EVENT_HOT_RELOAD, self::EVENT_SHUTDOWN], true)) {
                TreeObservers::event(
                    $event,
                    $this->nativeCallbacks->resolve((int) ($event['callback_id'] ?? 0))['method'] ?? null
                );
            }

            // Hot reload: write restart signal and exit so Kotlin re-executes with fresh PHP
            if (($event['type'] ?? -1) === self::EVENT_HOT_RELOAD) {
                $this->flushCompiledViews();
                ['uri' => $uri, 'stack' => $stack] = $this->hotRestartPayload();
                @file_put_contents(
                    storage_path('framework/.hot_restart'),
                    json_encode(['uri' => $uri, 'stack' => $stack, 'ts' => time()])
                );
                $this->stop();

                continue;
            }

            // App teardown: exit the loop (no hot-restart state) so the
            // persistent runtime can shut down or park.
            if (($event['type'] ?? -1) === self::EVENT_SHUTDOWN) {
                NativeRouter::debugLog('SHUTDOWN event received — exiting runloop in '.static::class);
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
                    NativeRouter::debugLog('dispatchNativeEvent() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }

                continue;
            }

            // Don't dispatch UI events while showing the error/dump screen
            // (except overlay controls like font size buttons)
            if (! $this->nativeHasError) {
                try {
                    $this->dispatch($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('dispatch() FAILED in '.static::class.': '.$e->getMessage());
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
     *
     * The standalone counterpart — the one that owns init/shutdown — is
     * core's `run()`. This variant exists because the router brackets a whole
     * session of screens: it calls `nativephp_element_init()` once, hot-swaps
     * components through here, and shuts the region down when the stack
     * empties. It also carries the two things a router-driven loop needs and a
     * standalone one does not: honouring an intent set during `mount()`, and
     * the Jump session claim.
     */
    public function runLoop(): void
    {
        // The native runloop holds a single request open for the entire
        // lifetime of the screen — it blocks in `nativephp_element_wait_event()`
        // between user interactions and re-renders on every event. Under the
        // Jump dev server (`artisan serve`) the request inherits PHP's default
        // `max_execution_time = 30`, which counts the request's accumulated
        // run time. So a busy session (rapid taps, slider drags, navigation)
        // burns through 30s and PHP fatals mid-loop — at the blocking bridge
        // read in JumpBridge::readExact() — killing the request and freezing
        // the app. On-device there is no Symfony request lifecycle, so this
        // never bites there. Disable the limit: this loop is intentionally
        // long-running, exactly like the on-device runloop.
        @set_time_limit(0);

        // Jump hybrid mode only. The immortal request above is necessary on the
        // dev server but creates a hazard unique to Jump; see ClaimsJumpSession.
        $jumpSessionToken = $this->claimJumpSessionIfUnderJump();

        static::registerDumpHandler();

        $this->nativeCallbacks ??= new CallbackRegistry;

        // A navigation intent set during mount() — e.g. an auth gate calling
        // $this->replace('/login') — must be honored: don't clear it and don't
        // enter the loop, so the router navigates immediately.
        if ($this->nativeNavigationIntent !== null) {
            return;
        }

        $this->nativeRunning = true;
        $this->nativeNavigationIntent = null;

        // Phase 2 — every (re-)entry is a fresh session from the native
        // reader's perspective; discard any prior memo hashes. The
        // epoch handshake in memoizedToArray() handles mid-session
        // resets too, but clearing here ensures the very first publish
        // of this session can't emit REUSE.
        $this->lastNodeHashes = [];
        $this->publishCount = 0;

        if (empty($this->nativeEventListeners)) {
            $this->registerNativeEventListeners();
        }

        while ($this->nativeRunning) {
            // Superseded by a newer Jump native session — this runloop is an
            // orphan (its WebView is gone) and must unwind without touching
            // the bridge.
            if ($this->supersededByNewerJumpSession($jumpSessionToken)) {
                $this->nativeRunning = false;
                break;
            }

            $this->nativeCallbacks->reset();
            $this->resetComputedCache();
            $this->syncTailwindPlatform();

            if (! $this->nativeHasError) {
                try {
                    $t0 = microtime(true);

                    if ($this->renderStreaming()) {
                        // Explicit streaming path
                        $this->flushDeferredTransition();
                        $t3 = microtime(true);
                        NativeRouter::debugLog(sprintf(
                            'PERF [%s] streaming total=%.1fms',
                            static::class, ($t3 - $t0) * 1000
                        ));
                    } else {
                        $element = $this->renderToElement();

                        $t1 = microtime(true);
                        $tree = $this->memoizedToArray($element);
                        $t2 = microtime(true);

                        $this->flushDeferredTransition();

                        nativephp_element_publish($tree);
                        TreeObservers::tree(
                            $tree, $this->nativeRouter?->currentUri() ?? '/'
                        );

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
                    NativeRouter::debugLog('render() FAILED in '.static::class.': '.$e->getMessage()."\n".$e->getTraceAsString());
                    $this->renderErrorScreen($e);
                }
            }

            $event = nativephp_element_wait_event($this->nextEventTimeout());

            if ($event === null) {
                // Idle tick (poll interval elapsed, or no event yet) —
                // fire any due polls, then loop back to re-render.
                $this->runDuePolls();

                continue;
            }

            // Broadcast user-facing frames to observers; system frames like
            // hot reload / shutdown are dev-loop noise, not user actions.
            if (TreeObservers::any()
                && ! in_array($event['type'] ?? -1, $this->systemEventTypes(), true)) {
                TreeObservers::event(
                    $event,
                    $this->nativeCallbacks->resolve((int) ($event['callback_id'] ?? 0))['method'] ?? null
                );
            }

            // Hot reload, app teardown, system back button — see the traits.
            if ($this->handlePlatformEvent($event)) {
                continue;
            }

            // Native event from bridge function — dispatch to #[OnNative] listeners
            if (($event['type'] ?? -1) === self::EVENT_NATIVE) {
                try {
                    $this->dispatchNativeEvent($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('dispatchNativeEvent() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }

                continue;
            }

            // Don't dispatch UI events while showing the error/dump screen
            // (except overlay controls like font size buttons)
            if (! $this->nativeHasError) {
                try {
                    $this->dispatch($event);
                } catch (NativeDumpException $e) {
                    $this->renderDumpScreen($e);
                } catch (\Throwable $e) {
                    NativeRouter::debugLog('dispatch() FAILED in '.static::class.': '.$e->getMessage());
                    $this->renderErrorScreen($e);
                }
            } elseif (in_array($event['callback_id'] ?? 0, $this->overlayCallbackIds)) {
                $this->dispatch($event);
            }
        }
    }
}
