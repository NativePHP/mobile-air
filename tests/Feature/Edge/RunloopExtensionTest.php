<?php

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Concerns\ValidatesInput;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Enums\ActionSource;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\RemoteActionNotAllowedException;
use Native\Mobile\Edge\Extensions\RunloopExtensionBase;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\PollScreen;
use Tests\Fixtures\Edge\ScriptedEventBridge;

/*
 * The hooks a package uses to take part in every screen's runloop
 * (RunloopExtension). Each test registers a small extension of its own.
 */

class ExtensionHookScreen extends NativeComponent
{
    public int $count = 0;

    public array $ran = [];

    public function render(): Element
    {
        return Column::make(
            Text::make("Count {$this->count}")->onPress('increment')->ref('bump'),
        );
    }

    public function increment(): void
    {
        $this->count++;
        $this->ran[] = 'increment';
    }

    public function echoed(string $word): string
    {
        $this->ran[] = "echoed:{$word}";

        return $word;
    }

    #[On('package.event')]
    public function onPackageEvent(): void
    {
        $this->ran[] = 'onPackageEvent';
    }
}

class ExtensionStreamingScreen extends NativeComponent
{
    public function render(): Element
    {
        return Column::make();
    }

    protected function renderStreaming(): bool
    {
        $this->streamView('extension-stream');

        return true;
    }
}

/** Records what core asked of it, and answers with whatever a test sets. */
class RecordingExtension extends RunloopExtensionBase
{
    public array $log = [];

    public ?int $wake = null;

    public ?Closure $onTick = null;

    public ?Closure $onAction = null;

    public array $claimEvents = [];

    /** @var array<int, Closure> */
    public array $callbacks = [];

    public function __construct(public string $name = 'ext') {}

    public function beginFrame(): void
    {
        $this->log[] = 'beginFrame';
    }

    public function endFrame(NativeComponent $root): void
    {
        $this->log[] = 'endFrame:'.class_basename($root);
    }

    public function nextWake(array $tree): ?int
    {
        return $this->wake;
    }

    public function tick(array $tree): bool
    {
        $this->log[] = 'tick';

        return $this->onTick ? (bool) ($this->onTick)($tree) : false;
    }

    public function handleNativeEvent(string $name, mixed $payload): bool
    {
        if (in_array($name, $this->claimEvents, true)) {
            $this->log[] = 'claimed:'.$name;

            return true;
        }

        return false;
    }

    public function resolveCallback(NativeComponent $component, int $id): ?Closure
    {
        return $this->callbacks[$id] ?? null;
    }

    public function aroundAction(NativeComponent $component, string $method, array $args, ActionSource $source, Closure $next): mixed
    {
        $this->log[] = "{$this->name}:before:{$method}:{$source->value}";

        $result = $this->onAction ? ($this->onAction)($next) : $next();

        $this->log[] = "{$this->name}:after:{$method}";

        return $result;
    }

    public function componentUnmounted(NativeComponent $component): void
    {
        $this->log[] = 'unmounted:'.class_basename($component);
    }
}

class ValidatingExtensionScreen extends NativeComponent
{
    use ValidatesInput;

    public bool $saved = false;

    public function save(): void
    {
        $this->saved = true;
    }

    public function render(): Element
    {
        return Column::make(Text::make('Saved: '.json_encode($this->saved)));
    }
}

function extensionTimeout(NativeComponent $screen): int
{
    return (new ReflectionMethod(NativeComponent::class, 'nextEventTimeout'))->invoke($screen);
}

function extensionUiEvent(NativeComponent $screen, array $event): void
{
    (new ReflectionMethod(NativeComponent::class, 'dispatchUiEvent'))->invoke($screen, $event);
}

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

afterEach(function () {
    NativeComponent::forgetExtensions();
    FakeBridge::disable();
    Carbon::setTestNow();
});

// ── Registration ─────────────────────────────────────────────────

it('registers an extension once, in order', function () {
    $a = new RecordingExtension('a');
    $b = new RecordingExtension('b');

    NativeComponent::extend($a);
    NativeComponent::extend($b);
    NativeComponent::extend($a);

    expect(NativeComponent::extensions())->toBe([$a, $b]);

    NativeComponent::forgetExtensions();

    expect(NativeComponent::extensions())->toBe([]);
});

// ── Frames ───────────────────────────────────────────────────────

it('calls beginFrame and endFrame around each render', function () {
    NativeComponent::extend($ext = new RecordingExtension);

    Native::test(ExtensionHookScreen::class)->tap('bump');

    expect($ext->log)->toBe([
        'beginFrame', 'endFrame:ExtensionHookScreen',
        'ext:before:increment:interaction', 'ext:after:increment',
        'beginFrame', 'endFrame:ExtensionHookScreen',
    ]);
});

it('calls the frame hooks on the streaming path too', function () {
    require_once __DIR__.'/../../Support/streaming-writer.php';
    $GLOBALS['nphp_stream'] = [];

    NativeComponent::extend($ext = new RecordingExtension);
    app()->instance(FakeBridge::class, new ScriptedEventBridge([]));

    (new ExtensionStreamingScreen)->runLoop();

    expect(array_values(array_filter($ext->log, fn ($e) => $e !== 'tick')))
        ->toBe(['beginFrame', 'endFrame:ExtensionStreamingScreen'])
        ->and($GLOBALS['nphp_stream'][0])->toBe(['frame_begin']);

    unset($GLOBALS['nphp_stream']);
});

// ── nextWake ─────────────────────────────────────────────────────

it('wakes a screen with no polls when an extension asks to', function () {
    $screen = new ExtensionHookScreen;
    expect(extensionTimeout($screen))->toBe(-1);

    NativeComponent::extend($ext = new RecordingExtension);
    expect(extensionTimeout($screen))->toBe(-1);

    $ext->wake = 250;
    expect(extensionTimeout($screen))->toBe(250);

    $ext->wake = 0;
    expect(extensionTimeout($screen))->toBe(0);

    $ext->wake = -40;
    expect(extensionTimeout($screen))->toBe(0);
});

it('takes the sooner of its own polls and the extensions', function () {
    $screen = new PollScreen;
    $polls = extensionTimeout($screen);
    expect($polls)->toBeGreaterThan(5);

    $soon = new RecordingExtension;
    $soon->wake = 5;
    $late = new RecordingExtension;
    $late->wake = 3_600_000;
    NativeComponent::extend($late);
    expect(extensionTimeout($screen))->toBeLessThanOrEqual($polls);

    NativeComponent::extend($soon);
    expect(extensionTimeout($screen))->toBe(5);
});

it('blocks while the error screen is up whatever an extension wants', function () {
    $ext = new RecordingExtension;
    $ext->wake = 0;
    NativeComponent::extend($ext);

    $screen = new ExtensionHookScreen;
    (new ReflectionProperty(NativeComponent::class, 'nativeHasError'))->setValue($screen, true);

    expect(extensionTimeout($screen))->toBe(-1);
});

// ── tick ─────────────────────────────────────────────────────────

it('ticks on wait() and repaints what the tick changed', function () {
    $ext = new RecordingExtension;
    $ext->onTick = function (array $tree) {
        $tree[0]->count = 7;

        return true;
    };
    NativeComponent::extend($ext);

    Native::test(ExtensionHookScreen::class)
        ->assertSee('Count 0')
        ->wait()
        ->assertSee('Count 7');
});

it('moves the test clock before the tick on wait(seconds)', function () {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $seen = null;

    $ext = new RecordingExtension;
    $ext->onTick = function () use (&$seen) {
        $seen = Carbon::now()->toDateTimeString();

        return false;
    };
    NativeComponent::extend($ext);

    Native::test(ExtensionHookScreen::class)->wait(2.5);

    expect($seen)->toBe('2026-01-01 00:00:02');
});

it('stops ticking the rest once a tick ends the loop', function () {
    $first = new RecordingExtension('first');
    $first->onTick = function (array $tree) {
        $tree[0]->navigate('/elsewhere');

        return true;
    };
    $second = new RecordingExtension('second');

    NativeComponent::extend($first);
    NativeComponent::extend($second);

    Native::test(ExtensionHookScreen::class)->wait()->assertNavigatedTo('/elsewhere');

    expect($first->log)->toContain('tick')
        ->and($second->log)->not->toContain('tick');
});

it('ticks at the top of each turn of the runloop, and leaves it cleanly when a tick navigates', function () {
    $ext = new RecordingExtension;
    $turns = 0;
    $ext->onTick = function (array $tree) use (&$turns) {
        if (++$turns === 2) {
            $tree[0]->navigate('/elsewhere');
        }

        return false;
    };
    NativeComponent::extend($ext);

    // One idle tick, then the loop would shut down if nothing else ended it.
    app()->instance(FakeBridge::class, new ScriptedEventBridge([null]));
    $screen = new ExtensionHookScreen;
    $screen->runLoop();

    // The second turn's tick navigated, so the loop ended there: no third
    // turn, and the screen leaves with the intent the tick set.
    expect($turns)->toBe(2)
        ->and($screen->getNavigationIntent()?->uri)->toBe('/elsewhere');
});

it('shows the error screen when a tick throws', function () {
    $ext = new RecordingExtension;
    $ext->onTick = fn () => throw new RuntimeException('tick failed');
    NativeComponent::extend($ext);

    $screen = Native::test(ExtensionHookScreen::class)->wait();

    expect((new ReflectionProperty(NativeComponent::class, 'nativeHasError'))->getValue($screen->instance()))->toBeTrue();
});

// ── handleNativeEvent ───────────────────────────────────────────

it('lets an extension claim a native event before the screen hears it', function () {
    $ext = new RecordingExtension;
    $ext->claimEvents = ['package.event'];
    NativeComponent::extend($ext);

    $screen = Native::test(ExtensionHookScreen::class)->emitNative('package.event');

    expect($ext->log)->toContain('claimed:package.event')
        ->and($screen->get('ran'))->toBe([]);
});

it('passes an event it does not claim on to the screen', function () {
    NativeComponent::extend(new RecordingExtension);

    $screen = Native::test(ExtensionHookScreen::class)->emitNative('package.event');

    expect($screen->get('ran'))->toBe(['onPackageEvent']);
});

// ── resolveCallback ─────────────────────────────────────────────

it('runs the handler an extension registered for a callback id, with the event value', function () {
    $received = null;
    $ext = new RecordingExtension;
    $ext->callbacks[424242] = function (...$args) use (&$received) {
        $received = $args;
    };
    NativeComponent::extend($ext);

    $screen = Native::test(ExtensionHookScreen::class);
    extensionUiEvent($screen->instance(), ['type' => 2, 'callback_id' => 424242, 'text' => 'hello']);

    expect($received)->toBe(['hello']);
});

it('drops an id two extensions claim, and runs neither', function () {
    $ran = [];
    $a = new RecordingExtension('a');
    $a->callbacks[99] = function () use (&$ran) {
        $ran[] = 'a';
    };
    $b = new RecordingExtension('b');
    $b->callbacks[99] = function () use (&$ran) {
        $ran[] = 'b';
    };
    NativeComponent::extend($a);
    NativeComponent::extend($b);

    $screen = Native::test(ExtensionHookScreen::class);
    extensionUiEvent($screen->instance(), ['type' => 0, 'callback_id' => 99]);

    expect($ran)->toBe([]);
});

it('drops an id that an extension claims and the screen owns, and runs neither', function () {
    $ran = [];
    $screenId = (new CallbackRegistry)->register('increment');

    $ext = new RecordingExtension;
    $ext->callbacks[$screenId] = function () use (&$ran) {
        $ran[] = 'extension';
    };
    NativeComponent::extend($ext);

    $screen = Native::test(ExtensionHookScreen::class);
    extensionUiEvent($screen->instance(), ['type' => 0, 'callback_id' => $screenId]);

    expect($ran)->toBe([])
        ->and($screen->get('count'))->toBe(0);
});

it('still runs the screen callback when no extension claims the id', function () {
    NativeComponent::extend(new RecordingExtension);

    Native::test(ExtensionHookScreen::class)->tap('bump')->assertSee('Count 1');
});

// ── aroundAction ────────────────────────────────────────────────

it('wraps an action, first registered outermost', function () {
    NativeComponent::extend($outer = new RecordingExtension('outer'));
    NativeComponent::extend($inner = new RecordingExtension('inner'));

    $log = [];
    $outer->log = &$log;
    $inner->log = &$log;

    $result = Native::test(ExtensionHookScreen::class)->instance()->__invokeInteraction('echoed', ['hi']);

    expect($result)->toBe('hi')
        ->and(array_values(array_filter($log, fn ($e) => ! str_contains($e, 'Frame'))))->toBe([
            'outer:before:echoed:interaction',
            'inner:before:echoed:interaction',
            'inner:after:echoed',
            'outer:after:echoed',
        ]);
});

it('lets an extension run something else in place of the action', function () {
    $ext = new RecordingExtension;
    $ext->onAction = fn (Closure $next) => 'replaced';
    NativeComponent::extend($ext);

    $screen = Native::test(ExtensionHookScreen::class);
    $result = $screen->instance()->__invokeInteraction('echoed', ['hi']);

    expect($result)->toBe('replaced')
        ->and($screen->get('ran'))->toBe([]);
});

it('hands ValidatesInput a validation error from an extension that ran the action itself', function () {
    $ext = new RecordingExtension;
    $ext->onAction = fn (Closure $next) => throw ValidationException::withMessages(['name' => 'Taken on the server.']);
    NativeComponent::extend($ext);

    $screen = Native::test(ValidatingExtensionScreen::class)->instance();

    expect($screen->__invokeInteraction('save', []))->toBeNull()
        ->and($screen->getErrorBag()->get('name'))->toBe(['Taken on the server.'])
        ->and($screen->saved)->toBeFalse();
});

it('wraps polls and remote calls too, with their source', function () {
    NativeComponent::extend($ext = new RecordingExtension);

    Native::test(PollScreen::class)->firePolls();

    expect(collect($ext->log)->first(fn ($e) => str_contains($e, ':before:')))->toEndWith(':poll');
});

it('never sees a call the guard refused', function () {
    NativeComponent::extend($ext = new RecordingExtension);

    $screen = Native::test(ExtensionHookScreen::class);

    expect(fn () => $screen->instance()->__invokeInteraction('render', []))
        ->toThrow(ComponentMethodNotFoundException::class)
        ->and(fn () => $screen->instance()->__invokeInteraction('missing', []))
        ->toThrow(ComponentMethodNotFoundException::class)
        ->and(fn () => $screen->instance()->__invokeInteraction('echoed', ['x'], ActionSource::Remote))
        ->toThrow(RemoteActionNotAllowedException::class);

    expect(array_filter($ext->log, fn ($e) => str_contains($e, ':before:')))->toBe([]);
});

// ── componentUnmounted ──────────────────────────────────────────

it('tells extensions when a component unmounts', function () {
    NativeRouter::clearRoutes();
    NativeRouter::register('/next', ExtensionHookScreen::class);
    NativeComponent::extend($ext = new RecordingExtension);

    $first = Native::test(ExtensionHookScreen::class);
    $first->instance()->navigate('/next');
    $first->follow()->goBack();

    expect($ext->log)->toContain('unmounted:ExtensionHookScreen');

    NativeRouter::clearRoutes();
});
