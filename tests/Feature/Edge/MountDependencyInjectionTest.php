<?php

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;

final class MountInjectedClick implements UrlRoutable
{
    public function __construct(public ?int $id = null) {}

    public function getRouteKey(): ?int
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $value === 'missing' ? null : new self((int) $value);
    }

    public function resolveChildRouteBinding($childType, $value, $field): null
    {
        return null;
    }
}

final class MountInjectedService
{
    public string $value = 'from the container';
}

enum MountInjectedState: string
{
    case Active = 'active';
}

final class MountInjectedScreen extends NativeComponent
{
    public MountInjectedClick $click;

    public ?int $clickId = null;

    public bool $propertyWasHydratedBeforeMount = false;

    public string $serviceValue = '';

    public string $label = '';

    public function mount(MountInjectedClick $click, MountInjectedService $service, string $label = 'default'): void
    {
        $this->propertyWasHydratedBeforeMount = $this->click === $click;
        $this->clickId = $click->id;
        $this->serviceValue = $service->value;
        $this->label = $label;
    }

    public function render(): Element|View
    {
        return Text::make("Click {$this->clickId}: {$this->serviceValue} ({$this->label})");
    }
}

final class RouteBoundPropertyScreen extends NativeComponent
{
    public MountInjectedClick $click;

    public string $label = '';

    public MountInjectedState $state;

    public function render(): Element|View
    {
        return Text::make("Property click {$this->click->id}: {$this->label} ({$this->state->value})");
    }
}

final class RouteBoundEnumMountScreen extends NativeComponent
{
    public string $stateValue = '';

    public function mount(MountInjectedState $state): void
    {
        $this->stateValue = $state->value;
    }

    public function render(): Element|View
    {
        return Text::make("Mount state {$this->stateValue}");
    }
}

beforeEach(function () {
    NativeRouter::clearRoutes();
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

it('injects route-bound models, container dependencies, and primitive parameters into mount', function () {
    NativeRouter::register('/counter/{click}/{label}', MountInjectedScreen::class);

    Native::visit('/counter/88/example')
        ->assertSet('clickId', 88)
        ->assertSet('propertyWasHydratedBeforeMount', true)
        ->assertSet('serviceValue', 'from the container')
        ->assertSet('label', 'example')
        ->assertSee('Click 88: from the container (example)');
});

it('hydrates a typed public model property from the matching route parameter', function () {
    NativeRouter::register('/counter/{click}/{label}/{state}', RouteBoundPropertyScreen::class);

    $screen = Native::visit('/counter/88/example/active')
        ->assertSet('label', 'example')
        ->assertSet('state', MountInjectedState::Active)
        ->assertSee('Property click 88: example (active)');

    expect($screen->get('click'))
        ->toBeInstanceOf(MountInjectedClick::class)
        ->id->toBe(88);
});

it('throws a model not found exception when route binding fails', function () {
    NativeRouter::register('/counter/{click}', RouteBoundPropertyScreen::class);

    Native::visit('/counter/missing');
})->throws(ModelNotFoundException::class, MountInjectedClick::class);

it('binds backed enums passed to mount and rejects invalid cases', function () {
    NativeRouter::register('/state/{state}', RouteBoundEnumMountScreen::class);

    Native::visit('/state/active')
        ->assertSet('stateValue', 'active')
        ->assertSee('Mount state active');

    Native::visit('/state/invalid');
})->throws(BackedEnumCaseNotFoundException::class);
