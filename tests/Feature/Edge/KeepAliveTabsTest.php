<?php

use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;

/*
 * Keep-alive tabs: leaving a tab of the native tab bar parks its screens
 * (component instances and all) instead of unmounting them, and coming back
 * resumes them. These drive the real navigation loop through the fake
 * bridge, tapping the tab items the screens publish.
 */

class KeepAliveTabsLayout extends NativeLayout
{
    public static bool $keepAlive = true;

    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Home', '/home', icon: 'home'))
            ->add(Tab::link('Shop', '/shop', icon: 'cart'))
            ->add(Tab::link('Profile', '/profile', icon: 'person'))
            ->keepAlive(self::$keepAlive);
    }
}

class KeepAliveLoginLayout extends NativeLayout {}

abstract class KeepAliveScreen extends NativeComponent
{
    /** @var list<string> what happened, in order: "mount Home#1", "resume Home#1", ... */
    public static array $log = [];

    private static int $instances = 0;

    public int $instance;

    public int $count = 0;

    public function mount(): void
    {
        $this->instance = ++self::$instances;
        self::$log[] = 'mount '.$this->name();
    }

    public function onResume(): void
    {
        self::$log[] = 'resume '.$this->name();
    }

    public function unmount(): void
    {
        self::$log[] = 'unmount '.$this->name();
    }

    /** A failing render shows as a test failure, not an error screen. */
    public function renderErrorScreen(Throwable $e): void
    {
        throw $e;
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function openDetail(): void
    {
        $this->navigate('/home/detail');
    }

    public function goShop(): void
    {
        $this->switchToTab('/shop');
    }

    public function signOut(): void
    {
        $this->replace('/login');
    }

    public function name(): string
    {
        return substr(class_basename(static::class), strlen('KeepAlive')).'#'.$this->instance;
    }

    public static function reset(): void
    {
        self::$log = [];
        self::$instances = 0;
    }
}

/**
 * Plays a script against the router: each step either names a tab to tap
 * in the last published tree, or a method on the top screen to call. When
 * the script runs out, the app shuts down.
 */
class KeepAliveBridge extends FakeBridge
{
    /** @var list<string|array{call: string}|array{back: true}> */
    public array $script = [];

    /** @var list<string> the text of every published frame */
    public array $frames = [];

    public function elementPublish(array $tree): void
    {
        parent::elementPublish($tree);
        $this->frames[] = implode(' | ', self::texts($tree));
    }

    public function elementWaitEvent(int $timeoutMs): ?array
    {
        $queued = parent::elementWaitEvent($timeoutMs);

        if ($queued !== null || $timeoutMs === 0) {
            return $queued;
        }

        $step = array_shift($this->script);

        if ($step === null) {
            return ['type' => NativeComponent::EVENT_SHUTDOWN];
        }

        if (is_array($step) && isset($step['back'])) {
            return ['type' => NativeComponent::EVENT_SYSTEM_BACK];
        }

        $tree = $this->lastPublish();

        $node = is_array($step)
            ? self::findCallback($tree, $step['call'])
            : self::findTab($tree, $step);

        expect($node)->not->toBeNull('nothing to press for '.json_encode($step));

        return ['type' => 1, 'callback_id' => $node];
    }

    private static function findTab(array $node, string $url): ?int
    {
        if ($node['type'] === 'bottom_nav_item' && ($node['props']['url'] ?? null) === $url) {
            return $node['on_press'] ?? null;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($found = self::findTab($child, $url)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** The pressable a screen renders for each of its methods. */
    private static function findCallback(array $node, string $method): ?int
    {
        if (isset($node['on_press']) && in_array("call:$method", self::texts($node), true)) {
            return $node['on_press'];
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($found = self::findCallback($child, $method)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function texts(array $node): array
    {
        $texts = [];

        if ($node['type'] === 'text') {
            $texts[] = (string) ($node['props']['text'] ?? '');
        }

        foreach ($node['children'] ?? [] as $child) {
            $texts = [...$texts, ...self::texts($child)];
        }

        return $texts;
    }
}

/**
 * Every screen renders a button per method the script may call, so a step
 * can press it like a user would.
 */
/**
 * Renders through a view (layout chrome only wraps view renders), with a
 * pressable per method the script may call.
 */
abstract class KeepAliveButtonsScreen extends KeepAliveScreen
{
    public function render(): View
    {
        return view('keep-alive-screen', ['label' => "{$this->name()} count={$this->count}"]);
    }
}

class KeepAliveHome extends KeepAliveButtonsScreen {}

class KeepAliveShop extends KeepAliveButtonsScreen {}

class KeepAliveProfile extends KeepAliveButtonsScreen {}

class KeepAliveDetail extends KeepAliveButtonsScreen {}

class KeepAliveLogin extends KeepAliveButtonsScreen {}

function keepAliveRun(string $start, array $script): KeepAliveBridge
{
    $bridge = new KeepAliveBridge;
    $bridge->script = $script;
    app()->instance(FakeBridge::class, $bridge);

    $router = new NativeRouter;
    $router->start(NativeRouter::resolve($start)['class'], [], $start);

    return $bridge;
}

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    NativeRouter::clearRoutes();

    NativeRouter::register('/home', KeepAliveHome::class, KeepAliveTabsLayout::class);
    NativeRouter::register('/shop', KeepAliveShop::class, KeepAliveTabsLayout::class);
    NativeRouter::register('/profile', KeepAliveProfile::class, KeepAliveTabsLayout::class);
    NativeRouter::register('/home/detail', KeepAliveDetail::class, KeepAliveTabsLayout::class);
    NativeRouter::register('/login', KeepAliveLogin::class, KeepAliveLoginLayout::class);

    KeepAliveTabsLayout::$keepAlive = true;
    KeepAliveScreen::reset();
    System::rememberPlatform(null);
});

afterEach(function () {
    NativeRouter::clearRoutes();
    FakeBridge::disable();
});

it('keeps a tab alive across a switch: no second mount, state intact', function () {
    $bridge = keepAliveRun('/home', [
        ['call' => 'increment'],
        ['call' => 'increment'],
        '/shop',
        '/home',
    ]);

    expect(KeepAliveScreen::$log)->toBe([
        'mount Home#1',
        'mount Shop#2',
        'resume Home#1',
        // shutdown: the stack unwinds, then the parked Shop tab goes
        'unmount Home#1',
        'unmount Shop#2',
    ]);

    // Home comes back with its count, as the same instance.
    expect(end($bridge->frames))->toContain('Home#1 count=2');
});

it('keeps a tab\'s pushed screens, and back still pops to the tab root', function () {
    $bridge = keepAliveRun('/home', [
        ['call' => 'increment'],
        ['call' => 'openDetail'],
        '/shop',
        '/home',
        ['back' => true],
    ]);

    expect(KeepAliveScreen::$log)->toBe([
        'mount Home#1',
        'mount Detail#2',
        'mount Shop#3',
        'resume Detail#2',
        'unmount Detail#2',
        'resume Home#1',
        'unmount Home#1',
        'unmount Shop#3',
    ]);

    expect(end($bridge->frames))->toContain('Home#1 count=1');
});

it('opens a tab at its root the first time and keeps every tab it visited', function () {
    keepAliveRun('/home', [
        '/shop',
        '/profile',
        '/shop',
        '/home',
        '/profile',
    ]);

    expect(array_values(array_filter(KeepAliveScreen::$log, fn ($line) => ! str_starts_with($line, 'unmount'))))->toBe([
        'mount Home#1',
        'mount Shop#2',
        'mount Profile#3',
        'resume Shop#2',
        'resume Home#1',
        'resume Profile#3',
    ]);
});

it('switches tabs from code with switchToTab()', function () {
    keepAliveRun('/home', [
        ['call' => 'openDetail'],
        ['call' => 'goShop'],
        '/home',
    ]);

    expect(array_slice(KeepAliveScreen::$log, 0, 4))->toBe([
        'mount Home#1',
        'mount Detail#2',
        'mount Shop#3',
        'resume Detail#2',
    ]);
});

it('publishes every tab root at the same stack depth', function () {
    $bridge = new class extends KeepAliveBridge
    {
        public array $depths = [];

        public function elementPublish(array $tree): void
        {
            parent::elementPublish($tree);
            $this->depths[] = ($tree['props']['current_uri'] ?? '?').'@'.($tree['props']['stack_depth'] ?? '?');
        }
    };
    $bridge->script = [['call' => 'openDetail'], '/shop', '/home'];
    app()->instance(FakeBridge::class, $bridge);

    (new NativeRouter)->start(KeepAliveHome::class, [], '/home');

    expect(array_values(array_unique($bridge->depths)))->toBe([
        '/home@1',
        '/home/detail@2',
        '/shop@1',
    ]);
});

it('pops the showing tab to its root when it is selected again', function () {
    keepAliveRun('/home', [
        ['call' => 'openDetail'],
        '/home',
    ]);

    expect(array_slice(KeepAliveScreen::$log, 0, 4))->toBe([
        'mount Home#1',
        'mount Detail#2',
        'unmount Detail#2',
        'resume Home#1',
    ]);
});

it('mounts a fresh screen on every tap when the bar opts out', function () {
    KeepAliveTabsLayout::$keepAlive = false;

    keepAliveRun('/home', [
        ['call' => 'increment'],
        '/shop',
        '/home',
    ]);

    expect(KeepAliveScreen::$log)->toBe([
        'mount Home#1',
        'unmount Home#1',
        'mount Shop#2',
        'unmount Shop#2',
        'mount Home#3',
        'unmount Home#3',
    ]);
});

it('drops the parked tabs when a screen replaces its way out of the tab chrome', function () {
    keepAliveRun('/home', [
        '/shop',
        ['call' => 'signOut'],
        ['call' => 'increment'],
    ]);

    expect(KeepAliveScreen::$log)->toBe([
        'mount Home#1',
        'mount Shop#2',
        'unmount Shop#2',
        'mount Login#3',
        // The login screen rendered without the tab bar: Home goes at the
        // next navigation turn, here the shutdown.
        'unmount Login#3',
        'unmount Home#1',
    ]);
});

it('sends a tab switch, not a replace, from the tab items of a keep-alive bar', function () {
    keepAliveRun('/home', []);

    $tree = FakeBridge::current()->lastPublish();

    expect($tree['type'])->toBe('native_root_tabs')
        ->and($tree['props']['keep_alive'] ?? false)->toBeTrue();
});
