<?php

namespace Native\Mobile\Edge\Components\Native;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Native\Mobile\Edge\NativeElementCollector;

/**
 * Base class for native UI element components (column, row, text, button, etc.).
 *
 * These components use NativeElementCollector to build an element tree that gets
 * serialized to shared memory and rendered as native Android/iOS views. Each
 * component maps to a native element type (column, row, text, etc.) via
 * the elementType() method.
 *
 * This is separate from EdgeComponent, which builds navigation chrome
 * (top bar, bottom nav) via the Edge class.
 */
abstract class NativeBladeComponent extends Component
{
    abstract protected function elementType(): string;

    protected bool $isSelfClosing = false;

    protected bool $handlesCollectorManually = false;

    public function render(): View|\Closure|string
    {
        if ($this->isSelfClosing) {
            return '';
        }

        return view('nativephp-mobile::components.native-element-with-children');
    }

    public function withAttributes(array $attributes)
    {
        parent::withAttributes($attributes);

        if (! $this->handlesCollectorManually) {
            $attrs = $this->attributes->getAttributes();

            if (NativeElementCollector::isStreaming()) {
                if ($this->isSelfClosing) {
                    NativeElementCollector::leafStreaming($this->elementType(), $attrs);
                } else {
                    NativeElementCollector::openStreaming($this->elementType(), $attrs);
                }
            } else {
                if ($this->isSelfClosing) {
                    NativeElementCollector::leaf($this->elementType(), $attrs);
                } else {
                    NativeElementCollector::open($this->elementType(), $attrs);
                }
            }
        }

        return $this;
    }
}
