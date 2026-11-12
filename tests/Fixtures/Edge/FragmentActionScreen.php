<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\NativeAction;
use Native\Mobile\Edge\Enums\ActionSource;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for taps inside a server-rendered fragment
 * (docs/ASGARD-PROTOCOL.md §10): two regions, a placeholder with its own
 * callback, an optional child with a region of the same name, and
 * methods of every kind a fragment might try to name.
 */
class FragmentActionScreen extends NativeComponent
{
    public string $range = '7d';

    public bool $withChild = false;

    /** @var list<string> the methods that actually ran, in order */
    public array $ran = [];

    /** @var list<array{0:string,1:string}> [source, method] seen by callAction() */
    public array $actions = [];

    protected function callAction(string $method, array $args, ActionSource $source): mixed
    {
        $this->actions[] = [$source->value, $method];

        return parent::callAction($method, $args, $source);
    }

    #[NativeAction]
    public function celebrate(string $who, int $times = 1): void
    {
        $this->ran[] = "celebrate:{$who}:{$times}";
    }

    /** Also #[NativeAction], so a server target that runs it locally would show. */
    #[NativeAction]
    public function shuffle(mixed ...$args): void
    {
        $this->ran[] = 'shuffle';
    }

    #[NativeAction]
    public function toggled(bool $on): void
    {
        $this->ran[] = 'toggled:'.($on ? 'on' : 'off');
    }

    /** Public, but not marked: a fragment may not call it. Screen markup may. */
    public function unmarked(): void
    {
        $this->ran[] = 'unmarked';
    }

    /** The placeholder's own callback. */
    public function retry(): void
    {
        $this->ran[] = 'retry';
    }

    /** Marked, but a lifecycle hook by name, so the guard refuses it. */
    #[NativeAction]
    public function hydrateRange(): void
    {
        $this->ran[] = 'hydrateRange';
    }

    /** Marked, but a class-typed parameter would mean binding or injection. */
    #[NativeAction]
    public function withObject(\stdClass $thing): void
    {
        $this->ran[] = 'withObject';
    }

    /** Optional, so only the parameter's type can refuse it. */
    #[NativeAction]
    public function withOptionalObject(?\stdClass $thing = null): void
    {
        $this->ran[] = 'withOptionalObject';
    }

    /**
     * Overrides a method the interaction path treats as a navigation
     * shortcut, so a fragment reaching it must still go through callAction().
     */
    #[NativeAction]
    public function exitToWeb(string $uri): void
    {
        $this->ran[] = 'exitToWeb:'.$uri;
    }

    #[NativeAction]
    public function withCallable(callable $fn): void
    {
        $this->ran[] = 'withCallable';
    }

    #[NativeAction]
    public function byReference(&$value): void
    {
        $this->ran[] = 'byReference';
    }

    #[NativeAction]
    protected function hidden(): void
    {
        $this->ran[] = 'hidden';
    }

    public function render(): View
    {
        return view('fragment-action-screen');
    }
}
