<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Nativephp\NativeUi\Elements\ActivityIndicator;
use Nativephp\NativeUi\Elements\Button;
use Nativephp\NativeUi\Elements\Checkbox;
use Nativephp\NativeUi\Elements\Divider;
use Nativephp\NativeUi\Elements\Icon;
use Nativephp\NativeUi\Elements\Image;
use Nativephp\NativeUi\Elements\ProgressBar;
use Nativephp\NativeUi\Elements\Radio;
use Nativephp\NativeUi\Elements\RadioGroup;
use Nativephp\NativeUi\Elements\Select;
use Nativephp\NativeUi\Elements\Slider;
use Nativephp\NativeUi\Elements\Spacer;
use Nativephp\NativeUi\Elements\Text;
use Nativephp\NativeUi\Elements\TextInput;
use Nativephp\NativeUi\Elements\Toggle;
use Nativephp\NativeUi\Elements\Badge;
use Nativephp\NativeUi\Elements\BottomSheet;
use Nativephp\NativeUi\Elements\Card;
use Nativephp\NativeUi\Elements\Chip;
use Nativephp\NativeUi\Elements\ListItem;
use Nativephp\NativeUi\Elements\Tab;
use Nativephp\NativeUi\Elements\TabRow;
use Nativephp\NativeUi\Elements\ButtonGroup;
use Nativephp\NativeUi\Elements\Carousel;
use Native\Mobile\Edge\NativeElementCollector;

beforeEach(function () {
    NativeElementCollector::reset();
    ElementRegistry::reset();
    ElementRegistry::register('button', Button::class);
    ElementRegistry::register('text', Text::class);
    ElementRegistry::register('spacer', Spacer::class);
    ElementRegistry::register('divider', Divider::class);
    ElementRegistry::register('image', Image::class);
    ElementRegistry::register('progress_bar', ProgressBar::class);
    ElementRegistry::register('activity_indicator', ActivityIndicator::class);
    ElementRegistry::register('icon', Icon::class);
    ElementRegistry::register('text_input', TextInput::class);
    ElementRegistry::register('toggle', Toggle::class);
    ElementRegistry::register('checkbox', Checkbox::class);
    ElementRegistry::register('slider', Slider::class);
    ElementRegistry::register('select', Select::class);
    ElementRegistry::register('radio_group', RadioGroup::class);
    ElementRegistry::register('radio', Radio::class);
    ElementRegistry::register('badge', Badge::class);
    ElementRegistry::register('card', Card::class);
    ElementRegistry::register('chip', Chip::class);
    ElementRegistry::register('list_item', ListItem::class);
    ElementRegistry::register('tab_row', TabRow::class);
    ElementRegistry::register('tab', Tab::class);
    ElementRegistry::register('bottom_sheet', BottomSheet::class);
    ElementRegistry::register('button_group', ButtonGroup::class);
    ElementRegistry::register('carousel', Carousel::class);
});

it('builds a single leaf element', function () {
    NativeElementCollector::leaf('spacer', []);

    $element = NativeElementCollector::collect();
    $tree = $element->toArray(new CallbackRegistry);

    expect($tree)->toBe(['type' => 'spacer']);
});

it('builds a single container with no children', function () {
    NativeElementCollector::open('column', []);
    NativeElementCollector::close();

    $element = NativeElementCollector::collect();
    $tree = $element->toArray(new CallbackRegistry);

    expect($tree)->toBe(['type' => 'column']);
});

it('builds a container with leaf children', function () {
    NativeElementCollector::open('column', ['fill' => true]);
    NativeElementCollector::leaf('text', ['text' => 'Hello', 'fontSize' => 20]);
    NativeElementCollector::leaf('spacer', []);
    NativeElementCollector::leaf('divider', []);
    NativeElementCollector::close();

    $element = NativeElementCollector::collect();
    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['layout'])->toBe(['width' => 'fill', 'height' => 'fill']);
    expect($tree['children'])->toHaveCount(3);
    expect($tree['children'][0]['type'])->toBe('text');
    expect($tree['children'][0]['props']['text'])->toBe('Hello');
    expect($tree['children'][0]['props']['font_size'])->toBe(20.0);
    expect($tree['children'][1]['type'])->toBe('spacer');
    expect($tree['children'][2]['type'])->toBe('divider');
});

it('builds nested containers', function () {
    NativeElementCollector::open('column', ['fill' => true, 'center' => true]);
    NativeElementCollector::open('row', ['gap' => '16']);
    NativeElementCollector::leaf('button', ['label' => 'A', '_press' => 'handleA']);
    NativeElementCollector::leaf('button', ['label' => 'B', '_press' => 'handleB']);
    NativeElementCollector::close(); // close row
    NativeElementCollector::close(); // close column

    $registry = new CallbackRegistry;
    $element = NativeElementCollector::collect();
    $tree = $element->toArray($registry);

    expect($tree['type'])->toBe('column');
    expect($tree['layout']['align_items'])->toBe(1);
    expect($tree['layout']['justify_content'])->toBe(1);
    expect($tree['children'])->toHaveCount(1);

    $row = $tree['children'][0];
    expect($row['type'])->toBe('row');
    expect($row['layout']['gap'])->toBe(16.0);
    expect($row['children'])->toHaveCount(2);

    expect($row['children'][0]['props']['label'])->toBe('A');
    expect($row['children'][0]['props']['on_press'])->toBeInt();
    expect($row['children'][1]['props']['label'])->toBe('B');
    expect($row['children'][1]['props']['on_press'])->toBeInt();

    // Callbacks should resolve
    expect($registry->resolve($row['children'][0]['props']['on_press']))->toBe('handleA');
    expect($registry->resolve($row['children'][1]['props']['on_press']))->toBe('handleB');
});

it('applies layout attributes correctly', function () {
    NativeElementCollector::leaf('column', [
        'fillWidth' => true,
        'padding' => '16',
        'margin' => '8',
        'gap' => '12',
        'safeArea' => true,
        'alignItems' => '1',
        'justifyContent' => '2',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout'])->toBe([
        'width' => 'fill',
        'padding' => 16.0,
        'margin' => 8.0,
        'gap' => 12.0,
        'safe_area' => 1,
        'align_items' => 1,
        'justify_content' => 2,
    ]);
});

it('applies style attributes correctly', function () {
    NativeElementCollector::leaf('column', [
        'bg' => '#FF0000',
        'borderRadius' => '12',
        'borderWidth' => '2',
        'borderColor' => '#000000',
        'opacity' => '0.8',
        'elevation' => '4',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['style'])->toBe([
        'bg_color' => '#FF0000',
        'border_radius' => 12.0,
        'border_width' => 2.0,
        'border_color' => '#000000',
        'opacity' => 0.8,
        'elevation' => 4.0,
    ]);
});

it('applies text-specific props', function () {
    NativeElementCollector::leaf('text', [
        'text' => 'Hello World',
        'fontSize' => '24',
        'fontWeight' => '7',
        'color' => '#1a1a2e',
        'textAlign' => '1',
        'maxLines' => '2',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('text');
    expect($tree['props']['text'])->toBe('Hello World');
    expect($tree['props']['font_size'])->toBe(24.0);
    expect($tree['props']['font_weight'])->toBe(7);
    expect($tree['props']['color'])->toBe('#1a1a2e');
    expect($tree['props']['text_align'])->toBe(1);
    expect($tree['props']['max_lines'])->toBe(2);
});

it('applies button-specific props', function () {
    NativeElementCollector::leaf('button', [
        'label' => 'Click Me',
        'color' => '#4CAF50',
        'labelColor' => '#FFFFFF',
        'disabled' => true,
        '_press' => 'handleClick',
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('button');
    expect($tree['props']['label'])->toBe('Click Me');
    expect($tree['props']['color'])->toBe('#4CAF50');
    expect($tree['props']['label_color'])->toBe('#FFFFFF');
    expect($tree['props']['disabled'])->toBeTrue();
    expect($tree['props']['on_press'])->toBeInt();
    expect($registry->resolve($tree['props']['on_press']))->toBe('handleClick');
});

it('applies text input props and callbacks', function () {
    NativeElementCollector::leaf('text_input', [
        'value' => 'current text',
        'placeholder' => 'Enter text...',
        'keyboard' => '1',
        'secure' => true,
        'maxLength' => '100',
        'multiline' => true,
        '_change' => 'onTextChange',
        '_submit' => 'onTextSubmit',
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('text_input');
    expect($tree['props']['value'])->toBe('current text');
    expect($tree['props']['placeholder'])->toBe('Enter text...');
    expect($tree['props']['keyboard'])->toBe(1);
    expect($tree['props']['secure'])->toBeTrue();
    expect($tree['props']['max_length'])->toBe(100);
    expect($tree['props']['multiline'])->toBeTrue();
    expect($registry->resolve($tree['props']['on_change']))->toBe('onTextChange');
    expect($registry->resolve($tree['props']['on_submit']))->toBe('onTextSubmit');
});

it('applies toggle props', function () {
    NativeElementCollector::leaf('toggle', [
        'value' => true,
        'disabled' => true,
        '_change' => 'onToggle',
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('toggle');
    expect($tree['props']['value'])->toBeTrue();
    expect($tree['props']['disabled'])->toBeTrue();
    expect($registry->resolve($tree['props']['on_change']))->toBe('onToggle');
});

it('applies checkbox props', function () {
    NativeElementCollector::leaf('checkbox', [
        'value' => true,
        'label' => 'Accept terms',
        '_change' => 'onAccept',
        'disabled' => false,
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('checkbox');
    expect($tree['props']['value'])->toBeTrue();
    expect($tree['props']['label'])->toBe('Accept terms');
    expect($registry->resolve($tree['props']['on_change']))->toBe('onAccept');
});

it('applies checkbox disabled state', function () {
    NativeElementCollector::leaf('checkbox', [
        'value' => false,
        'disabled' => true,
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('checkbox');
    expect($tree['props']['value'])->toBeFalse();
    expect($tree['props']['disabled'])->toBeTrue();
});

it('applies progress bar props', function () {
    NativeElementCollector::leaf('progress_bar', ['value' => 0.75, 'color' => '#4CAF50', 'trackColor' => '#E0E0E0']);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('progress_bar');
    expect($tree['props']['value'])->toBe(0.75);
    expect($tree['props']['color'])->toBe('#4CAF50');
    expect($tree['props']['track_color'])->toBe('#E0E0E0');
});

it('applies image props', function () {
    NativeElementCollector::leaf('image', [
        'src' => 'https://example.com/img.png',
        'fit' => '2',
        'tintColor' => '#FF0000',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('image');
    expect($tree['props']['src'])->toBe('https://example.com/img.png');
    expect($tree['props']['fit'])->toBe(2);
    expect($tree['props']['tint_color'])->toBe('#FF0000');
});

it('applies scroll view props', function () {
    NativeElementCollector::open('scroll_view', [
        'horizontal' => true,
        'showsIndicators' => false,
    ]);
    NativeElementCollector::leaf('text', ['text' => 'Scrollable']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('scroll_view');
    expect($tree['props']['horizontal'])->toBeTrue();
    expect($tree['props']['shows_indicators'])->toBeFalse();
    expect($tree['children'])->toHaveCount(1);
});

it('applies node-level onPress and onLongPress', function () {
    NativeElementCollector::open('column', [
        '_press' => 'tapColumn',
        '_longPress' => 'longPressColumn',
    ]);
    NativeElementCollector::close();

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['on_press'])->toBeInt();
    expect($tree['on_long_press'])->toBeInt();
    expect($registry->resolve($tree['on_press']))->toBe('tapColumn');
    expect($registry->resolve($tree['on_long_press']))->toBe('longPressColumn');
});

it('throws when collecting without any elements', function () {
    NativeElementCollector::collect();
})->throws(RuntimeException::class, 'No root element was built');

it('throws on unknown element type', function () {
    NativeElementCollector::leaf('unknown_widget', []);
})->throws(RuntimeException::class, 'Unknown native element type: unknown_widget');

it('produces identical tree to programmatic API', function () {
    // Build via collector (simulates Blade rendering)
    NativeElementCollector::open('column', ['fill' => true, 'center' => true]);
    NativeElementCollector::leaf('text', ['text' => 'Count: 5', 'fontSize' => '32', 'fontWeight' => '7', 'color' => '#1a1a2e']);
    NativeElementCollector::open('row', ['gap' => '16']);
    NativeElementCollector::leaf('button', ['label' => '-', '_press' => 'decrement', 'color' => '#FF5722', 'labelColor' => '#FFFFFF']);
    NativeElementCollector::leaf('button', ['label' => '+', '_press' => 'increment', 'color' => '#4CAF50', 'labelColor' => '#FFFFFF']);
    NativeElementCollector::close(); // row
    NativeElementCollector::close(); // column

    $collectorRegistry = new CallbackRegistry;
    $collectorTree = NativeElementCollector::collect()->toArray($collectorRegistry);

    // Build via programmatic API
    $programmatic = \Native\Mobile\Edge\Elements\Column::make(
        \Nativephp\NativeUi\Elements\Text::make('Count: 5')->fontSize(32)->fontWeight(7)->color('#1a1a2e'),
        \Native\Mobile\Edge\Elements\Row::make(
            \Nativephp\NativeUi\Elements\Button::make('-')->onPress('decrement')->color('#FF5722')->labelColor('#FFFFFF'),
            \Nativephp\NativeUi\Elements\Button::make('+')->onPress('increment')->color('#4CAF50')->labelColor('#FFFFFF'),
        )->gap(16),
    )->fill()->center();

    $programmaticRegistry = new CallbackRegistry;
    $programmaticTree = $programmatic->toArray($programmaticRegistry);

    // Trees should be structurally identical
    expect($collectorTree['type'])->toBe($programmaticTree['type']);
    expect($collectorTree['layout'])->toBe($programmaticTree['layout']);

    // Children
    expect($collectorTree['children'])->toHaveCount(2);
    expect($programmaticTree['children'])->toHaveCount(2);

    // Text element
    expect($collectorTree['children'][0]['type'])->toBe($programmaticTree['children'][0]['type']);
    expect($collectorTree['children'][0]['props'])->toBe($programmaticTree['children'][0]['props']);

    // Row
    expect($collectorTree['children'][1]['type'])->toBe($programmaticTree['children'][1]['type']);
    expect($collectorTree['children'][1]['layout'])->toBe($programmaticTree['children'][1]['layout']);

    // Buttons in row
    $collectorButtons = $collectorTree['children'][1]['children'];
    $programmaticButtons = $programmaticTree['children'][1]['children'];
    expect($collectorButtons)->toHaveCount(2);

    // Button labels and colors match
    expect($collectorButtons[0]['props']['label'])->toBe($programmaticButtons[0]['props']['label']);
    expect($collectorButtons[0]['props']['color'])->toBe($programmaticButtons[0]['props']['color']);
    expect($collectorButtons[0]['props']['label_color'])->toBe($programmaticButtons[0]['props']['label_color']);
    expect($collectorButtons[1]['props']['label'])->toBe($programmaticButtons[1]['props']['label']);

    // Callback method names resolve identically
    expect($collectorRegistry->resolve($collectorButtons[0]['props']['on_press']))->toBe('decrement');
    expect($collectorRegistry->resolve($collectorButtons[1]['props']['on_press']))->toBe('increment');
    expect($programmaticRegistry->resolve($programmaticButtons[0]['props']['on_press']))->toBe('decrement');
    expect($programmaticRegistry->resolve($programmaticButtons[1]['props']['on_press']))->toBe('increment');
});

it('applies radio group and radio props', function () {
    NativeElementCollector::open('radio_group', ['value' => 'opt2', '_change' => 'onSelect']);
    NativeElementCollector::leaf('radio', ['radioValue' => 'opt1', 'label' => 'Option 1']);
    NativeElementCollector::leaf('radio', ['radioValue' => 'opt2', 'label' => 'Option 2']);
    NativeElementCollector::leaf('radio', ['radioValue' => 'opt3', 'label' => 'Option 3', 'disabled' => true]);
    NativeElementCollector::close();

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);
    expect($tree['type'])->toBe('radio_group');
    expect($tree['props']['value'])->toBe('opt2');
    expect($registry->resolve($tree['props']['on_change']))->toBe('onSelect');
    expect($tree['children'])->toHaveCount(3);
    expect($tree['children'][0]['props']['value'])->toBe('opt1');
    expect($tree['children'][0]['props']['label'])->toBe('Option 1');
    expect($tree['children'][2]['props']['disabled'])->toBeTrue();
});

it('resets state between collections', function () {
    NativeElementCollector::leaf('spacer', []);
    NativeElementCollector::collect();

    NativeElementCollector::leaf('divider', []);
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('divider');
});
