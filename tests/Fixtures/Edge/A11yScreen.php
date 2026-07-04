<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Icon;
use Native\Mobile\Edge\Elements\Image;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\Elements\Toggle;
use Native\Mobile\Edge\NativeComponent;

/**
 * Fixture for assertAccessible(): renders the same screen with every
 * audit rule violated ($accessible = false) or fully labelled (true).
 */
class A11yScreen extends NativeComponent
{
    public bool $accessible = false;

    public function noop(): void
    {
        //
    }

    public function render(): Element|View
    {
        if (! $this->accessible) {
            return Column::make(
                // Icon-only button, no a11y label.
                Button::make()->setProp('leading_icon', 'trash')->onPress('noop'),
                // Clickable icon, no a11y label.
                Icon::make('gear')->onPress('noop'),
                // Clickable image, no alt.
                Image::make('https://example.com/tap.png')->onPress('noop'),
                // Pressable with neither text nor a11y label.
                Pressable::make()->onPress('noop'),
                // Input with no label, placeholder, or a11y label.
                TextInput::make()->onChange("__syncProperty('accessible')"),
                // Toggle with no label of any kind.
                Toggle::make()->onChange('noop'),
            );
        }

        return Column::make(
            Button::make()->setProp('leading_icon', 'trash')->a11yLabel('Delete item')->onPress('noop'),
            Icon::make('gear')->a11yLabel('Settings')->onPress('noop'),
            Image::make('https://example.com/tap.png')->alt('Tap target')->onPress('noop'),
            Pressable::make(Text::make('Open'))->onPress('noop'),
            // Labeled through its child: the alt-text image announces the tile.
            Pressable::make(Image::make('https://example.com/tile.png')->alt('Photo tile'))->onPress('noop'),
            TextInput::make()->placeholder('Search')->onChange("__syncProperty('accessible')"),
            Toggle::make()->setProp('label', 'Enabled')->onChange('noop'),
        );
    }
}
