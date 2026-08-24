<?php

namespace Native\Mobile\Plugins\Compilers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use InvalidArgumentException;

/**
 * Structural read-modify-write access to an Info.plist document.
 *
 * Plugin manifests may declare any Info.plist value Apple accepts, so
 * entries are merged by type: scalars replace, lists union on content,
 * dicts merge recursively. Untouched keys keep their original markup.
 */
class PlistDocument
{
    protected DOMDocument $dom;

    protected DOMElement $root;

    protected function __construct(DOMDocument $dom, DOMElement $root)
    {
        $this->dom = $dom;
        $this->root = $root;
    }

    public static function fromXml(string $xml): static
    {
        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new InvalidArgumentException('Info.plist is not well-formed XML.');
        }

        $root = $dom->documentElement?->getElementsByTagName('dict')->item(0);

        if (! $root instanceof DOMElement || $root->parentNode !== $dom->documentElement) {
            throw new InvalidArgumentException('Info.plist has no root <dict>.');
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
        $entries = [];

        foreach ($this->pairs() as $key => $value) {
            $entries[$key] = static::fromNode($value);
        }

        return $entries;
    }

    public function get(string $key): mixed
    {
        $value = $this->pairs()[$key] ?? null;

        return $value ? static::fromNode($value) : null;
    }

    /**
     * Merge entries into the root dict, replacing or appending each key.
     */
    public function merge(array $entries): void
    {
        foreach ($entries as $key => $value) {
            if ($value === null) {
                continue;
            }

            $this->set($key, static::mergeValues($this->get($key), $value));
        }
    }

    public function set(string $key, mixed $value): void
    {
        $node = $this->toNode($value, 1);
        $existing = $this->pairs()[$key] ?? null;

        if ($existing) {
            $existing->parentNode->replaceChild($node, $existing);

            return;
        }

        // Keep the closing </dict> on its own line by inserting
        // ahead of the trailing whitespace the file already has.
        $anchor = $this->root->lastChild instanceof DOMText && trim($this->root->lastChild->data) === ''
            ? $this->root->lastChild
            : $this->root->appendChild($this->dom->createTextNode("\n"));

        $keyNode = $this->dom->createElement('key');
        $keyNode->appendChild($this->dom->createTextNode($key));

        $this->root->insertBefore($this->dom->createTextNode("\n\t"), $anchor);
        $this->root->insertBefore($keyNode, $anchor);
        $this->root->insertBefore($this->dom->createTextNode("\n\t"), $anchor);
        $this->root->insertBefore($node, $anchor);
    }

    /**
     * Combine an existing value with an incoming one. Lists union on
     * content so a rebuild or a second plugin never duplicates an
     * item, dicts merge key by key, and anything else is replaced.
     */
    public static function mergeValues(mixed $existing, mixed $incoming): mixed
    {
        if (! is_array($existing) || ! is_array($incoming)) {
            return $incoming;
        }

        $existingIsList = array_is_list($existing);

        if ($existingIsList !== array_is_list($incoming)) {
            return $incoming;
        }

        if ($existingIsList) {
            $seen = array_map([static::class, 'canonical'], $existing);

            foreach ($incoming as $item) {
                if (! in_array(static::canonical($item), $seen, true)) {
                    $existing[] = $item;
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
     * Top-level <key> text mapped to its value element.
     *
     * @return array<string, DOMElement>
     */
    protected function pairs(): array
    {
        $pairs = [];
        $pendingKey = null;

        foreach ($this->root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

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
            'array' => array_map(
                fn (DOMElement $child) => static::fromNode($child),
                static::elementChildren($node)
            ),
            'dict' => static::dictFromNode($node),
            default => $node->textContent,
        };
    }

    protected static function dictFromNode(DOMElement $node): array
    {
        $dict = [];
        $pendingKey = null;

        foreach (static::elementChildren($node) as $child) {
            if ($child->tagName === 'key') {
                $pendingKey = $child->textContent;
            } elseif ($pendingKey !== null) {
                $dict[$pendingKey] = static::fromNode($child);
                $pendingKey = null;
            }
        }

        return $dict;
    }

    protected function toNode(mixed $value, int $depth): DOMNode
    {
        if (is_bool($value)) {
            return $this->dom->createElement($value ? 'true' : 'false');
        }

        if (is_int($value)) {
            return $this->dom->createElement('integer', (string) $value);
        }

        if (is_float($value)) {
            return $this->dom->createElement('real', (string) $value);
        }

        if (! is_array($value)) {
            $node = $this->dom->createElement('string');
            $node->appendChild($this->dom->createTextNode((string) $value));

            return $node;
        }

        $isList = array_is_list($value);
        $node = $this->dom->createElement($isList ? 'array' : 'dict');
        $indent = "\n".str_repeat("\t", $depth + 1);

        foreach ($value as $key => $item) {
            if (! $isList) {
                $keyNode = $this->dom->createElement('key');
                $keyNode->appendChild($this->dom->createTextNode((string) $key));

                $node->appendChild($this->dom->createTextNode($indent));
                $node->appendChild($keyNode);
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
     * A key-order independent fingerprint, so two dicts with
     * the same content count as the same list item.
     */
    protected static function canonical(mixed $value): string
    {
        if (is_array($value) && ! array_is_list($value)) {
            ksort($value);
        }

        if (is_array($value)) {
            $value = array_map([static::class, 'canonical'], $value);
        }

        return json_encode($value);
    }
}
