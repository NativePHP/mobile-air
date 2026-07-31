<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Transition;

class NavScreen extends NativeComponent
{
    public bool $dirty = false;

    public bool $discardShown = false;

    public function pushWithTransition(): void
    {
        $this->navigate('/detail/7', ['from' => 'nav'])
            ->transition(Transition::SlideFromBottom);
    }

    public function replaceWithTransition(): void
    {
        $this->replace('/login')->transition(Transition::Fade);
    }

    public function pushNamed(): void
    {
        $this->navigate($this->route('listing.show', ['id' => 5]));
    }

    public function onBackPressed(): void
    {
        if ($this->dirty) {
            $this->discardShown = true;

            return;
        }

        $this->back();
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make('Nav screen'),
            Button::make('Push with transition')->onPress('pushWithTransition'),
            Button::make('Replace with transition')->onPress('replaceWithTransition'),
            Button::make('Push named')->onPress('pushNamed'),
        );
    }
}
