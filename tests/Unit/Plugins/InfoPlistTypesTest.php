<?php

namespace Tests\Unit\Plugins;

use Native\Mobile\Plugins\Compilers\IOSPluginCompiler;
use PHPUnit\Framework\TestCase;

/**
 * A plugin's `ios.info_plist` values must land in the app's Info.plist as
 * their real plist types.
 *
 * Booleans used to fall through to the string branch and be written as
 * `<string></string>` — `(string) false` is the empty string — which
 * frameworks read as unset. FirebaseAppDelegateProxyEnabled=false and
 * FirebaseAnalyticsCollectionEnabled=false were both silently ignored on
 * device because of it.
 */
class InfoPlistTypesTest extends TestCase
{
    private function inject(array $entries, string $existing = ''): string
    {
        $compiler = new class extends IOSPluginCompiler
        {
            public function __construct() {}

            public function inject(string $plist, array $entries): string
            {
                return $this->injectPlistEntries($plist, $entries);
            }
        };

        $plist = "<?xml version=\"1.0\"?>\n<plist version=\"1.0\">\n<dict>\n{$existing}</dict>\n</plist>\n";

        return $compiler->inject($plist, $entries);
    }

    /** @test */
    public function it_writes_false_as_a_boolean_node(): void
    {
        $out = $this->inject(['FirebaseAppDelegateProxyEnabled' => false]);

        $this->assertStringContainsString(
            "<key>FirebaseAppDelegateProxyEnabled</key>\n\t<false/>",
            $out
        );
        $this->assertStringNotContainsString('<string></string>', $out);
    }

    /** @test */
    public function it_writes_true_as_a_boolean_node(): void
    {
        $this->assertStringContainsString(
            "<key>Flag</key>\n\t<true/>",
            $this->inject(['Flag' => true])
        );
    }

    /** @test */
    public function it_writes_integers_as_integer_nodes(): void
    {
        $this->assertStringContainsString(
            "<key>Count</key>\n\t<integer>3</integer>",
            $this->inject(['Count' => 3])
        );
    }

    /** @test */
    public function it_repairs_a_key_previously_written_as_an_empty_string(): void
    {
        // Apps built before the fix carry the empty-string form; a rebuild
        // must replace it rather than skip the key.
        $out = $this->inject(
            ['FirebaseAnalyticsCollectionEnabled' => false],
            "\t<key>FirebaseAnalyticsCollectionEnabled</key>\n\t<string></string>\n"
        );

        $this->assertStringContainsString('<false/>', $out);
        $this->assertStringNotContainsString('<string></string>', $out);
    }

    /** @test */
    public function it_still_writes_strings_and_arrays(): void
    {
        $out = $this->inject(['NSCameraUsageDescription' => 'Scan a code', 'Modes' => ['a', 'b']]);

        $this->assertStringContainsString('<string>Scan a code</string>', $out);
        $this->assertStringContainsString('<string>a</string>', $out);
        $this->assertStringContainsString('<array>', $out);
    }
}
