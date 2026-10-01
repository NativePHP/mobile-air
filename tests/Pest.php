<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(
    TestCase::class,
)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Render a Blade string through the native EDGE pipeline and return the
 * element tree as an array. The helper owns its whole file lifecycle:
 * it provisions the scratch view, registers the location once, and
 * removes both the source and the compiled artifact afterwards.
 * Callers only toggle the precompiler, the thing under test.
 *
 * View names are random per call so a name can never collide with an
 * earlier run's different content inside Blade's compiled cache,
 * which compares mtimes at one second granularity.
 */
function renderEdgeTree(string $blade, array $data = []): array
{
    $viewPath = __DIR__.'/Feature/Edge/views';
    @mkdir($viewPath, 0755, true);

    if (! in_array($viewPath, app('view')->getFinder()->getPaths())) {
        app('view')->addLocation($viewPath);
    }

    $name = 'edge-tree-'.bin2hex(random_bytes(8));
    $source = $viewPath.'/'.$name.'.blade.php';
    file_put_contents($source, $blade);

    try {
        NativeElementCollector::reset();
        view($name, $data)->render();

        return NativeElementCollector::collect()
            ->toArray(new CallbackRegistry);
    } finally {
        @unlink(app('blade.compiler')->getCompiledPath($source));
        @unlink($source);
    }
}
