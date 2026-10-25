<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
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
    private const URL = 'https://bin.nativephp.com/main/8.4/ios/ios-3.1.0-php8.4.27.zip';

    protected string $projectPath;

    protected BinariesInstaller $installer;

    protected BufferedConsoleOutput $output;

    /** @var list<array<string, mixed>> */
    protected array $requests = [];

    protected string $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/nativephp_install_checksum_test_'.uniqid();
        File::ensureDirectoryExists($this->projectPath.'/nativephp/ios');
        app()->setBasePath($this->projectPath);

        $this->output = new BufferedConsoleOutput;
        Prompt::setOutput($this->output);

        $this->archive = $this->zip(['Libraries/libphp.a', 'Include/php/main/php.h']);

        $this->installer = new BinariesInstaller;
        $this->installer->versionsManifest = [
            'versions' => [
                '8.4' => [
                    'php_version' => '8.4.27',
                    'ios' => [self::URL],
                    'files' => [
                        'ios' => [[
                            'url' => self::URL,
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

        $this->assertSame([], $this->requests);
        $this->assertFileExists($this->projectPath.'/nativephp/ios/Libraries/libphp.a');
    }

    public function test_a_cached_archive_that_does_not_match_is_downloaded_again(): void
    {
        $this->serve([new Response(200, [], $this->archive)]);
        $this->cache(substr($this->archive, 0, 100));

        $this->installer->installIos();

        $this->assertCount(1, $this->requests);
        $this->assertSame($this->archive, file_get_contents($this->cachedPath()));
        $this->assertFileExists($this->projectPath.'/nativephp/ios/Libraries/libphp.a');
    }

    public function test_a_download_that_does_not_match_is_thrown_away(): void
    {
        $other = $this->zip(['Libraries/libphp.a', 'Include/php/main/php.h', 'Libraries/extra.a']);
        $this->serve([new Response(200, [], $other)]);

        $this->installer->installIos();

        $this->assertStringContainsString(
            'The downloaded '.basename(self::URL)." doesn't match the release manifest: it is ".strlen($other).' bytes',
            preg_replace('/\s+/', ' ', $this->output->fetch())
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
        $this->cache($this->archive);

        $this->installer->installIos();

        $this->assertSame([], $this->requests);
        $this->assertFileExists($this->projectPath.'/nativephp/ios/Libraries/libphp.a');
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

    private function cachedPath(): string
    {
        return base_path('nativephp/binaries').'/'.basename(self::URL);
    }

    private function cache(string $bytes): void
    {
        File::ensureDirectoryExists(base_path('nativephp/binaries'));
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
