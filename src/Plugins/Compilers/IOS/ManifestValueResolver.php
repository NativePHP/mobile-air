<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

use InvalidArgumentException;
use Native\Mobile\Plugins\Plugin;

final readonly class ManifestValueResolver
{
    /**
     * @param  array<string, string|null>  $allowedEnvironment
     */
    public function __construct(
        private string $appId,
        private array $allowedEnvironment = [],
    ) {}

    public static function forPlugin(string $appId, Plugin $plugin): self
    {
        $allowedEnvironment = [];

        foreach ($plugin->getSecrets() as $key => $configuration) {
            $name = is_string($configuration) ? $configuration : $key;
            if (! is_string($name) || ! preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
                throw new InvalidArgumentException("Plugin {$plugin->name} declares an invalid environment variable name.");
            }

            $value = env($name);
            $allowedEnvironment[$name] = $value === null ? null : (string) $value;
        }

        return new self($appId, $allowedEnvironment);
    }

    public function resolve(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->resolve($item), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)\}/', function (array $matches): string {
            if ($matches[1] === 'APP_ID') {
                return $this->appId;
            }

            if (! array_key_exists($matches[1], $this->allowedEnvironment)) {
                throw new InvalidArgumentException(
                    "Environment variable {$matches[1]} is not declared in the plugin manifest secrets allowlist."
                );
            }

            if ($this->allowedEnvironment[$matches[1]] === null) {
                throw new InvalidArgumentException(
                    "Environment variable {$matches[1]} is declared by the plugin but is not available."
                );
            }

            return $this->allowedEnvironment[$matches[1]];
        }, $value);
    }
}
