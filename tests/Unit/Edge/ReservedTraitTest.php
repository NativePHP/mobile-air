<?php

use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\NativeComponent;
use Tests\Fixtures\Edge\PackagePlumbing;

/*
 * A package can reserve a trait so that none of the methods it brings
 * onto a component can be named by a template or a remote call. The trait
 * lives in its own file, as a package's would: an aliased method is
 * refused by the file it comes from.
 */

class ReservedTraitScreen extends NativeComponent
{
    use PackagePlumbing {
        packageSend as public aliasedSend;
    }

    public function render(): Element
    {
        return Column::make();
    }

    public function ordinary(): void {}
}

class OverridingReservedTraitScreen extends ReservedTraitScreen
{
    public function packageSend(): string
    {
        return 'overridden';
    }
}

class TraitlessScreenWithSameName extends NativeComponent
{
    public function render(): Element
    {
        return Column::make();
    }

    public function packageSend(): string
    {
        return 'mine';
    }
}

afterEach(fn () => ComponentMethodInvoker::forgetReservedTraits());

it('leaves trait methods callable until the trait is reserved', function () {
    ComponentMethodInvoker::assertCallable(new ReservedTraitScreen, 'packageSend');
})->throwsNoExceptions();

it('refuses a reserved trait method by name, in any case', function (string $method) {
    ComponentMethodInvoker::reserveTrait(PackagePlumbing::class);

    expect(fn () => ComponentMethodInvoker::assertCallable(new ReservedTraitScreen, $method))
        ->toThrow(ComponentMethodNotFoundException::class);
})->with(['packageSend', 'PACKAGESEND', 'packagesend']);

it('refuses a method the trait brought in under an alias', function () {
    ComponentMethodInvoker::reserveTrait(PackagePlumbing::class);

    expect(fn () => ComponentMethodInvoker::assertCallable(new ReservedTraitScreen, 'aliasedSend'))
        ->toThrow(ComponentMethodNotFoundException::class);
});

it('refuses an override of a reserved method on the app class', function () {
    ComponentMethodInvoker::reserveTrait(PackagePlumbing::class);

    expect(fn () => ComponentMethodInvoker::assertCallable(new OverridingReservedTraitScreen, 'packageSend'))
        ->toThrow(ComponentMethodNotFoundException::class);
});

it('refuses a reserved method as a remote call too', function () {
    ComponentMethodInvoker::reserveTrait(PackagePlumbing::class);

    expect(fn () => ComponentMethodInvoker::assertRemoteCallable(new ReservedTraitScreen, 'packageSend', []))
        ->toThrow(ComponentMethodNotFoundException::class);
});

it('keeps the component\'s other methods callable', function () {
    ComponentMethodInvoker::reserveTrait(PackagePlumbing::class);

    ComponentMethodInvoker::assertCallable(new ReservedTraitScreen, 'ordinary');
})->throwsNoExceptions();

it('leaves a same-named method alone on a component that does not use the trait', function () {
    ComponentMethodInvoker::reserveTrait(PackagePlumbing::class);

    ComponentMethodInvoker::assertCallable(new TraitlessScreenWithSameName, 'packageSend');
})->throwsNoExceptions();

it('only reserves traits', function () {
    ComponentMethodInvoker::reserveTrait(ReservedTraitScreen::class);
})->throws(InvalidArgumentException::class);
