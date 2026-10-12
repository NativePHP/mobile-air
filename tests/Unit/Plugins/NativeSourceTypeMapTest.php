<?php

namespace Tests\Unit\Plugins;

use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Plugins\NativeSourceTypeMap;
use PHPUnit\Framework\TestCase;

class NativeSourceTypeMapTest extends TestCase
{
    private Filesystem $files;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->root = sys_get_temp_dir().'/nativephp-type-map-'.uniqid();
        $this->files->ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_maps_a_kotlin_object_to_its_file_by_fqn(): void
    {
        $this->files->put($this->root.'/ButtonRenderer.kt', <<<'KT'
            package com.test.plugin

            object ButtonRenderer {
                fun Render() {}
            }
            KT);

        $this->assertSame(
            'ButtonRenderer.kt',
            NativeSourceTypeMap::fileFor(
                $this->root,
                'com.test.plugin.ButtonRenderer',
                'android',
                $this->files
            )
        );
    }

    public function test_it_maps_several_kotlin_types_in_one_file(): void
    {
        $this->files->put($this->root.'/Containers.kt', <<<'KT'
            package com.test.plugin

            object ColumnRenderer {}
            object RowRenderer {}
            KT);

        $this->assertSame('Containers.kt', NativeSourceTypeMap::fileFor(
            $this->root, 'com.test.plugin.ColumnRenderer', 'android', $this->files
        ));
        $this->assertSame('Containers.kt', NativeSourceTypeMap::fileFor(
            $this->root, 'com.test.plugin.RowRenderer', 'android', $this->files
        ));
    }

    public function test_it_maps_a_swift_struct_by_type_name(): void
    {
        $this->files->put($this->root.'/NativeUIButtonRenderer.swift', <<<'SWIFT'
            import SwiftUI

            struct NativeUIButtonRenderer: View {
                var body: some View { EmptyView() }
            }
            SWIFT);

        $this->assertSame(
            'NativeUIButtonRenderer.swift',
            NativeSourceTypeMap::fileFor(
                $this->root,
                'NativeUIButtonRenderer',
                'ios',
                $this->files
            )
        );
    }

    public function test_it_does_not_treat_a_matching_filename_as_a_type_mapping(): void
    {
        $this->files->put($this->root.'/GhostRenderer.kt', '// no type declaration');

        $this->assertNull(NativeSourceTypeMap::fileFor(
            $this->root,
            'com.test.plugin.GhostRenderer',
            'android',
            $this->files
        ));
        $this->assertSame(
            'GhostRenderer.kt',
            NativeSourceTypeMap::fileNamedFor(
                $this->root,
                'GhostRenderer',
                'android',
                $this->files
            )
        );
    }

    public function test_it_keeps_a_same_named_file_for_an_unmapped_renderer(): void
    {
        $this->files->put($this->root.'/ButtonRenderer.kt', "package com.test\n\nobject ButtonRenderer {}");
        $this->files->put($this->root.'/GhostRenderer.kt', '// no type declaration');

        $this->assertSame(
            ['GhostRenderer.kt'],
            NativeSourceTypeMap::filesToKeepForUnmapped(
                ['com.test.ButtonRenderer', 'com.test.GhostRenderer'],
                $this->root,
                ['ButtonRenderer.kt'],
                'android',
                $this->files
            )
        );
    }

    public function test_it_returns_null_when_the_type_does_not_exist(): void
    {
        $this->files->put($this->root.'/ButtonRenderer.kt', "package com.test\n\nobject ButtonRenderer {}");

        $this->assertNull(NativeSourceTypeMap::fileFor(
            $this->root, 'com.test.MissingRenderer', 'android', $this->files
        ));
    }

    public function test_it_registers_when_no_subset_was_selected(): void
    {
        $this->assertTrue(NativeSourceTypeMap::shouldRegister(
            'com.test.ButtonRenderer',
            $this->root,
            null,
            'android',
            $this->files
        ));
    }

    public function test_it_registers_only_when_the_defining_file_was_selected(): void
    {
        $this->files->put($this->root.'/ButtonRenderer.kt', "package com.test\n\nobject ButtonRenderer {}");
        $this->files->put($this->root.'/BadgeRenderer.kt', "package com.test\n\nobject BadgeRenderer {}");

        $this->assertTrue(NativeSourceTypeMap::shouldRegister(
            'com.test.ButtonRenderer',
            $this->root,
            ['ButtonRenderer.kt'],
            'android',
            $this->files
        ));
        $this->assertFalse(NativeSourceTypeMap::shouldRegister(
            'com.test.BadgeRenderer',
            $this->root,
            ['ButtonRenderer.kt'],
            'android',
            $this->files
        ));
    }

    public function test_it_keeps_an_unmapped_renderer(): void
    {
        $this->files->put($this->root.'/ButtonRenderer.kt', "package com.test\n\nobject ButtonRenderer {}");

        $this->assertTrue(NativeSourceTypeMap::shouldRegister(
            'com.test.GhostRenderer',
            $this->root,
            ['ButtonRenderer.kt'],
            'android',
            $this->files
        ));
    }

    public function test_it_treats_a_selected_directory_as_covering_its_files(): void
    {
        $this->files->ensureDirectoryExists($this->root.'/ui');
        $this->files->put($this->root.'/ui/ButtonRenderer.kt', "package com.test\n\nobject ButtonRenderer {}");

        $this->assertTrue(NativeSourceTypeMap::shouldRegister(
            'com.test.ButtonRenderer',
            $this->root,
            ['ui'],
            'android',
            $this->files
        ));
    }
}
