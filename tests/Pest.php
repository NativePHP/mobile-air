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
 * element tree as an array. The view name is unique per render because
 * the compiler engine caches per path within one app lifecycle, so a
 * reused name would silently re-render the previous template.
 */
function renderEdgeTree(string $blade, array $data = []): array
{
    static $sequence = 0;
    static $run = null;
    $run ??= substr(md5(uniqid('', true)), 0, 8);

    // The run token keeps names unique ACROSS pest processes too: a
    // reused name from an earlier run can hit Blade's compiled cache
    // within the same mtime second and silently serve stale output.
    $name = 'edge-tree-'.$run.'-'.(++$sequence);

    file_put_contents(__DIR__.'/Feature/Edge/views/'.$name.'.blade.php', $blade);

    NativeElementCollector::reset();
    view($name, $data)->render();

    return NativeElementCollector::collect()
        ->toArray(new CallbackRegistry);
}
