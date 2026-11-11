<?php

use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Exceptions\ElementRegistrationException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Edge\CustomEventElement;
use Tests\Fixtures\Edge\ErrorProbeElement;

it('registers core elements under the core owner and locks once the app has booted', function () {
    expect(ElementRegistry::owner('text'))->toBe(ElementRegistry::CORE)
        ->and(ElementRegistry::get('text'))->toBe(Text::class)
        ->and(ElementRegistry::owner('column'))->toBe(ElementRegistry::CORE)
        ->and(ElementRegistry::isLocked())->toBeTrue();
});

it('records who registered a type', function () {
    ElementRegistry::reset();

    ElementRegistry::register('acme_widget', CustomEventElement::class, 'acme/widgets');

    expect(ElementRegistry::owner('acme_widget'))->toBe('acme/widgets')
        ->and(ElementRegistry::resolve('acme_widget'))->toBeInstanceOf(CustomEventElement::class);
});

it('files a registration without an owner under unknown', function () {
    ElementRegistry::reset();

    ElementRegistry::register('acme_widget', CustomEventElement::class);

    expect(ElementRegistry::owner('acme_widget'))->toBe(ElementRegistry::UNKNOWN);
});

it('refuses a type another owner already holds', function () {
    ElementRegistry::reset();
    ElementRegistry::register('acme_widget', CustomEventElement::class, 'acme/widgets');

    try {
        ElementRegistry::register('acme_widget', ErrorProbeElement::class, 'evil/widgets');
        $this->fail('Expected the registration to be refused.');
    } catch (ElementRegistrationException $e) {
        expect($e->type)->toBe('acme_widget')
            ->and($e->currentOwner)->toBe('acme/widgets')
            ->and($e->attemptedOwner)->toBe('evil/widgets')
            ->and($e->getMessage())->toContain("Element type 'acme_widget' is already registered by acme/widgets")
            ->and($e->getMessage())->toContain('evil/widgets tried to register '.ErrorProbeElement::class);
    }

    expect(ElementRegistry::get('acme_widget'))->toBe(CustomEventElement::class);
});

it('refuses another owner even when it brings the same class', function () {
    ElementRegistry::register('text', Text::class, 'acme/text');
})->throws(ElementRegistrationException::class, "Element type 'text' is already registered by core");

it('refuses the same owner swapping in a different class', function () {
    ElementRegistry::register('text', ErrorProbeElement::class, ElementRegistry::CORE);
})->throws(ElementRegistrationException::class, "It can't be registered again as ".ErrorProbeElement::class);

it('treats the same registration again as a no-op, even when locked', function () {
    expect(ElementRegistry::isLocked())->toBeTrue();

    ElementRegistry::register('text', Text::class, ElementRegistry::CORE);
    ElementRegistry::register('column', '\\'.Column::class, ElementRegistry::CORE);

    expect(ElementRegistry::owner('text'))->toBe(ElementRegistry::CORE)
        ->and(ElementRegistry::get('column'))->toBe(Column::class);
});

it('refuses new types once locked', function () {
    try {
        ElementRegistry::register('acme_widget', CustomEventElement::class, 'acme/widgets');
        $this->fail('Expected the registration to be refused.');
    } catch (ElementRegistrationException $e) {
        expect($e->type)->toBe('acme_widget')
            ->and($e->currentOwner)->toBeNull()
            ->and($e->attemptedOwner)->toBe('acme/widgets')
            ->and($e->getMessage())->toContain('the element registry is locked once the app has booted');
    }

    expect(ElementRegistry::has('acme_widget'))->toBeFalse();
});

it('lets tests swap an element in over any owner and the lock', function () {
    ElementRegistry::swap('text', ErrorProbeElement::class);
    ElementRegistry::swap('acme_widget', CustomEventElement::class);

    expect(ElementRegistry::get('text'))->toBe(ErrorProbeElement::class)
        ->and(ElementRegistry::owner('text'))->toBe(ElementRegistry::TEST)
        ->and(ElementRegistry::get('acme_widget'))->toBe(CustomEventElement::class);
});

it('hands a swapped type back to its owner on the next boot', function () {
    ElementRegistry::swap('text', ErrorProbeElement::class);

    // What the next test's app boot does in a suite that doesn't reset.
    ElementRegistry::register('text', Text::class, ElementRegistry::CORE);

    expect(ElementRegistry::get('text'))->toBe(Text::class)
        ->and(ElementRegistry::owner('text'))->toBe(ElementRegistry::CORE);
});

it('lets tests fake the whole registry', function () {
    ElementRegistry::fake(['text' => Text::class]);

    expect(ElementRegistry::all())->toBe(['text' => Text::class])
        ->and(ElementRegistry::isLocked())->toBeFalse();
});

it('keeps the test helpers out of a running app', function () {
    // A separate PHP process with the package autoloaded but no test runner,
    // which is what a running app looks like to the registry.
    $script = sys_get_temp_dir().'/element-registry-'.uniqid().'.php';
    file_put_contents($script, <<<'PHP'
        <?php
        require $argv[1];

        use Native\Mobile\Edge\ElementRegistry;

        foreach ([
            'swap' => fn () => ElementRegistry::swap('text', 'Acme\\Text'),
            'fake' => fn () => ElementRegistry::fake(['text' => 'Acme\\Text']),
            'reset' => fn () => ElementRegistry::reset(),
        ] as $helper => $call) {
            try {
                $call();
                echo "{$helper}: allowed\n";
            } catch (LogicException $e) {
                echo "{$helper}: {$e->getMessage()}\n";
            }
        }
        PHP);

    try {
        $process = new Process([PHP_BINARY, $script, dirname(__DIR__, 3).'/vendor/autoload.php']);
        $process->mustRun();
    } finally {
        @unlink($script);
    }

    expect(explode("\n", trim($process->getOutput())))->toBe([
        'swap: ElementRegistry::swap() is only available in tests. Declare elements in a plugin\'s nativephp.json `components` instead.',
        'fake: ElementRegistry::reset() is only available in tests. Declare elements in a plugin\'s nativephp.json `components` instead.',
        'reset: ElementRegistry::reset() is only available in tests. Declare elements in a plugin\'s nativephp.json `components` instead.',
    ]);
});
