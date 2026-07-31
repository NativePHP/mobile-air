<?php

namespace Native\Mobile\Edge\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Native\Mobile\Edge\Edge;
use Native\Mobile\Edge\Exceptions\MissingRequiredPropsException;

abstract class EdgeComponent extends Component
{
    protected string $type = '';

    protected bool $hasChildren = false;

    abstract protected function toNativeProps(): array;

    /**
     * Define required properties for this component.
     * Override in child classes to specify which props are required.
     *
     * @return array<string> List of required property names
     */
    protected function requiredProps(): array
    {
        return [];
    }

    /**
     * Validate that all required properties have non-empty values.
     *
     * @throws MissingRequiredPropsException
     */
    protected function validateProps(): void
    {
        $missing = [];

        foreach ($this->requiredProps() as $prop) {
            if (! property_exists($this, $prop)) {
                $missing[] = $prop;

                continue;
            }

            $value = $this->{$prop};

            if ($value === null || $value === '') {
                $missing[] = $prop;
            }
        }

        if (! empty($missing)) {
            throw new MissingRequiredPropsException(
                static::class,
                $this->type,
                $missing
            );
        }
    }

    public function render(): View
    {
        $this->validateProps();

        if ($this->hasChildren) {
            // Start a new context for collecting children
            $contextIndex = Edge::startContext();

            // Use namespace which handles both published and package views
            return view('nativephp-mobile::components.native-placeholder-with-children', [
                'contextIndex' => $contextIndex,
                'type' => $this->type,
                'props' => $this->toNativeProps(),
            ]);
        } else {
            // No children, just add the component
            Edge::add($this->type, $this->toNativeProps());

            return view('nativephp-mobile::components.native-placeholder');
        }
    }
}
