<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\View\View;
use Native\Mobile\Attributes\Locked;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Renderless;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class ComponentFeaturesScreen extends NativeComponent
{
    public int $count = 0;

    public int $renders = 0;

    public array $profile = ['name' => 'Taylor'];

    public array $hookLog = [];

    #[Locked]
    public int $accountId = 7;

    public ?string $injected = null;

    public array $events = [];

    public int $renderlessListenerCalls = 0;

    public int $imperativeSkipListenerCalls = 0;

    public ?string $pureEnum = null;

    public int $page = 0;

    public ParityRecord $record;

    public ParityRecord $parityRecord;

    public ParityParentRecord $parentRecord;

    public ParitySoftRecord $childRecord;

    public function resolveAction(
        ParityRecord $record,
        ParityActionService $service,
        ParityStatus $status,
    ): void {
        $this->injected = "{$record->id}:{$service->label}:{$status->value}";
    }

    public function increment(): void
    {
        $this->count++;
    }

    #[Renderless]
    public function incrementRenderless(): void
    {
        $this->count++;
    }

    public function incrementAndSkip(): void
    {
        $this->count++;
        $this->skipRender();
    }

    public function dispatchNormally(): void
    {
        $this->dispatch('parity-saved', id: 10);
    }

    public function dispatchToSelf(): void
    {
        $this->dispatch('parity-saved', id: 11)->self();
    }

    public function dispatchToClass(): void
    {
        $this->dispatch('parity-saved', id: 12)->to(self::class);
    }

    public function dispatchThenNavigate(): void
    {
        $this->dispatch('parity-saved', id: 13);
        $this->navigate('/next');
    }

    public function incrementAndDispatchRenderless(): void
    {
        $this->count++;
        $this->dispatch('renderless-listener');
    }

    public function incrementAndDispatchImperativeSkip(): void
    {
        $this->count++;
        $this->dispatch('imperative-skip-listener');
    }

    #[On('parity-saved')]
    public function recordEvent(int $id, ParityActionService $service): void
    {
        $this->events[] = "{$id}:{$service->label}";
    }

    #[On('renderless-listener')]
    #[Renderless]
    public function recordRenderlessEvent(): void
    {
        $this->renderlessListenerCalls++;
    }

    #[On('imperative-skip-listener')]
    public function recordImperativeSkipEvent(): void
    {
        $this->imperativeSkipListenerCalls++;
        $this->skipRender();
    }

    public function resolvePureEnum(ParityPureStatus $status): void
    {
        $this->pureEnum = $status->name;
    }

    public function updating(string $path, mixed $value): void
    {
        $this->hookLog[] = "updating:{$path}:{$value}";
    }

    public function updatingProfile(mixed $value, ?string $key): void
    {
        $this->hookLog[] = "updatingProfile:{$key}:{$value}";
    }

    public function updatingProfileName(mixed $value, ?string $key): void
    {
        $this->hookLog[] = "updatingProfileName:{$key}:{$value}";
    }

    public function updated(string $path, mixed $value): void
    {
        $this->hookLog[] = "updated:{$path}:{$value}";
    }

    public function updatedProfile(mixed $value, ?string $key): void
    {
        $this->hookLog[] = "updatedProfile:{$key}:{$value}";
    }

    public function updatedProfileName(mixed $value, ?string $key): void
    {
        $this->hookLog[] = "updatedProfileName:{$key}:{$value}";
    }

    protected function secret(): void
    {
        $this->count = 999;
    }

    public function render(): Element|View
    {
        $this->renders++;

        return Column::make(
            Text::make("Count: {$this->count}"),
            Text::make("Renders: {$this->renders}"),
            Text::make('Events: '.implode(',', $this->events)),
            Button::make('Dispatch then navigate')->onPress('dispatchThenNavigate'),
        );
    }
}

class ParityActionService
{
    public string $label = 'container';
}

class ParityRecord implements UrlRoutable
{
    public function __construct(public string $id = '') {}

    public function getRouteKey(): string
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?static
    {
        $suffix = $field === null ? '' : '@'.$field;

        return $value === 'missing' ? null : new static((string) $value.$suffix);
    }

    public function resolveChildRouteBinding($childType, $value, $field): mixed
    {
        return null;
    }
}

class ParityParentRecord implements UrlRoutable
{
    public function __construct(public string $id = '') {}

    public function getRouteKey(): string
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?static
    {
        return new static('parent:'.$value);
    }

    public function resolveChildRouteBinding($childType, $value, $field): mixed
    {
        return new ParitySoftRecord('regular-child:'.$value);
    }

    public function resolveSoftDeletableChildRouteBinding($childType, $value, $field): mixed
    {
        return new ParitySoftRecord('trashed-child:'.$value);
    }
}

class ParitySoftRecord implements UrlRoutable
{
    use SoftDeletes;

    public function __construct(public string $id = '') {}

    public function getRouteKey(): string
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?static
    {
        return new static('regular:'.$value);
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): ?static
    {
        return new static('trashed:'.$value);
    }

    public function resolveChildRouteBinding($childType, $value, $field): mixed
    {
        return null;
    }
}

enum ParityStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}

enum ParityPureStatus
{
    case Active;
}
