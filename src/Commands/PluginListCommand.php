<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginRegistry;

class PluginListCommand extends Command
{
    protected $signature = 'native:plugin:list
                            {--json : Output as JSON}
                            {--all : Show all installed plugins, including unregistered ones}';

    protected $description = 'List all installed NativePHP Mobile plugins';

    public function __construct(protected PluginRegistry $registry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $showAll = $this->option('all');

        if ($showAll) {
            $plugins = $this->registry->allInstalled();
        } else {
            $plugins = $this->registry->all();
        }

        $unregistered = $this->registry->unregistered();

        if ($plugins->isEmpty() && $unregistered->isEmpty()) {
            $this->info('No NativePHP plugins installed.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->outputJson($plugins, $unregistered);
        } else {
            $this->outputTable($plugins, $unregistered, $showAll);
        }

        return self::SUCCESS;
    }

    protected function outputTable($plugins, $unregistered, bool $showAll): void
    {
        // Show provider status - error if not published
        if (! $this->registry->hasPluginsProvider()) {
            $this->components->error('NativeServiceProvider not published. No plugins will be included in builds.');
            $this->newLine();
            $this->components->info('To use plugins, publish the provider first:');
            $this->line('  php artisan vendor:publish --tag=nativephp-plugins-provider');
            $this->newLine();

            // Still show installed plugins so user knows what's available
            if ($unregistered->isNotEmpty()) {
                $this->components->warn('Installed plugins (not registered):');
                foreach ($unregistered as $plugin) {
                    $provider = $plugin->getServiceProvider();
                    $this->line("  - {$plugin->name} (v{$plugin->version})");
                    if ($provider) {
                        $this->line("    Provider: {$provider}");
                    }
                }
                $this->newLine();
                $this->components->info('After publishing the provider, register plugins with:');
                $this->line('  php artisan native:plugin:register');
            }

            return;
        }

        if ($plugins->isNotEmpty()) {
            $this->info('Registered Plugins:');
            $this->newLine();

            $rows = $plugins->map(function (Plugin $plugin) {
                return [
                    $plugin->name,
                    $plugin->version,
                    $plugin->getNamespace(),
                    count($plugin->getBridgeFunctions()),
                    $plugin->hasAndroidCode() ? 'Yes' : 'No',
                    $plugin->hasIosCode() ? 'Yes' : 'No',
                ];
            })->all();

            $this->table(
                ['Package', 'Version', 'Namespace', 'Functions', 'Android', 'iOS'],
                $rows
            );

            $this->newLine();
            $this->info("Total: {$plugins->count()} registered plugin(s)");

            // Show all bridge functions
            $this->newLine();
            $this->info('Registered Bridge Functions:');

            foreach ($plugins as $plugin) {
                foreach ($plugin->getBridgeFunctions() as $function) {
                    $this->line("  - {$function['name']}");
                }
            }
        }

        // Show unregistered plugins
        if ($unregistered->isNotEmpty()) {
            $this->newLine();
            $this->components->warn('Unregistered Plugins (not included in builds):');

            foreach ($unregistered as $plugin) {
                $description = $plugin->description ? " - {$plugin->description}" : '';
                $functions = count($plugin->getBridgeFunctions());
                $this->line("  - {$plugin->name} (v{$plugin->version}){$description} [{$functions} bridge function(s)]");
            }

            $this->newLine();
            $this->components->info('To register plugins, run:');
            $this->line('  php artisan native:plugin:register');
        }
    }

    protected function outputJson($plugins, $unregistered): void
    {
        $output = [
            'has_provider' => $this->registry->hasPluginsProvider(),
            'registered' => $plugins->map(function (Plugin $plugin) {
                return [
                    'name' => $plugin->name,
                    'version' => $plugin->version,
                    'namespace' => $plugin->getNamespace(),
                    'path' => $plugin->path,
                    'bridge_functions' => $plugin->getBridgeFunctions(),
                    'has_android' => $plugin->hasAndroidCode(),
                    'has_ios' => $plugin->hasIosCode(),
                    'android_permissions' => $plugin->getAndroidPermissions(),
                    'ios_info_plist' => $plugin->getIosInfoPlist(),
                ];
            })->values()->all(),
            'unregistered' => $unregistered->map(function (Plugin $plugin) {
                return [
                    'name' => $plugin->name,
                    'version' => $plugin->version,
                ];
            })->values()->all(),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }
}
