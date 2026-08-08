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
 * Repeatable, so a single method may listen for several events. This is the
 * Livewire-free replacement for the legacy `#[OnNative]` attribute (which
 * extended Livewire's `BaseOn` and therefore required Livewire to be
 * installed).
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class On
{
    public string $event;

    public function __construct(string $event)
    {
        // Idempotent prefixing: the bridge docs' older examples spell the
        // prefix out (#[On('native:Foo')]), and double-prefixing silently
        // registers a listener no event name can ever match.
        $this->event = str_starts_with($event, 'native:')
            ? $event
            : 'native:'.$event;
    }
}
