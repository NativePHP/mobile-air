<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\ClientAction;
use Native\Mobile\Edge\Enums\ActionSource;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture for calls whose method name and arguments come from
 * outside the app (ActionSource::Remote). Each method is one case the
 * remote guard has to accept or refuse.
 */
class RemoteActionScreen extends NativeComponent
{
    /** @var list<string> the handlers that actually ran */
    public array $ran = [];

    /** @var list<array{0:string,1:string}> [source, method], in order */
    public array $actions = [];

    protected function callAction(string $method, array $args, ActionSource $source): mixed
    {
        $this->actions[] = [$source->value, $method];

        return parent::callAction($method, $args, $source);
    }

    #[ClientAction]
    public function celebrate(string $who, int $times = 1): void
    {
        $this->ran[] = "celebrate:{$who}:{$times}";
    }

    #[ClientAction]
    public function plainValues(?string $label, float $amount, bool|int $flag, array $tags, mixed ...$rest): void
    {
        $this->ran[] = 'plainValues:'.json_encode([$label, $amount, $flag, $tags, $rest]);
    }

    /** Public, but not marked: a remote call may not reach it. A template may. */
    public function unmarked(): void
    {
        $this->ran[] = 'unmarked';
    }

    /** Marked, but a lifecycle hook by name, so the guard refuses it. */
    #[ClientAction]
    public function hydrateRange(): void
    {
        $this->ran[] = 'hydrateRange';
    }

    /** Marked, but a class-typed parameter would mean binding or injection. */
    #[ClientAction]
    public function withObject(\stdClass $thing): void
    {
        $this->ran[] = 'withObject';
    }

    /** Optional, so only the parameter's type can refuse it. */
    #[ClientAction]
    public function withOptionalObject(?\stdClass $thing = null): void
    {
        $this->ran[] = 'withOptionalObject';
    }

    /**
     * Overrides a method the interaction path treats as a navigation
     * shortcut, so a remote call reaching it must still go through
     * callAction().
     */
    #[ClientAction]
    public function exitToWeb(string $uri): void
    {
        $this->ran[] = 'exitToWeb:'.$uri;
    }

    #[ClientAction]
    public function withCallable(callable $fn): void
    {
        $this->ran[] = 'withCallable';
    }

    #[ClientAction]
    public function byReference(&$value): void
    {
        $this->ran[] = 'byReference';
    }

    #[ClientAction]
    protected function hidden(): void
    {
        $this->ran[] = 'hidden';
    }

    public function render(): View
    {
        return view('remote-action-screen');
    }
}
