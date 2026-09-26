<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\PackageCommand;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A build made outside the production environment is a testing build, and both
 * stores are told so at package time: Google Play through the manifest's
 * largest release audience, App Store Connect through TestFlight internal
 * testing. Without those declarations the same artifact can be promoted by
 * hand to a public release.
 */
class ReleaseAudienceTest extends TestCase
{
    protected string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_release_audience_'.uniqid();
        File::makeDirectory($this->projectPath.'/nativephp/android/app/src/main', 0755, true);
        app()->setBasePath($this->projectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_a_testing_build_declares_a_non_production_audience_to_play(): void
    {
        config(['app.env' => 'testing']);
        $this->writeManifest();

        $this->updateReleaseAudience();

        // Play reads the audience from the name's suffix and requires an empty
        // value; anything else is ignored and every track stays open.
        $this->assertMatchesRegularExpression(
            '/<meta-data\s+android:name="com\.google\.android\.play\.largest_release_audience\.NONPRODUCTION"\s+android:value=""\s*\/>/',
            $this->manifest()
        );
    }

    public function test_the_declaration_sits_inside_the_application_element(): void
    {
        config(['app.env' => 'testing']);
        $this->writeManifest();

        $this->updateReleaseAudience();

        $this->assertMatchesRegularExpression(
            '/<application[^>]*>.*largest_release_audience.*<\/application>/s',
            $this->manifest()
        );
    }

    public function test_a_production_build_carries_no_ceiling(): void
    {
        config(['app.env' => 'production']);
        $this->writeManifest();

        $this->updateReleaseAudience();

        $this->assertStringNotContainsString('largest_release_audience', $this->manifest());
    }

    public function test_moving_back_to_production_strips_a_stale_declaration(): void
    {
        $this->writeManifest();

        config(['app.env' => 'testing']);
        $this->updateReleaseAudience();
        $this->assertStringContainsString('largest_release_audience.NONPRODUCTION', $this->manifest());

        config(['app.env' => 'production']);
        $this->updateReleaseAudience();
        $this->assertStringNotContainsString('largest_release_audience', $this->manifest());
    }

    public function test_a_developer_authored_audience_is_left_alone(): void
    {
        File::put($this->manifestPath(), <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="NativePHP">
        <meta-data
            android:name="com.google.android.play.largest_release_audience.CLOSED_TESTING"
            android:value="" />
        <activity android:name=".MainActivity" />
    </application>
</manifest>
XML);

        config(['app.env' => 'production']);
        $this->updateReleaseAudience();
        $this->assertStringContainsString('largest_release_audience.CLOSED_TESTING', $this->manifest());

        config(['app.env' => 'testing']);
        $this->updateReleaseAudience();
        $this->assertStringContainsString('largest_release_audience.CLOSED_TESTING', $this->manifest());
        $this->assertStringContainsString('largest_release_audience.NONPRODUCTION', $this->manifest());
    }

    public function test_repeated_testing_builds_declare_the_audience_once(): void
    {
        config(['app.env' => 'testing']);
        $this->writeManifest();

        $this->updateReleaseAudience();
        $this->updateReleaseAudience();

        $this->assertSame(1, substr_count($this->manifest(), 'largest_release_audience'));
    }

    public function test_a_manifest_that_does_not_exist_is_left_alone(): void
    {
        config(['app.env' => 'testing']);

        $this->updateReleaseAudience();

        $this->assertFileDoesNotExist($this->manifestPath());
    }

    public function test_only_production_escapes_the_ceiling(): void
    {
        $audience = new ReflectionMethod(PackageCommand::class, 'largestReleaseAudience');
        $internal = new ReflectionMethod(PackageCommand::class, 'restrictToInternalTestFlight');

        $command = (new ReflectionClass(PackageCommand::class))->newInstanceWithoutConstructor();

        foreach (['local', 'testing', 'staging'] as $env) {
            config(['app.env' => $env]);
            $this->assertSame('NONPRODUCTION', $audience->invoke($command), $env);
            $this->assertTrue($internal->invoke($command), $env);
        }

        config(['app.env' => 'production']);
        $this->assertNull($audience->invoke($command));
        $this->assertFalse($internal->invoke($command));
    }

    private function manifestPath(): string
    {
        return $this->projectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
    }

    private function manifest(): string
    {
        return File::get($this->manifestPath());
    }

    private function writeManifest(): void
    {
        File::put($this->manifestPath(), <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="NativePHP">
        <activity android:name=".MainActivity" />
    </application>
</manifest>
XML);
    }

    private function updateReleaseAudience(): void
    {
        $command = (new ReflectionClass(PackageCommand::class))->newInstanceWithoutConstructor();

        (new ReflectionMethod(PackageCommand::class, 'updateReleaseAudience'))->invoke($command);
    }
}
