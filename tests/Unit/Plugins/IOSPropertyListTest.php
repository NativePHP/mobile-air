<?php

namespace Tests\Unit\Plugins;

use InvalidArgumentException;
use Native\Mobile\Plugins\Compilers\IOS\PropertyList;
use PHPUnit\Framework\TestCase;

class IOSPropertyListTest extends TestCase
{
    public function test_decode_errors_name_the_source_property_list(): void
    {
        $contents = <<<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0">
<dict>
    <key>ExpirationDate</key>
    <date>2030-01-01T00:00:00Z</date>
</dict>
</plist>
PLIST;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('/tmp/NativePHP.entitlements');
        $this->expectExceptionMessage('Unsupported property list element: date');

        (new PropertyList)->decode($contents, '/tmp/NativePHP.entitlements');
    }
}
