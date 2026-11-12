<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;

/** SignupScreen whose inputs carry both `native:model` and `@change`. */
class CheckedSignupScreen extends SignupScreen
{
    public string $nickname = '';

    public int $marker = 5;

    /** @var array<int, array<int, mixed>> each handler call: [args..., bound property at call time] */
    public array $checks = [];

    public function check(string $value): void
    {
        $this->checks[] = [$value, $this->name];
    }

    public function checkWith(int $marker, string $value): void
    {
        $this->checks[] = [$marker, $value, $this->email];
    }

    public function checkNickname(string $value): void
    {
        $this->checks[] = [$value, $this->nickname];
    }

    public function render(): Element|View
    {
        return view('checked-signup-screen');
    }
}
