<?php

namespace Tests\Unit\Traits;

use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Traits\PackagesIos;
use Tests\TestCase;

class PackagesIosExtensionExportTest extends TestCase
{
    private string $archivePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archivePath = sys_get_temp_dir().'/nativephp-extension-archive-'.uniqid().'.xcarchive';
        mkdir($this->archivePath.'/Products/Applications/NativePHP.app/PlugIns/Widget.appex', 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->archivePath);

        parent::tearDown();
    }

    public function test_it_uses_xcode_export_for_archives_with_app_extensions(): void
    {
        $exporter = new class
        {
            use PackagesIos;

            public int $manualExports = 0;

            public int $xcodeExports = 0;

            public object $components;

            public function __construct()
            {
                $this->components = new class
                {
                    public function twoColumnDetail(string $label, string $value): void {}
                };
            }

            public function run(string $archivePath): ?string
            {
                return $this->exportArchive($archivePath);
            }

            protected function exportArchiveManually(string $archivePath): ?string
            {
                $this->manualExports++;

                return '/tmp/manual.ipa';
            }

            protected function exportArchiveWithXcode(string $archivePath): ?string
            {
                $this->xcodeExports++;

                return '/tmp/xcode.ipa';
            }
        };

        $result = $exporter->run($this->archivePath);

        $this->assertSame('/tmp/xcode.ipa', $result);
        $this->assertSame(0, $exporter->manualExports);
        $this->assertSame(1, $exporter->xcodeExports);
    }
}
