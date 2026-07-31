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
}
