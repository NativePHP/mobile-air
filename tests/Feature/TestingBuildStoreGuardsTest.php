<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Concerns\PackagesIos;
use Native\Mobile\Concerns\PublishesToPlayStore;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The two store-side halves of a testing build: iOS declares TestFlight
 * internal testing in the export options, and a Play Store upload refuses the
 * production track outright.
 */
class TestingBuildStoreGuardsTest extends TestCase
{
    protected string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here should reach Google: the arc guard has to turn a
        // production-track upload away before any of the API work starts.
        Http::preventStrayRequests();
        Http::fake();

        $this->projectPath = sys_get_temp_dir().'/nativephp_store_guards_'.uniqid();
        File::makeDirectory($this->projectPath.'/build', 0755, true);
        config(['nativephp.app_id' => 'com.example.app']);
        putenv('EXTRACTED_PROVISIONING_PROFILE_UUID=00000000-0000-0000-0000-000000000000');
    }

    protected function tearDown(): void
    {
        putenv('EXTRACTED_PROVISIONING_PROFILE_UUID');
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_a_testing_build_is_exported_for_internal_testflight_only(): void
    {
        config(['app.env' => 'testing']);

        $this->assertStringContainsString(
            '<key>testFlightInternalTestingOnly</key>',
            $this->exportOptions('app-store')
        );
    }

    public function test_a_production_build_is_exported_without_the_restriction(): void
    {
        config(['app.env' => 'production']);

        $this->assertStringNotContainsString(
            'testFlightInternalTestingOnly',
            $this->exportOptions('app-store')
        );
    }

    public function test_the_restriction_is_only_meaningful_for_app_store_exports(): void
    {
        config(['app.env' => 'testing']);

        $this->assertStringNotContainsString(
            'testFlightInternalTestingOnly',
            $this->exportOptions('ad-hoc')
        );
    }

    public function test_a_testing_build_refuses_the_play_store_production_track(): void
    {
        config(['app.env' => 'testing']);

        [$published, $output] = $this->publish('production');

        $this->assertFalse($published);
        $this->assertStringContainsString('Refusing to publish to the production track', $output);
    }

    public function test_a_testing_build_may_still_reach_a_testing_track(): void
    {
        config(['app.env' => 'testing']);

        // The upload itself has no credentials to work with, so it fails later —
        // what matters is that it was not turned away by the arc guard.
        [, $output] = $this->publish('internal');

        $this->assertStringNotContainsString('Refusing to publish', $output);
    }

    public function test_a_production_build_may_reach_the_production_track(): void
    {
        config(['app.env' => 'production']);

        [, $output] = $this->publish('production');

        $this->assertStringNotContainsString('Refusing to publish', $output);
    }

    private function exportOptions(string $exportMethod): string
    {
        $command = $this->command(['export-method' => $exportMethod]);

        $path = (new ReflectionMethod($command, 'createExportOptions'))->invoke($command, $this->projectPath);

        return File::get($path);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function publish(string $track): array
    {
        $key = $this->projectPath.'/service-account.json';
        $bundle = $this->projectPath.'/app.aab';
        File::put($key, json_encode(['client_email' => 'a@b.c', 'private_key' => 'nope']));
        File::put($bundle, 'not-a-real-bundle');

        $command = $this->command();

        $published = (new ReflectionMethod($command, 'publishToPlayStore'))->invoke($command, [
            'service_account_key' => $key,
            'package_name' => 'com.example.app',
            'bundle_path' => $bundle,
            'track' => $track,
        ]);

        return [$published, $command->buffer->fetch()];
    }

    /**
     * @param  array<string, string>  $options
     */
    private function command(array $options = []): Command
    {
        $command = new class($options) extends Command
        {
            use PackagesIos, PublishesToPlayStore;

            public BufferedOutput $buffer;

            /**
             * @param  array<string, string>  $options
             */
            public function __construct(private array $commandOptions)
            {
                parent::__construct();

                $this->buffer = new BufferedOutput;
                $this->setOutput(new OutputStyle(new ArrayInput([]), $this->buffer));
            }

            public function option($key = null)
            {
                return $this->commandOptions[$key] ?? null;
            }

            protected function getTeamId(?array $iosSigningConfig = null): string
            {
                return 'ABCDE12345';
            }
        };

        $components = new ReflectionProperty(Command::class, 'components');
        $components->setValue($command, new Factory($command->buffer));

        return $command;
    }
}
