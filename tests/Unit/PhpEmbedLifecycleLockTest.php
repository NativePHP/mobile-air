<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `php_embed_init` is process-global however many TSRM contexts the four PHP
 * lanes end up owning. Two of them initialising at once corrupt the module
 * registry and the thread-resource table rather than sharing them, and the
 * result is a SIGSEGV that takes the whole process down.
 *
 * The invariant is therefore: every native boot and every native shutdown, on
 * every lane, runs under PHPBridge.embedLifecycleLock. It is asserted here
 * against the shipped Kotlin because there is no JVM test harness in this
 * repository — a source assertion is a guard against the lock being dropped
 * again, not a proof that the runtime is correct.
 */
class PhpEmbedLifecycleLockTest extends TestCase
{
    /**
     * Every call site that has to be serialised, by file.
     *
     * @var array<string, list<string>>
     */
    private const GUARDED_CALLS = [
        'PHPBridge.kt' => [
            'nativePersistentBoot',
            'nativePersistentShutdown',
            'nativeWorkerBoot',
            'nativeWorkerShutdown',
            'nativeEphemeralBoot',
            'nativeEphemeralShutdown',
        ],
        'WebviewPHPRuntime.kt' => [
            'nativeWebviewPhpBoot',
            'nativeWebviewPhpShutdown',
        ],
    ];

    private function bridgeSource(string $file): string
    {
        $path = __DIR__.'/../../resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/'.$file;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Call sites of $callee that are not inside a `withLock { … }` block.
     *
     * Walks the file keeping a stack of the lines that opened each still-open
     * brace, so "inside a lock" means one of the enclosing blocks was opened
     * by a line that takes the lock — which survives reformatting in a way
     * that looking N lines backwards does not.
     *
     * @return list<string> the offending lines, `<line number>: <text>`
     */
    private function unguardedCallSites(string $source, string $callee): array
    {
        $depthOpenedByLock = [];
        $unguarded = [];
        $depth = 0;

        foreach (explode("\n", $source) as $index => $line) {
            $code = trim(preg_replace('~//.*$~', '', $line) ?? '');

            $isCall = str_contains($code, $callee.'(')
                && ! str_starts_with($code, 'external fun')
                && ! str_contains($code, 'fun '.$callee);

            // The call is judged before this line's own braces are counted,
            // so `withLock { nativeXBoot(…) }` on one line still reads as
            // guarded via the same line's opener below.
            $locked = $depthOpenedByLock !== [] || str_contains($code, 'withLock');

            if ($isCall && ! $locked) {
                $unguarded[] = ($index + 1).': '.trim($line);
            }

            $opens = substr_count($code, '{');
            $closes = substr_count($code, '}');

            for ($i = 0; $i < $opens; $i++) {
                $depth++;
                if (str_contains($code, 'withLock')) {
                    $depthOpenedByLock[$depth] = true;
                }
            }

            for ($i = 0; $i < $closes; $i++) {
                unset($depthOpenedByLock[$depth]);
                $depth--;
            }
        }

        return $unguarded;
    }

    /** @test */
    public function every_native_php_boot_and_shutdown_is_serialised(): void
    {
        foreach (self::GUARDED_CALLS as $file => $callees) {
            $source = $this->bridgeSource($file);

            foreach ($callees as $callee) {
                $this->assertStringContainsString(
                    $callee,
                    $source,
                    "{$file} no longer mentions {$callee} — update this test with the lane's new name."
                );

                $this->assertSame(
                    [],
                    $this->unguardedCallSites($source, $callee),
                    "{$callee} is called outside embedLifecycleLock in {$file}. Two concurrent "
                    .'php_embed_init calls SEGV the process; every lane has to take the same lock.'
                );
            }
        }
    }

    /** @test */
    public function the_lock_is_declared_once_and_shared(): void
    {
        $source = $this->bridgeSource('PHPBridge.kt');

        $this->assertStringContainsString(
            'internal val embedLifecycleLock',
            $source,
            'PHPBridge must expose the embed lifecycle lock so every lane, including plugin '
            .'code compiled into this module, takes the same one.'
        );
    }

    /**
     * @test
     *
     * The ephemeral lane is documented for plugin use and had no wrapper at
     * all, so a plugin reached it through the raw external and got no
     * serialisation with it. A supported entry point is half of the fix.
     */
    public function the_ephemeral_lane_has_a_public_wrapper(): void
    {
        $source = $this->bridgeSource('PHPBridge.kt');

        foreach (['fun bootEphemeralRuntime', 'fun runEphemeralArtisan', 'fun shutdownEphemeralRuntime'] as $wrapper) {
            $this->assertStringContainsString($wrapper, $source);
        }
    }
}
