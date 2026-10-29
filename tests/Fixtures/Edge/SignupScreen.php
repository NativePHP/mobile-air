<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Native\Mobile\Edge\Concerns\ValidatesInput;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class SignupScreen extends NativeComponent
{
    use ValidatesInput;

    public string $name = '';

    public string $email = '';

    public bool $saved = false;

    protected function rules(): array
    {
        return [
            'name' => 'required',
            'email' => 'required|email',
        ];
    }

    protected function validationAttributes(): array
    {
        return ['email' => 'email address'];
    }

    public function updatedEmail(): void
    {
        $this->validateOnly('email');
    }

    public function save(): void
    {
        $this->validate();

        $this->saved = true;
    }

    public function reject(): void
    {
        throw ValidationException::withMessages(['name' => 'That name is taken.']);
    }

    public function render(): Element|View
    {
        return view('signup-screen');
    }
}
