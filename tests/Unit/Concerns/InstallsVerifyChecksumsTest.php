<?php

namespace Tests\Unit\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
use Native\Mobile\Support\PhpBinaries;
use Tests\Support\BinariesInstaller;
use Tests\TestCase;
use ZipArchive;

/**
 * The installers only use an archive that matches the checksum and size the
 * manifest publishes for it, whether it came from the cache or was just
 * downloaded.
 */
class InstallsVerifyChecksumsTest extends TestCase
{
    protected string $projectPath;

    protected BinariesInstaller $installer;

    /** @var list<array<string, mixed>> */
    protected array $requests = [];

    protected string $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_install_checksum_test_'.uniqid();
        File::ensureDirectoryExists($this->projectPath.'/nativephp/ios');
        File::ensureDirectoryExists($this->projectPath.'/nativephp/android');
        app()->setBasePath($this->projectPath);

        Prompt::setOutput(new BufferedConsoleOutput);

        $this->archive = $this->zip(['Libraries/libphp.a', 'Include/php/main/php.h']);

        $this->installer = new BinariesInstaller;
        $this->installer->versionsManifest = [
            'versions' => [
                '8.4' => [
                    'php_version' => '8.4.27',
                    'ios' => [$this->archiveUrl()],
                    'files' => [
                        'ios' => [[
                            'url' => $this->archiveUrl(),
                            'sha256' => hash('sha256', $this->archive),
                            'size' => strlen($this->archive),
                        ]],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_a_cached_archive_that_matches_is_installed_without_a_download(): void
    {
        $this->serve([]);
        $this->cache($this->archive);

        $this->installer->installIos();

        $this->assertSame([], $this->installer->failures());
        $this->assertSame([], $this->requests);
        $this->assertFileExists($this->projectPath.'/nativephp/ios/Libraries/libphp.a');
    }

    public function test_a_cached_archive_that_does_not_match_is_downloaded_again(): void
    {
        $this->serve([new Response(200, [], $this->archive)]);
        $this->cache(substr($this->archive, 0, 100));

        $this->installer->installIos();

        $this->assertSame([], $this->installer->failures());
        $this->assertCount(1, $this->requests);
        $this->assertSame($this->archive, file_get_contents($this->cachedPath()));
        $this->assertFileExists($this->projectPath.'/nativephp/ios/Libraries/libphp.a');
    }

    public function test_a_download_that_does_not_match_is_thrown_away(): void
    {
        $other = $this->zip(['Libraries/libphp.a', 'Include/php/main/php.h', 'Libraries/extra.a']);
        $this->serve([new Response(200, [], $other)]);

        $this->installer->installIos();

        $this->assertCount(1, $this->installer->failures());
        $this->assertStringStartsWith(
            'The downloaded '.basename($this->archiveUrl())." doesn't match the release manifest: it is ".strlen($other).' bytes',
            $this->installer->failures()[0]
        );
        $this->assertFileDoesNotExist($this->cachedPath());
        $this->assertFileDoesNotExist($this->projectPath.'/nativephp/ios/Libraries/libphp.a');
    }

    /**
     * Manifests from before checksums existed: the cached archive is used as
     * it always was.
     */
    public function test_without_a_published_checksum_the_cached_archive_is_used(): void
    {
        unset($this->installer->versionsManifest['versions']['8.4']['files']);
        $this->serve([]);
        $this->cache(substr($this->archive, 0, -1).'x');

        $this->installer->installIos();

        $this->assertSame([], $this->requests);
        $this->assertNotContains(
            true,
            array_map(fn ($failure) => str_contains($failure, 'release manifest'), $this->installer->failures())
        );
    }

    /**
     * @param  list<Response>  $responses
     */
    private function serve(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));

        $this->installer->client = new Client(['handler' => $stack]);
    }

    private function archiveUrl(): string
    {
        return PhpBinaries::HOST.'/main/8.4/ios/ios-'.PhpBinaries::release().'-php8.4.27.zip';
    }

    private function cachedPath(): string
    {
        return PhpBinaries::cacheDirectory().'/'.basename($this->archiveUrl());
    }

    private function cache(string $bytes): void
    {
        File::ensureDirectoryExists(PhpBinaries::cacheDirectory());
        file_put_contents($this->cachedPath(), $bytes);
    }

    /**
     * @param  string[]  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nativephp_zip_');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $file) {
            $zip->addFromString($file, $file);
        }

        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
