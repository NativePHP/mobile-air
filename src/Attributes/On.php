<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Marks a NativeComponent method as a listener for a native event.
 *
 * Native events originate on the device (Swift/Kotlin) and are delivered to
 * the PHP component over the bridge — e.g. a completed camera capture, a
 * scanned barcode, a finished biometric prompt. Pass the event class; the
 * decorated method is invoked with the event's public properties bound by
 * parameter name:
 *
 *     #[On(PhotoTaken::class)]
 *     public function photoTaken(string $path, string $mimeType, ?string $id): void
 *     {
 *         // ...
 *     }
 *
 * Pass `when` to hear the event only for certain values. The method runs when
 * every key of the filter is in the payload and holds the same value. Values
 * are compared strictly, as they arrived, before the parameter types coerce
 * them. Only a number matches as an int and as a float alike, since a device
 * sends `1.0` as `1`. A dotted key reads a nested value, and a backed enum is
 * compared by its backing value:
 *
 *     #[On(OrderShipped::class, when: ['orderId' => 42])]
 *     public function thisOrderShipped(string $status): void {}
 *
 *     #[On(MessageReceived::class, when: ['sender.id' => 7])]
 *     public function fromSeven(array $sender, string $body): void {}
 *
 *     #[On(ThermalStateChanged::class, when: ['state' => ThermalState::Critical])]
 *     public function tooHot(): void {}
 *
 * A screen that listens for a native event only through filters that do not
 * match is not called and does not re-render, unless something else ran for
 * the event: a closure registered with `->on()`, a fluent callback (such as
 * `Camera::getPhoto()->photoTaken(...)`) or a Laravel listener. It also
 * re-renders when a component event still waits to be delivered. Repeat the
 * attribute to give one method several filters: it runs once when any of
 * them matches. For a component event (`emit()` / `dispatch()`), the filter
 * is matched against the named arguments.
 *
 * Repeatable, so a single method may listen for several events. This is the
 * Livewire-free replacement for the legacy `#[OnNative]` attribute (which
 * extended Livewire's `BaseOn` and therefore required Livewire to be
 * installed).
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class On
{
    public string $event;

    public ?array $when = null;

    public function __construct(string $event, ?array $when = null)
    {
        $this->event = 'native:'.$event;
        $this->when = $when;
    }
}
