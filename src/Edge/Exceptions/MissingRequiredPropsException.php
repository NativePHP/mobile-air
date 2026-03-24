<?php

namespace Native\Mobile\Edge\Exceptions;

use Exception;

class MissingRequiredPropsException extends Exception
{
    public function __construct(
        public string $componentClass,
        public string $componentType,
        public array $missingProps
    ) {
        $propsFormatted = implode(', ', array_map(fn ($p) => "'{$p}'", $missingProps));
        $componentName = class_basename($componentClass);
        $bladeTag = $this->toBladeTag($componentName);

        $message = "EDGE Component <native:{$bladeTag}> is missing required properties: {$propsFormatted}. ";
        $message .= 'Add these attributes to your component: ';
        $message .= implode(' ', array_map(fn ($p) => "{$p}=\"...\"", $missingProps));

        parent::__construct($message);
    }

    /**
     * Convert component class name to blade tag format.
     * e.g., "BottomNavItem" -> "bottom-nav-item"
     */
    protected function toBladeTag(string $className): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $className));
    }
}
