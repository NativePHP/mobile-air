<?php

namespace Native\Mobile\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use InvalidArgumentException;

/**
 * Structural read-modify-write access to an Info.plist document.
 *
 * Plugins may declare any value Apple accepts, so entries merge by
 * type: scalars replace, lists union on content, dicts merge key
 * by key. Keys nobody touches keep their original markup.
 */
class PlistDocument
{
    protected function __construct(
        protected DOMDocument $dom,
        protected DOMElement $root,
    ) {}

    public static function fromXml(string $xml): static
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
            $error = libxml_get_last_error();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new InvalidArgumentException(
                'Plist is not well-formed XML'.($error ? ': '.trim($error->message).' (line '.$error->line.')' : '.')
            );
        }

        $root = (new DOMXPath($dom))->query('/*/dict[1]')->item(0);

        if (! $root instanceof DOMElement) {
            throw new InvalidArgumentException('Plist has no root <dict>.');
        }

        return new static($dom, $root);
    }

    public function toXml(): string
    {
        return $this->dom->saveXML();
    }

    /**
     * Every top-level entry as PHP values.
     */
    public function all(): array
    {
        return static::fromNode($this->root);
    }

    public function get(string $key): mixed
    {
        $value = static::pairsOf($this->root)[$key] ?? null;

        return $value ? static::fromNode($value) : null;
    }

    /**
     * Merge entries into the root dict, replacing or appending each key.
     */
    public function merge(array $entries): void
    {
        foreach (static::withoutNulls($entries) as $key => $value) {
            $this->set($key, static::mergeValues($this->get($key), $value));
        }
    }

    public function set(string $key, mixed $value): void
    {
        $node = $this->toNode($value, 1);
        $existing = static::pairsOf($this->root)[$key] ?? null;

        if ($existing) {
            $existing->parentNode->replaceChild($node, $existing);

            return;
        }

        // Insert ahead of the trailing whitespace the file already
        // has, so the closing </dict> stays on a line of its own.
        $anchor = $this->root->lastChild instanceof DOMText && trim($this->root->lastChild->data) === ''
            ? $this->root->lastChild
            : $this->root->appendChild($this->dom->createTextNode("\n"));

        $this->root->insertBefore($this->dom->createTextNode("\n\t"), $anchor);
        $this->root->insertBefore($this->textElement('key', $key), $anchor);
        $this->root->insertBefore($this->dom->createTextNode("\n\t"), $anchor);
        $this->root->insertBefore($node, $anchor);
    }

    /**
     * Combine an existing value with an incoming one. Lists union on content
     * so rebuilds and second plugins never duplicate an item, dicts merge
     * key by key, an empty array contributes nothing, and any other
     * pairing is replaced outright by the incoming value.
     */
    protected static function mergeValues(mixed $existing, mixed $incoming): mixed
    {
        if (! is_array($incoming)) {
            return $incoming;
        }

        if ($incoming === []) {
            return $existing ?? [];
        }

        if (! is_array($existing) || array_is_list($existing) !== array_is_list($incoming)) {
            return array_is_list($incoming) ? static::mergeValues([], $incoming) : $incoming;
        }

        if (array_is_list($existing)) {
            $seen = array_map([static::class, 'canonical'], $existing);

            foreach ($incoming as $item) {
                $fingerprint = static::canonical($item);

                if (! in_array($fingerprint, $seen, true)) {
                    $existing[] = $item;
                    $seen[] = $fingerprint;
                }
            }

            return $existing;
        }

        foreach ($incoming as $key => $value) {
            $existing[$key] = static::mergeValues($existing[$key] ?? null, $value);
        }

        return $existing;
    }

    /**
     * A dict's <key> text mapped to the value element that follows it.
     *
     * @return array<string, DOMElement>
     */
    protected static function pairsOf(DOMElement $dict): array
    {
        $pairs = [];
        $pendingKey = null;

        foreach (static::elementChildren($dict) as $child) {
            if ($child->tagName === 'key') {
                $pendingKey = $child->textContent;
            } elseif ($pendingKey !== null) {
                $pairs[$pendingKey] = $child;
                $pendingKey = null;
            }
        }

        return $pairs;
    }

    protected static function fromNode(DOMElement $node): mixed
    {
        return match ($node->tagName) {
            'true' => true,
            'false' => false,
            'integer' => (int) $node->textContent,
            'real' => (float) $node->textContent,
            'array' => array_map([static::class, 'fromNode'], static::elementChildren($node)),
            'dict' => array_map([static::class, 'fromNode'], static::pairsOf($node)),
            default => $node->textContent,
        };
    }

    protected function toNode(mixed $value, int $depth): DOMNode
    {
        if (is_bool($value)) {
            return $this->dom->createElement($value ? 'true' : 'false');
        }

        if (is_int($value)) {
            return $this->textElement('integer', (string) $value);
        }

        if (is_float($value)) {
            return $this->textElement('real', (string) $value);
        }

        if (! is_array($value)) {
            return $this->textElement('string', (string) $value);
        }

        $isList = array_is_list($value);
        $node = $this->dom->createElement($isList ? 'array' : 'dict');
        $indent = "\n".str_repeat("\t", $depth + 1);

        foreach ($value as $key => $item) {
            if (! $isList) {
                $node->appendChild($this->dom->createTextNode($indent));
                $node->appendChild($this->textElement('key', (string) $key));
            }

            $node->appendChild($this->dom->createTextNode($indent));
            $node->appendChild($this->toNode($item, $depth + 1));
        }

        if ($value !== []) {
            $node->appendChild($this->dom->createTextNode("\n".str_repeat("\t", $depth)));
        }

        return $node;
    }

    /**
     * An element whose text is escaped, which createElement's
     * value argument would not do for characters like "&".
     */
    protected function textElement(string $tag, string $text): DOMElement
    {
        $element = $this->dom->createElement($tag);
        $element->appendChild($this->dom->createTextNode($text));

        return $element;
    }

    /**
     * @return array<int, DOMElement>
     */
    protected static function elementChildren(DOMElement $node): array
    {
        return array_values(array_filter(
            iterator_to_array($node->childNodes),
            fn ($child) => $child instanceof DOMElement
        ));
    }

    /**
     * Drop null entries at every depth, since a plist has no
     * way to express one and JSON manifests may carry them.
     */
    protected static function withoutNulls(array $values): array
    {
        $wasList = array_is_list($values);

        $values = array_map(
            fn ($value) => is_array($value) ? static::withoutNulls($value) : $value,
            array_filter($values, fn ($value) => $value !== null)
        );

        return $wasList ? array_values($values) : $values;
    }

    /**
     * A key-order independent fingerprint, so two dicts with
     * the same content count as the same list item.
     */
    protected static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            $value = array_map([static::class, 'canonical'], $value);
        }

        return json_encode($value);
    }
}
