<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Native\Mobile\Concerns\PackagesIos;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Exporting an archive whose app embeds an app extension (#538). Xcode signs
 * every bundle in the export and refuses one that has no provisioning profile
 * in the export options.
 */
class IosExportAppExtensionTest extends TestCase
{
    protected string $projectPath;

    protected string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_export_extension_'.uniqid();
        $this->archivePath = $this->projectPath.'/nativephp/ios/build/NativePHP.xcarchive';
        $appPath = $this->archivePath.'/Products/Applications/NativePHP.app';
        File::makeDirectory($appPath.'/PlugIns/NotificationService.appex', 0755, true);
        File::makeDirectory($appPath.'/PlugIns/Widgets.appex', 0755, true);
        app()->setBasePath($this->projectPath);

        config(['nativephp.app_id' => 'com.example.app', 'app.env' => 'production']);
        putenv('EXTRACTED_PROVISIONING_PROFILE_UUID=11111111-1111-1111-1111-111111111111');
    }

    protected function tearDown(): void
    {
        putenv('EXTRACTED_PROVISIONING_PROFILE_UUID');
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_the_export_names_a_profile_for_each_app_extension(): void
    {
        Prompt::fake();
        $exportOptions = null;

        Process::fake(function (PendingProcess $process) use (&$exportOptions) {
            $command = (array) $process->command;
            $path = end($command);

            if ($command[0] === 'xcodebuild') {
                $exportOptions = $command[array_search('-exportOptionsPlist', $command, true) + 1];
            }

            if ($command[0] === '/usr/libexec/PlistBuddy') {
                return Process::result(str_contains($path, 'NotificationService')
                    ? "com.example.app.NotificationService\n"
                    : "com.example.app.Widgets\n");
            }

            if ($command[0] === 'security') {
                // Widgets.appex was archived without a profile.
                return str_contains($path, 'NotificationService')
                    ? Process::result("<plist><dict><key>Name</key><string>Notification Service</string>\n\t<key>UUID</key>\n\t<string>22222222-2222-2222-2222-222222222222</string></dict></plist>")
                    : Process::result(errorOutput: 'security: failed to open file', exitCode: 1);
            }

            return Process::result();
        });

        $command = $this->command();
        (new ReflectionMethod($command, 'exportArchive'))->invoke($command, $this->archivePath);

        $this->assertNotNull($exportOptions, 'xcodebuild -exportArchive did not run');
        $this->assertSame([
            'com.example.app' => '11111111-1111-1111-1111-111111111111',
            'com.example.app.NotificationService' => '22222222-2222-2222-2222-222222222222',
        ], $this->provisioningProfiles($exportOptions));
        Prompt::assertOutputContains('Widgets.appex has no provisioning profile');

        // Xcode signs the export; nothing re-signs the bundles itself.
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('--sign', (array) $process->command, true));
    }

    public function test_an_archive_without_extensions_names_only_the_app(): void
    {
        File::deleteDirectory($this->archivePath.'/Products/Applications/NativePHP.app/PlugIns');
        Process::fake();

        $command = $this->command();
        $path = (new ReflectionMethod($command, 'createExportOptions'))->invoke($command, $this->projectPath.'/nativephp/ios', null, $this->archivePath);

        $this->assertSame(['com.example.app' => '11111111-1111-1111-1111-111111111111'], $this->provisioningProfiles($path));
        Process::assertNothingRan();
    }

    /**
     * @return array<string, string>
     */
    private function provisioningProfiles(string $exportOptionsPath): array
    {
        $plist = simplexml_load_file($exportOptionsPath);
        $dict = $plist->dict->xpath('key[.="provisioningProfiles"]/following-sibling::dict[1]')[0];

        $profiles = [];
        foreach ($dict->key as $key) {
            $profiles[(string) $key] = (string) $dict->xpath('key[.="'.$key.'"]/following-sibling::string[1]')[0];
        }

        return $profiles;
    }

    private function command(): Command
    {
        $command = new class extends Command
        {
            use PackagesIos;

            public BufferedOutput $buffer;

            public function __construct()
            {
                parent::__construct();

                $this->buffer = new BufferedOutput;
                $this->setOutput(new OutputStyle(new ArrayInput([]), $this->buffer));
            }

            public function option($key = null)
            {
                return $key === 'export-method' ? 'app-store' : null;
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
