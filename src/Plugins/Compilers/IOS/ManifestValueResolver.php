<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

final readonly class ManifestValueResolver
{
    public function __construct(private string $appId) {}

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

            $environmentValue = env($matches[1]);

            return $environmentValue === null ? $matches[0] : (string) $environmentValue;
        }, $value);
    }
}
