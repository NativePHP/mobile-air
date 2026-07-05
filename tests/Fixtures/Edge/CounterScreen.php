<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\Elements\Toggle;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Support\NativeCallbacks;

class CounterScreen extends NativeComponent
{
    public int $count = 0;

    public string $query = '';

    public string $lastHook = '';

    public bool $enabled = false;

    public array $pings = [];

    public ?float $lat = null;

    public int $resumes = 0;

    public function onResume(): void
    {
        $this->resumes++;
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function add(int $by): void
    {
        $this->count += $by;
    }

    public function setEnabled(bool $value): void
    {
        $this->enabled = $value;
    }

    public function updatedQuery(string $value): void
    {
        $this->lastHook = "query:{$value}";
    }

    #[Computed]
    public function doubled(): int
    {
        return $this->count * 2;
    }

    #[On(PingReceived::class)]
    public function onPing(string $message): void
    {
        $this->pings[] = $message;
    }

    public function locate(): void
    {
        $response = nativephp_call('Geolocation.GetCurrentPosition', json_encode(['fine' => true]));
        $decoded = json_decode($response ?? 'null', true);

        $this->lat = $decoded['latitude'] ?? null;
    }

    public function openDetail(): void
    {
        $this->navigate('/detail/7', ['from' => 'counter']);
    }

    public function leaveToWeb(): void
    {
        $this->exitToWeb('/settings');
    }

    /**
     * Registers a one-shot fluent callback for PingReceived — the same
     * shape the Pending* builders use (Camera::getPhoto()->photoTaken()).
     */
    public function awaitPing(): void
    {
        NativeCallbacks::register(
            'ping-capture',
            PingReceived::class,
            function ($event) {
                $this->pings[] = 'cb:'.$event->message;
            }
        );
    }

    public function boom(): void
    {
        dd('kaboom', $this->count);
    }

    public function render(): Element|View
    {
        $tree = Column::make(
            Text::make("Count: {$this->count}"),
            Text::make("Doubled: {$this->doubled}"),
            Text::make("Query: {$this->query}"),
            Button::make('Increment')->onPress('increment')->ref('increment-btn'),
            Button::make('Add five')->onPress('add(5)'),
            Button::make('Open detail')->onPress('openDetail'),
            Toggle::make()->onChange('setEnabled'),
            TextInput::make()->onChange("__syncProperty('query')")->ref('query-input'),
        );

        if ($this->enabled) {
            $tree->addChild(Text::make('Feature enabled'));
        }

        return $tree;
    }
}
