<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

final class PropertyList
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function encode(array $values): string
    {
        ksort($values);
        $dictionary = $values === [] ? "<dict>\n</dict>" : $this->encodeValue($values, 0);

        return <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
{$dictionary}
</plist>
PLIST;
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $contents, ?string $source = null): array
    {
        try {
            return $this->decodeContents($contents);
        } catch (InvalidArgumentException $exception) {
            if ($source === null) {
                throw $exception;
            }

            throw new InvalidArgumentException(
                "Unable to decode property list at {$source}: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function decodeContents(string $contents): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($contents, LIBXML_NONET)) {
                throw new InvalidArgumentException('Unable to parse the property list.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $this->firstElementChild($document->documentElement);
        $value = $root === null ? null : $this->decodeValue($root);

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('The property list root must be a dictionary.');
        }

        return $value;
    }

    private function encodeValue(mixed $value, int $depth): string
    {
        $indent = str_repeat("\t", $depth);

        if (is_array($value) && ! array_is_list($value)) {
            ksort($value);
            $lines = ["{$indent}<dict>"];
            foreach ($value as $key => $item) {
                $key = htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $lines[] = "{$indent}\t<key>{$key}</key>";
                $lines[] = $this->encodeValue($item, $depth + 1);
            }
            $lines[] = "{$indent}</dict>";

            return implode("\n", $lines);
        }

        if (is_array($value)) {
            $lines = ["{$indent}<array>"];
            foreach ($value as $item) {
                $lines[] = $this->encodeValue($item, $depth + 1);
            }
            $lines[] = "{$indent}</array>";

            return implode("\n", $lines);
        }

        if (is_bool($value)) {
            return $indent.'<'.($value ? 'true' : 'false').'/>';
        }

        if (is_int($value)) {
            return "{$indent}<integer>{$value}</integer>";
        }

        if (is_float($value)) {
            return "{$indent}<real>{$value}</real>";
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Property list values must be strings, numbers, booleans, arrays, or dictionaries.');
        }

        $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "{$indent}<string>{$value}</string>";
    }

    private function decodeValue(DOMElement $element): mixed
    {
        return match ($element->tagName) {
            'dict' => $this->decodeDictionary($element),
            'array' => array_map($this->decodeValue(...), $this->elementChildren($element)),
            'true' => true,
            'false' => false,
            'integer' => (int) $element->textContent,
            'real' => (float) $element->textContent,
            'string' => $element->textContent,
            default => throw new InvalidArgumentException("Unsupported property list element: {$element->tagName}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDictionary(DOMElement $element): array
    {
        $children = $this->elementChildren($element);
        $values = [];

        for ($index = 0; $index < count($children); $index += 2) {
            $key = $children[$index] ?? null;
            $value = $children[$index + 1] ?? null;

            if ($key?->tagName !== 'key' || $value === null) {
                throw new InvalidArgumentException('Invalid property list dictionary.');
            }

            $values[$key->textContent] = $this->decodeValue($value);
        }

        return $values;
    }

    /**
     * @return list<DOMElement>
     */
    private function elementChildren(?DOMElement $element): array
    {
        if ($element === null) {
            return [];
        }

        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function firstElementChild(?DOMElement $element): ?DOMElement
    {
        return $this->elementChildren($element)[0] ?? null;
    }
}
