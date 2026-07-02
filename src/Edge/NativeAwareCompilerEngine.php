<?php

namespace Native\Mobile\Edge;

use Illuminate\View\Engines\CompilerEngine;

/**
 * Drop-in replacement for Laravel's Blade CompilerEngine with one extra
 * rule: while a native render is active, any view whose cached compiled
 * file lacks the native marker is force-recompiled before evaluation.
 *
 * Blade's compiled cache is keyed by path + mtime only, so a view first
 * compiled by a web render (native precompiler inactive) would otherwise
 * be reused verbatim by a native render — its <native:*> tags left as
 * plain HTML and the element collector left empty. The root view gets
 * this guard in NativeComponent::renderBladeBoundToSelf(); this engine
 * extends it to nested @includes and any view rendered through the
 * standard Factory path during a native render.
 *
 * Subclasses (rather than decorates) CompilerEngine so `getEngine()
 * instanceof CompilerEngine` checks — including our own render path —
 * keep working.
 */
class NativeAwareCompilerEngine extends CompilerEngine
{
    public function get($path, array $data = [])
    {
        if (NativeTagPrecompiler::active()
            && ! NativeTagPrecompiler::compiledFileIsNative($this->compiler->getCompiledPath($path))) {
            $this->compiler->compile($path);
        }

        return parent::get($path, $data);
    }
}
