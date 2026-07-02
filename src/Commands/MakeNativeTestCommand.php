<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeNativeTestCommand extends Command
{
    protected $signature = 'native:make-test
                            {name : The component to test (e.g. Counter, Settings/Profile, or a FQCN)}
                            {--force : Overwrite if the test already exists}';

    protected $description = 'Create a Pest test for a NativeComponent screen';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        $name = str_replace('/', '\\', $this->argument('name'));

        // A bare name maps to the native:make convention; a FQCN is used as-is.
        $componentClass = class_exists($name)
            ? $name
            : 'App\\NativeComponents\\'.$name;

        $className = class_basename($componentClass);
        $testPath = base_path('tests/Feature/'.$className.'Test.php');

        if ($this->files->exists($testPath) && ! $this->option('force')) {
            $this->components->error("{$className}Test already exists at {$testPath}. Use --force to overwrite.");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists(dirname($testPath));
        $this->files->put($testPath, $this->stub($componentClass, $className));

        $this->components->info("Test created: {$testPath}");

        if (! class_exists($componentClass)) {
            $this->components->warn("Note: [{$componentClass}] does not exist yet — create it with `php artisan native:make {$className}`.");
        }

        return self::SUCCESS;
    }

    protected function stub(string $componentClass, string $className): string
    {
        return <<<PHP
<?php

use {$componentClass};
use Native\Mobile\Testing\Native;

it('renders', function () {
    Native::test({$className}::class)
        ->assertSee('');
});

it('handles interaction', function () {
    Native::test({$className}::class)
        ->tap('')
        ->assertSet('', null);
});

PHP;
    }
}
