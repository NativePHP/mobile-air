<?php

namespace Native\Mobile\Edge;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;

class NativeTagPrecompiler extends ComponentTagCompiler
{
    public function __construct(BladeCompiler $blade)
    {
        parent::__construct(
            $blade->getClassComponentAliases(),
            $blade->getClassComponentNamespaces(),
            $blade
        );
    }

    public function __invoke($value): string
    {
        // First transform native: tags to x-native- tags
        $value = preg_replace(
            '/<\/\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)\s*>/',
            '</x-native-$1>',
            $value
        );

        $value = preg_replace(
            '/<\s*native\s*:\s*([a-zA-Z0-9\-_\.]+)/',
            '<x-native-$1',
            $value
        );

        // Then use parent ComponentTagCompiler to compile the x-native- tags
        return $this->compileTags($value);
    }
}
