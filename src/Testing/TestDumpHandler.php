<?php

namespace Native\Mobile\Testing;

use Native\Mobile\Edge\NativeDumpException;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

/**
 * Test-friendly dd()/dump() handling. On device, a component's dd()
 * paints the dump screen; without a handler under test it would kill
 * the whole PHPUnit process. This handler makes dd() throw
 * NativeDumpException (which the harness converts into a readable test
 * failure carrying the dumped values), while plain dump() keeps working
 * as a normal CLI dump.
 */
class TestDumpHandler
{
    /**
     * Registered on every harness construction — NOT once per process:
     * the framework's test lifecycle resets VarDumper's handler between
     * tests, so a one-shot registration would silently stop applying
     * after the first test.
     */
    public static function register(): void
    {
        VarDumper::setHandler(function ($var) {
            foreach (debug_backtrace(0, 12) as $frame) {
                if (($frame['function'] ?? '') === 'dd') {
                    throw new NativeDumpException(
                        $frame['args'] ?? [$var],
                        $frame['file'] ?? 'unknown',
                        $frame['line'] ?? 0,
                    );
                }
            }

            // Plain dump() — behave like the default CLI handler.
            $dumper = new CliDumper;
            $dumper->dump((new VarCloner)->cloneVar($var));
        });
    }

    /**
     * Whether register() can take effect in this process. When it can't,
     * a component's dd() dumps and exits the test run as it would in any
     * other Laravel test.
     *
     * Two things are known to get in the way:
     *  - Laravel Herd's dump interception, when it watches CLI dumps. Its
     *    auto_prepend_file aliases Symfony's VarDumper to a class whose
     *    setHandler() does nothing, and defines its own global dd() that
     *    never calls a handler.
     *  - VAR_DUMPER_FORMAT being set. Symfony ignores setHandler() then.
     */
    public static function canCapture(): bool
    {
        $probe = static fn () => null;
        $previous = VarDumper::setHandler($probe);

        return VarDumper::setHandler($previous) === $probe;
    }
}
