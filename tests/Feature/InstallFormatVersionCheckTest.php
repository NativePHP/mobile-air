<?php

namespace Tests\Feature;

use Laravel\Prompts\Prompt;
use Native\Mobile\Commands\InstallCommand;
use Native\Mobile\Support\PhpBinaries;
use Tests\TestCase;

/**
 * `native:install` warns when the binaries it is about to install speak a
 * different wire format from the readers this package ships, which is the
 * case where the app launches and renders nothing.
 */
class InstallFormatVersionCheckTest extends TestCase
{
    public function test_it_warns_when_the_binaries_use_another_wire_format(): void
    {
        $expected = PhpBinaries::readerFormatVersion();

        $output = $this->check(['format_version' => $expected + 1]);

        $this->assertStringContainsString(
            'binaries use wire format '.($expected + 1).', but this version of nativephp/mobile expects format '.$expected.'.',
            $output
        );
        $this->assertStringContainsString('render nothing', $output);
    }

    public function test_it_says_nothing_when_the_formats_match(): void
    {
        $this->assertSame('', $this->check(['format_version' => PhpBinaries::readerFormatVersion()]));
    }

    /**
     * Manifests published before php-bin-mobile emitted the field, and a
     * manifest that failed to download, have nothing to compare.
     */
    public function test_it_says_nothing_without_a_format_version_to_compare(): void
    {
        $this->assertSame('', $this->check(['versions' => []]));
        $this->assertSame('', $this->check(null));
    }

    /**
     * Run the check and return what it printed, on one line.
     */
    private function check(?array $manifest): string
    {
        Prompt::fake();

        (new class extends InstallCommand
        {
            public function checkFormatOf(?array $manifest): void
            {
                $this->versionsManifest = $manifest;
                $this->warnIfFormatVersionDiffers();
            }
        })->checkFormatOf($manifest);

        $content = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', Prompt::content());

        return trim(preg_replace('/\s+/', ' ', $content));
    }
}
