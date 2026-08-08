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

    protected function rules(): array
    {
        return [
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

    public function render(): View
    {
        return view('validation-screen');
    }
}
