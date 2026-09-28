<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\File;
use Native\Mobile\Support\PlatformBuildPool;
use Tests\TestCase;

/**
 * These run real child processes (plain php -r scripts) because the point
 * is how two live processes are read, waited for and stopped.
 */
class PlatformBuildPoolTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nativephp_pool_test_'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_it_runs_both_at_once_and_keeps_their_output_apart(): void
    {
        $lines = [];

        $pool = new PlatformBuildPool($this->dir, function (string $platform, string $line) use (&$lines) {
            $lines[] = "{$platform}: {$line}";
        }, pollMicroseconds: 10_000);

        // Each one only finishes once the other has started, so this only
        // passes if they really run side by side.
        $pool->add('ios', $this->php('touch("ios.started"); $i = 0; while (! file_exists("android.started") && $i++ < 200) usleep(10000); echo "ios one\nios two\n";'), $this->dir.'/ios.log');
        $pool->add('android', $this->php('touch("android.started"); $i = 0; while (! file_exists("ios.started") && $i++ < 200) usleep(10000); echo "android one\npartial";'), $this->dir.'/android.log');

        $started = microtime(true);
        $results = $pool->run();

        $this->assertLessThan(2, microtime(true) - $started);
        $this->assertTrue($results['ios']->successful());
        $this->assertTrue($results['android']->successful());

        $this->assertContains('ios: ios one', $lines);
        $this->assertContains('ios: ios two', $lines);
        $this->assertContains('android: android one', $lines);
        // A last line without a newline still comes through.
        $this->assertContains('android: partial', $lines);

        $this->assertSame("ios one\nios two\n", file_get_contents($this->dir.'/ios.log'));
        $this->assertSame("android one\npartial", file_get_contents($this->dir.'/android.log'));
    }

    public function test_one_failing_platform_does_not_stop_the_other(): void
    {
        $pool = new PlatformBuildPool($this->dir, fn () => null, pollMicroseconds: 10_000);

        $pool->add('ios', $this->php('fwrite(STDERR, "xcodebuild failed\n"); exit(65);'), $this->dir.'/ios.log');
        $pool->add('android', $this->php('usleep(300000); echo "built\n";'), $this->dir.'/android.log');

        $results = $pool->run();

        $this->assertFalse($results['ios']->successful());
        $this->assertSame(65, $results['ios']->exitCode);
        $this->assertStringContainsString('xcodebuild failed', file_get_contents($this->dir.'/ios.log'));

        $this->assertTrue($results['android']->successful());
        $this->assertSame("built\n", file_get_contents($this->dir.'/android.log'));
    }

    public function test_stopping_ends_both_builds_and_marks_them_interrupted(): void
    {
        if (! defined('SIGTERM')) {
            $this->markTestSkipped('Needs POSIX signals.');
        }

        $pool = null;
        $pool = new PlatformBuildPool($this->dir, function (string $platform, string $line) use (&$pool) {
            if ($line === 'ready') {
                $pool->stop();
            }
        }, pollMicroseconds: 10_000);

        // The iOS stand-in starts its own long-running child, like
        // native:run starting xcodebuild, and records its pid.
        $grandchild = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('file_put_contents("grandchild.pid", getmypid()); sleep(30);');
        $pool->add('ios', $this->php('$p = proc_open('.var_export($grandchild, true).', [], $pipes); while (! file_exists("grandchild.pid")) usleep(10000); echo "ready\n"; proc_close($p);'), $this->dir.'/ios.log');
        $pool->add('android', $this->php('sleep(30);'), $this->dir.'/android.log');

        $started = microtime(true);
        $results = $pool->run();

        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertTrue($results['ios']->interrupted);
        $this->assertTrue($results['android']->interrupted);
        $this->assertFalse($results['ios']->successful());

        // The build's own child went too, rather than being left running.
        $grandchildPid = (int) file_get_contents($this->dir.'/grandchild.pid');
        usleep(100_000);
        $this->assertSame('', trim((string) shell_exec('ps -o pid= -p '.$grandchildPid)));
    }

    /**
     * @return array<int, string>
     */
    protected function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }
}
