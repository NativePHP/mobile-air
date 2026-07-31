<?php

namespace Tests\Unit\Traits;

use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\PlatformFileOperations;
use Orchestra\Testbench\TestCase;

class PlatformFileOperationsTest extends TestCase
{
    use PlatformFileOperations;

    protected string $testSourceDir;

    protected string $testDestDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSourceDir = sys_get_temp_dir().'/nativephp_test_source_'.uniqid();
        $this->testDestDir = sys_get_temp_dir().'/nativephp_test_dest_'.uniqid();

        // Create test directories
        File::makeDirectory($this->testSourceDir, 0755, true);
        File::makeDirectory($this->testDestDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        File::deleteDirectory($this->testSourceDir);
        File::deleteDirectory($this->testDestDir);

        parent::tearDown();
    }

    public function test_platform_optimized_copy_copies_files()
    {
        // Create test files
        File::put($this->testSourceDir.'/file1.txt', 'content1');
        File::put($this->testSourceDir.'/file2.txt', 'content2');
        File::makeDirectory($this->testSourceDir.'/subdir');
        File::put($this->testSourceDir.'/subdir/file3.txt', 'content3');

        // Execute copy
        $this->platformOptimizedCopy($this->testSourceDir, $this->testDestDir);

        // Assert files were copied
        $this->assertFileExists($this->testDestDir.'/file1.txt');
        $this->assertFileExists($this->testDestDir.'/file2.txt');
        $this->assertFileExists($this->testDestDir.'/subdir/file3.txt');

        $this->assertEquals('content1', File::get($this->testDestDir.'/file1.txt'));
        $this->assertEquals('content2', File::get($this->testDestDir.'/file2.txt'));
        $this->assertEquals('content3', File::get($this->testDestDir.'/subdir/file3.txt'));
    }

    public function test_platform_optimized_copy_with_excluded_dirs()
    {
        // Create test files
        File::put($this->testSourceDir.'/file1.txt', 'content1');
        File::makeDirectory($this->testSourceDir.'/node_modules');
        File::put($this->testSourceDir.'/node_modules/package.json', '{}');
        File::makeDirectory($this->testSourceDir.'/.git');
        File::put($this->testSourceDir.'/.git/config', 'git config');

        // Execute copy with exclusions
        $this->platformOptimizedCopy($this->testSourceDir, $this->testDestDir, ['node_modules', '.git']);

        // Assert excluded directories were not copied
        $this->assertFileExists($this->testDestDir.'/file1.txt');
        $this->assertDirectoryDoesNotExist($this->testDestDir.'/node_modules');
        $this->assertDirectoryDoesNotExist($this->testDestDir.'/.git');
    }

    public function test_remove_directory_removes_directory()
    {
        // Create test directory with files
        $testDir = sys_get_temp_dir().'/nativephp_test_remove_'.uniqid();
        File::makeDirectory($testDir.'/subdir', 0755, true);
        File::put($testDir.'/file.txt', 'content');
        File::put($testDir.'/subdir/file2.txt', 'content2');

        $this->assertDirectoryExists($testDir);

        // Execute removal
        $this->removeDirectory($testDir);

        // Assert directory was removed
        $this->assertDirectoryDoesNotExist($testDir);
    }

    public function test_remove_directory_handles_non_existent_directory()
    {
        $nonExistentDir = sys_get_temp_dir().'/nativephp_non_existent_'.uniqid();

        // Should not throw exception
        $this->removeDirectory($nonExistentDir);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function test_normalize_line_endings_converts_to_lf()
    {
        // Test Windows line endings
        $windowsContent = "Line 1\r\nLine 2\r\nLine 3";
        $normalized = $this->normalizeLineEndings($windowsContent);
        $this->assertEquals("Line 1\nLine 2\nLine 3", $normalized);

        // Test Mac classic line endings
        $macContent = "Line 1\rLine 2\rLine 3";
        $normalized = $this->normalizeLineEndings($macContent);
        $this->assertEquals("Line 1\nLine 2\nLine 3", $normalized);

        // Test Unix line endings (should remain unchanged)
        $unixContent = "Line 1\nLine 2\nLine 3";
        $normalized = $this->normalizeLineEndings($unixContent);
        $this->assertEquals("Line 1\nLine 2\nLine 3", $normalized);

        // Test mixed line endings
        $mixedContent = "Line 1\r\nLine 2\rLine 3\nLine 4";
        $normalized = $this->normalizeLineEndings($mixedContent);
        $this->assertEquals("Line 1\nLine 2\nLine 3\nLine 4", $normalized);
    }

    public function test_replace_file_contents_replaces_text()
    {
        $testFile = $this->testSourceDir.'/replace_test.txt';
        File::put($testFile, 'Hello World! This is a test.');

        // Execute replacement
        $result = $this->replaceFileContents($testFile, 'World', 'Universe');

        $this->assertTrue($result);
        $this->assertEquals('Hello Universe! This is a test.', File::get($testFile));
    }

    public function test_replace_file_contents_returns_false_for_non_existent_file()
    {
        $nonExistentFile = $this->testSourceDir.'/non_existent.txt';

        $result = $this->replaceFileContents($nonExistentFile, 'foo', 'bar');

        $this->assertFalse($result);
    }

    public function test_replace_file_contents_returns_false_when_no_changes()
    {
        $testFile = $this->testSourceDir.'/no_change_test.txt';
        File::put($testFile, 'Hello World!');

        $result = $this->replaceFileContents($testFile, 'Universe', 'Galaxy');

        $this->assertFalse($result);
        $this->assertEquals('Hello World!', File::get($testFile));
    }

    public function test_replace_file_contents_regex_replaces_patterns()
    {
        $testFile = $this->testSourceDir.'/regex_test.txt';
        File::put($testFile, 'version = "1.2.3"');

        // Execute regex replacement
        $result = $this->replaceFileContentsRegex($testFile, '/version\s*=\s*".*?"/', 'version = "2.0.0"');

        $this->assertTrue($result);
        $this->assertEquals('version = "2.0.0"', File::get($testFile));
    }

    public function test_replace_file_contents_regex_handles_multiline()
    {
        $testFile = $this->testSourceDir.'/multiline_test.txt';
        $content = "<!-- START -->\nSome content\nMore content\n<!-- END -->";
        File::put($testFile, $content);

        // Execute regex replacement
        $result = $this->replaceFileContentsRegex(
            $testFile,
            '/<!-- START -->.*?<!-- END -->/s',
            "<!-- START -->\nNew content\n<!-- END -->"
        );

        $this->assertTrue($result);
        $this->assertEquals("<!-- START -->\nNew content\n<!-- END -->", File::get($testFile));
    }

    /**
     * Helper methods for trait usage
     */
    protected function info($message)
    {
        // Mock info method for testing
    }

    protected function warn($message)
    {
        // Mock warn method for testing
    }
}
