<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Screen\ScreenMounted;
use Native\Mobile\Events\Screen\ScreenResumed;
use Native\Mobile\Events\Screen\ScreenUnmounted;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

/*
 * Named route tables a package fills while the app runs. They add screens
 * after every app route and never replace one, and each entry's context
 * reaches the screen it opens before mount().
 */

class TableScreen extends NativeComponent
{
    public mixed $context = 'never set';

    public mixed $contextAtMount = 'never set';

    public function setRouteContext(mixed $context): void
    {
        $this->context = $context;
    }

    public function mount(): void
    {
        $this->contextAtMount = $this->context;
    }

    public function open(string $uri): void
    {
        $this->navigate($uri);
    }

    public function render(): Element
    {
        return Column::make(Text::make('Table '.json_encode($this->context)));
    }
}

class TableRouter extends NativeRouter
{
    /** @return list<NativeComponent> */
    public function stackComponents(): array
    {
        return array_column($this->stack, 'component');
    }
}

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('matches a table route when no app route does', function () {
    $kept = NativeRouter::setRouteTable('package', [
        '/offers/{id}' => ['class' => TableScreen::class, 'context' => 'offers.show'],
    ]);

    $resolved = NativeRouter::resolve('/offers/7');

    expect($kept)->toBe(1)
        ->and($resolved['class'])->toBe(TableScreen::class)
        ->and($resolved['params'])->toBe(['id' => '7'])
        ->and($resolved['context'])->toBe('offers.show')
        ->and(NativeRouter::routeEntryFor('/offers/7'))->toBe([
            'table' => 'package', 'class' => TableScreen::class, 'context' => 'offers.show',
        ])
        ->and(NativeRouter::isNativeRoute('/offers/7'))->toBeTrue();
});

it('never shadows an app route, even a parameterized one with a literal table route', function () {
    NativeRouter::register('/promos/{id}', DetailScreen::class);
    NativeRouter::setRouteTable('package', [
        '/promos/special' => ['class' => TableScreen::class, 'context' => 'special'],
    ]);

    expect(NativeRouter::resolve('/promos/special')['class'])->toBe(DetailScreen::class)
        ->and(NativeRouter::resolve('/promos/special')['context'])->toBeNull()
        ->and(NativeRouter::routeEntryFor('/promos/special'))->toBeNull();
});

it('drops an entry whose pattern an app route has, and malformed entries', function () {
    NativeRouter::register('/home', CounterScreen::class);

    $kept = NativeRouter::setRouteTable('package', [
        '/home' => ['class' => TableScreen::class],
        '/not-a-screen' => ['class' => stdClass::class],
        '/no-class' => ['context' => 'x'],
        '/fine' => ['class' => TableScreen::class],
    ]);

    expect($kept)->toBe(1)
        ->and(array_keys(NativeRouter::routeTable('package')))->toBe(['/fine'])
        ->and(NativeRouter::resolve('/home')['class'])->toBe(CounterScreen::class);
});

it('replaces a table wholesale, removes it when empty, and clears with the routes', function () {
    NativeRouter::setRouteTable('package', ['/a' => ['class' => TableScreen::class]]);
    NativeRouter::setRouteTable('package', ['/b' => ['class' => TableScreen::class]]);

    expect(NativeRouter::isNativeRoute('/a'))->toBeFalse()
        ->and(NativeRouter::isNativeRoute('/b'))->toBeTrue();

    NativeRouter::setRouteTable('package', []);
    expect(NativeRouter::isNativeRoute('/b'))->toBeFalse();

    NativeRouter::setRouteTable('package', ['/c' => ['class' => TableScreen::class]]);
    NativeRouter::clearRoutes();
    expect(NativeRouter::isNativeRoute('/c'))->toBeFalse()
        ->and(NativeRouter::routeTable('package'))->toBe([]);
});

it('matches tables in the order they were first set', function () {
    NativeRouter::setRouteTable('first', ['/x/{id}' => ['class' => TableScreen::class, 'context' => 'first']]);
    NativeRouter::setRouteTable('second', ['/x/1' => ['class' => TableScreen::class, 'context' => 'second']]);

    expect(NativeRouter::routeEntryFor('/x/1')['table'])->toBe('first');
});

it('hands the entry context to the screen before mount() in the router', function () {
    NativeRouter::setRouteTable('package', [
        '/offers/{id}' => ['class' => TableScreen::class, 'context' => ['screen' => 'offers.show']],
    ]);

    $router = new TableRouter;
    $router->preloadStack([['uri' => '/offers/7']]);

    [$screen] = $router->stackComponents();

    expect($screen->context)->toBe(['screen' => 'offers.show'])
        ->and($screen->contextAtMount)->toBe(['screen' => 'offers.show']);
});

it('hands an app route screen a null context', function () {
    NativeRouter::register('/app-table', TableScreen::class);

    $router = new TableRouter;
    $router->preloadStack([['uri' => '/app-table']]);

    expect($router->stackComponents()[0]->context)->toBeNull();
});

it('hands the entry context to the screen in the test harness, by visit() and by follow()', function () {
    NativeRouter::register('/start', TableScreen::class);
    NativeRouter::setRouteTable('package', [
        '/offers/{id}' => ['class' => TableScreen::class, 'context' => 'offers.show'],
    ]);

    Native::visit('/offers/3')
        ->assertSet('contextAtMount', 'offers.show')
        ->assertSee('Table "offers.show"');

    Native::visit('/start')
        ->assertSet('context', null)
        ->call('open', '/offers/4')
        ->follow()
        ->assertSet('contextAtMount', 'offers.show');
});

it('cannot have setRouteContext() called from a template', function () {
    NativeRouter::register('/start', TableScreen::class);

    $screen = Native::visit('/start');

    expect(fn () => $screen->instance()->__invokeInteraction('setRouteContext', ['forged']))
        ->toThrow(ComponentMethodNotFoundException::class);
});

// ── Screen lifecycle events in the harness ──────────────────────

it('fires the screen lifecycle events the router fires', function () {
    NativeRouter::register('/start', TableScreen::class);
    NativeRouter::register('/next', TableScreen::class);
    NativeRouter::register('/swap', TableScreen::class);

    $fired = [];
    foreach ([ScreenMounted::class, ScreenResumed::class, ScreenUnmounted::class] as $class) {
        Event::listen($class, function ($event) use (&$fired) {
            $fired[] = class_basename($event).':'.$event->uri;
        });
    }

    $start = Native::visit('/start');
    $next = $start->call('open', '/next')->follow();
    $next->goBack();

    expect($fired)->toBe([
        'ScreenMounted:/start',
        'ScreenMounted:/next',
        'ScreenUnmounted:/next',
        'ScreenResumed:/start',
    ]);

    $fired = [];
    $start->instance()->replace('/swap');
    $start->follow();

    expect($fired)->toBe([
        'ScreenMounted:/swap',
        'ScreenUnmounted:/start',
    ]);
});
