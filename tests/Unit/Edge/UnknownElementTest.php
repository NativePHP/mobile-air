<?php

use Illuminate\Support\Facades\Log;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Exceptions\UnknownElementException;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Edge\UnknownElementHint;
use Tests\Fixtures\Edge\UserCardChild;

beforeEach(function () {
    // Nothing installed but unregistered, unless a test says otherwise.
    UnknownElementHint::useUnregisteredTypes([]);
    NativeTagPrecompiler::setActive(true);
    config()->set('app.debug', true);

    $this->precompile = fn (string $blade, array $shortForm = ['column', 'row', 'text']) => (new NativeTagPrecompiler($shortForm))($blade);
});

afterEach(function () {
    UnknownElementHint::useUnregisteredTypes(null);
    NativeTagPrecompiler::setActive(false);
    ComponentRegistry::reset();
});

it('names the package for a bare mobile-ui tag when mobile-ui is not installed', function () {
    ($this->precompile)('<column><outlined-text-input placeholder="Todo" /></column>');
})->throws(
    UnknownElementException::class,
    'Unknown native element <outlined-text-input>. It would not be rendered. It comes from nativephp/mobile-ui. '
    .'Install it with `composer require nativephp/mobile-ui`, then register it with `php artisan native:plugin:register nativephp/mobile-ui`.'
);

it('says to register a plugin that is installed but not registered', function () {
    UnknownElementHint::useUnregisteredTypes(['button' => 'nativephp/mobile-ui']);

    ($this->precompile)('<row><button label="Add" @press="add" /></row>');
})->throws(
    UnknownElementException::class,
    'It comes from nativephp/mobile-ui, which is installed but not registered. Run `php artisan native:plugin:register nativephp/mobile-ui`'
);

it('uses the hint from any installed plugin, not only first-party ones', function () {
    UnknownElementHint::useUnregisteredTypes(['map_view' => 'acme/maps']);

    ($this->precompile)('<map-view lat="1" />');
})->throws(UnknownElementException::class, 'It comes from acme/maps, which is installed but not registered.');

it('points at the new text input names', function () {
    ($this->precompile)('<column><text-input placeholder="x" /></column>');
})->throws(UnknownElementException::class, 'There is no text-input element any more.');

it('tells you to prefix a bare child component', function () {
    ComponentRegistry::register('user-card-child', UserCardChild::class);

    ($this->precompile)('<column><user-card-child /></column>');
})->throws(UnknownElementException::class, '<user-card-child> is a child component. Write it as <native:user-card-child>.');

it('flags any unknown hyphenated tag', function () {
    ($this->precompile)('<column><text-feild /></column>');
})->throws(UnknownElementException::class, 'Unknown native element <text-feild>. It would not be rendered. Check the spelling');

it('leaves registered short-form tags alone', function () {
    $out = ($this->precompile)('<row><button label="Add" /><outlined-text-input /></row>', ['row', 'button', 'outlined-text-input']);

    expect($out)->toContain("::leaf('outlined_text_input'");
});

it('ignores single-word tags that are not plugin elements', function () {
    $out = ($this->precompile)('<column><text>Hello <b>there</b><br/></text></column>');

    expect($out)->toContain('::textOpen(');
});

it('ignores prefixed tags, which the collector checks at render time', function () {
    $out = ($this->precompile)('<native:column><native:outlined-text-input /></native:column>');

    expect($out)->toContain("::leaf('outlined_text_input'");
});

it('ignores markup inside a webview slot', function () {
    $out = ($this->precompile)('<column><native:webview><my-widget></my-widget><button>Go</button></native:webview></column>');

    expect($out)->toContain('<my-widget>');
});

it('does nothing outside a native compile', function () {
    NativeTagPrecompiler::setActive(false);

    expect(($this->precompile)('<outlined-text-input />'))->toBe('<outlined-text-input />');
});

it('logs instead of throwing when debug is off', function () {
    config()->set('app.debug', false);
    Log::spy();

    $out = ($this->precompile)('<column><outlined-text-input /></column>');

    expect($out)->toContain('<outlined-text-input />');
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'Unknown native element <outlined-text-input>'))
        ->once();
});

it('adds the plugin hint to the collector error for prefixed tags', function () {
    NativeElementCollector::leaf('list_item', []);
})->throws(
    UnknownElementException::class,
    'Unknown native element type: list_item. It comes from nativephp/mobile-ui. Install it with `composer require nativephp/mobile-ui`'
);

it('keeps the collector error plain for a type nobody provides', function () {
    try {
        NativeElementCollector::leaf('unknown_widget', []);
    } catch (UnknownElementException $e) {
        expect($e->getMessage())->toBe('Unknown native element type: unknown_widget.');

        return;
    }

    $this->fail('No exception thrown');
});

it('names every unknown tag in one error', function () {
    ($this->precompile)('<row><outlined-text-input /><button label="Add" /><list-item /></row>');
})->throws(
    UnknownElementException::class,
    'Unknown native elements <outlined-text-input>, <button>, <list-item>. They would not be rendered. They come from nativephp/mobile-ui.'
);

it('gives each tag its own hint when they differ', function () {
    try {
        ($this->precompile)('<row><outlined-text-input /><text-feild /></row>');
    } catch (UnknownElementException $e) {
        expect($e->getMessage())
            ->toContain("They would not be rendered.\n<outlined-text-input>: It comes from nativephp/mobile-ui.")
            ->toContain("\n<text-feild>: Check the spelling");

        return;
    }

    $this->fail('No exception thrown');
});
