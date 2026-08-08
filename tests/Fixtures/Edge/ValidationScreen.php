<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Native\Mobile\Attributes\Validate;
use Native\Mobile\Edge\NativeComponent;

/**
 * Fixture for the validation suite: one eager #[Validate] prop, one
 * rules()-method (on-demand) prop, a wildcard rule, and handlers
 * covering every validate() entry shape.
 */
class ValidationScreen extends NativeComponent
{
    #[Validate('required|min:3')]
    public string $title = '';

    public string $bio = '';

    public array $tags = ['ok', 'go'];

    public bool $saved = false;

    #[Validate('string')]
    #[Validate('min:4')]
    public string $handle = '';

    public bool $boom = false;

    public bool $probed = false;

    public array $tagResult = [];

    public function mount(): void
    {
        // Closure listener whose validation ALWAYS fails — the #[On]
        // method tier below must still hear the same event.
        $this->registerNativeEventListener('probe', function () {
            $this->validate(['bio' => 'required']);
        });
    }

    #[\Native\Mobile\Attributes\On('probe')]
    public function probeHeard(): void
    {
        $this->probed = true;
    }

    public function checkTagReturn(): void
    {
        $this->tagResult = $this->validateOnly('tags.1');
    }

    public function zeroError(): void
    {
        $this->addError('bio', '0');
    }

    protected function rules(): array
    {
        return [
            // Same key as the #[Validate] attribute with a STRICTER rule:
            // the eager sync tier must keep using the attribute's min:3,
            // never this on-demand min:10 (regression: finding 1).
            'title' => 'required|min:10',
            'bio' => 'required|max:5',
            'tags.*' => 'required|string',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $this->saved = true;
    }

    public function saveInline(): void
    {
        $this->validate(['bio' => 'required']);

        $this->saved = true;
    }

    public function saveViaRequest(): void
    {
        $this->validate(ValidationPostRequest::class);

        $this->saved = true;
    }

    public function failManually(): void
    {
        throw ValidationException::withMessages(['title' => 'Custom problem']);
    }

    public function checkTag(): void
    {
        $this->validateOnly('tags.1');
    }

    public function catchThenRethrow(): void
    {
        try {
            $this->validate(['title' => 'required']);
        } catch (ValidationException) {
            // swallowed on purpose — a later manual throw in the SAME
            // dispatch must still reach the bag (regression: finding 4).
        }

        throw ValidationException::withMessages(['bio' => 'Manual bio problem']);
    }

    public function arm(): void
    {
        $this->boom = true;
    }

    public function render(): View
    {
        if ($this->boom) {
            // Render-phase validation failure — must BUBBLE in tests
            // (device paints the error screen), never silently keep a
            // stale frame (regression: finding 6).
            throw ValidationException::withMessages(['title' => 'Render-phase failure']);
        }

        return view('validation-screen');
    }
}
