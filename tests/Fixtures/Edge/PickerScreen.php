<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\PendingMediaPicker;
use Native\Mobile\PendingPhotoCapture;

/**
 * Fixture for the durable-callbacks suite: every fluent callback shape
 * the media picker supports, plus a private member so the carrier
 * round-trip proves class scope is restored on rebind.
 */
class PickerScreen extends NativeComponent
{
    public ?string $picked = null;

    public string $status = 'idle';

    private string $secretSuffix = '!';

    public function startClosure(): void
    {
        (new PendingMediaPicker)->id('closure-pick')->onSuccess(function (MediaSelected $media) {
            // Captures $this AND touches a private member — the hardest
            // shape to move across a process boundary.
            $this->picked = ($media->files[0]['path'] ?? '?').$this->secretSuffix;
        });
    }

    public function startMethodName(): void
    {
        (new PendingMediaPicker)->id('method-pick')->mediaSelected('onPicked');
    }

    public function startBogusMethod(): void
    {
        (new PendingMediaPicker)->id('bogus-pick')->mediaSelected('noSuchMethod');
    }

    public function onPicked(MediaSelected $media): void
    {
        $this->status = 'picked:'.$media->count;
    }

    /**
     * Both callbacks DELIBERATELY on one line — the same-line durability
     * hazard (serializable-closure extracts code by start line). Arrow
     * functions on purpose: Pint keeps them inline, where it would
     * expand `function () {}` bodies onto separate lines and silently
     * defuse this fixture.
     */
    public function startOneLineChain(): void
    {
        (new PendingPhotoCapture)->id('oneline-pick')->photoTaken(fn () => $this->status = 'taken')->photoCancelled(fn () => $this->status = 'cancelled');
    }

    public function startFirstClass(): void
    {
        (new PendingMediaPicker)->id('fc-pick')->onSuccess($this->onPicked(...));
    }

    /** Auto-UUID id, fixed source line — the stale same-line-mapping case. */
    public function startUuidPicker(): void
    {
        (new PendingMediaPicker)->onSuccess(fn () => $this->status = 'uuid-picked');
    }

    public function startDtoResource(): void
    {
        $dto = new \stdClass;
        $dto->handle = fopen('php://memory', 'r');

        (new PendingMediaPicker)->id('dto-pick')->onSuccess(function () use ($dto) {
            $this->status = 'dto:'.get_debug_type($dto->handle);
        });
    }

    public function startResourceClosure(): void
    {
        $handle = fopen('php://memory', 'r');

        (new PendingMediaPicker)->id('resource-pick')->onSuccess(function () use ($handle) {
            $this->status = 'resource:'.get_debug_type($handle);
        });
    }

    public function startHuge(): void
    {
        $blob = str_repeat('x', 200 * 1024);

        (new PendingMediaPicker)->id('huge-pick')->onSuccess(function () use ($blob) {
            $this->status = 'huge:'.strlen($blob);
        });
    }

    /** Method named after a loadable class — the class_exists hijack bait. */
    public function startClassNamedMethod(): void
    {
        (new PendingMediaPicker)->id('named-pick')->mediaSelected('error');
    }

    public function error(MediaSelected $media): void
    {
        $this->status = 'component-error-method';
    }

    public function startCustomEvent(): void
    {
        (new PendingMediaPicker)->id('custom-pick')
            ->onSuccess(function (CustomPickEvent $event) {
                $this->status = 'custom:'.$event->count;
            })
            ->event(CustomPickEvent::class);
    }

    public function startNestedResource(): void
    {
        $handle = fopen('php://memory', 'r');
        $inner = function () use ($handle) {
            return $handle;
        };

        (new PendingMediaPicker)->id('nested-pick')->onSuccess(function () use ($inner) {
            $this->status = 'nested:'.get_debug_type($inner());
        });
    }

    public function startClosedResource(): void
    {
        $handle = fopen('php://memory', 'r');
        fclose($handle);

        (new PendingMediaPicker)->id('closed-pick')->onSuccess(function () use ($handle) {
            $this->status = 'closed:'.get_debug_type($handle);
        });
    }

    /** THREE closures on one line — the collision path must catch the third too. */
    public function startTripleLine(): void
    {
        (new PendingPhotoCapture)->id('triple-pick')->photoTaken(fn () => $this->status = 't1')->photoCancelled(fn () => $this->status = 't2')->permissionDenied(fn () => $this->status = 't3');
    }

    /** Huge capture then small on ONE line: reg1 size-caps (no cache entry), reg2 must still be caught. */
    public function startHugeThenSmallLine(): void
    {
        $blob = str_repeat('x', 200 * 1024);

        (new PendingPhotoCapture)->id('hts-pick')->photoTaken(fn () => $this->status = 'huge:'.strlen($blob))->photoCancelled(fn () => $this->status = 'small');
    }

    /** Deep but ORDINARY capture (no resource) — must stay durable under the cap. */
    public function startDeepClean(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'leaf']]]]]];

        (new PendingMediaPicker)->id('deepclean-pick')->onSuccess(function () use ($deep) {
            $this->status = 'deepclean:'.count($deep);
        });
    }

    public function startArrayCallback(): void
    {
        (new PendingMediaPicker)->id('array-pick')->onSuccess([$this, 'onPicked']);
    }

    public function startDeepResource(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => fopen('php://memory', 'r')]]]]]];

        // The body must USE the capture, or Pint's lambda_not_used_import
        // fixer strips `use ($deep)` and silently defuses the fixture.
        (new PendingMediaPicker)->id('deep-pick')->onSuccess(function () use ($deep) {
            $this->status = 'deep:'.count($deep);
        });
    }

    public function render(): Element
    {
        return Column::make(
            Text::make('Picked: '.($this->picked ?? 'nothing')),
            Text::make('Status: '.$this->status),
        );
    }
}
