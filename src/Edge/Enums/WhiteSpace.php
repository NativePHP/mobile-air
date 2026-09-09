<?php

namespace Native\Mobile\Edge\Enums;

/**
 * Whitespace policy for `<text>` content, modelled on CSS `white-space`.
 *
 * Slot content is captured from an output buffer, where the newlines an
 * author typed to wrap a template line are indistinguishable from the
 * newlines inside a `{{ $message }}` value. The default (Normal) collapses
 * every whitespace run to a single space, so multi-line markup renders as
 * one flowing line — the same collapse a browser applies. `PreLine` keeps
 * line breaks while still collapsing spaces, which is what user-written
 * paragraphs need; `Pre`/`PreWrap` keep every byte.
 *
 * Wire values (`white_space` prop) follow the CSS keyword order:
 * 0 normal, 1 nowrap, 2 pre, 3 pre-line, 4 pre-wrap. NoWrap only affects
 * the collapse policy here; wrapping itself is a renderer concern.
 */
enum WhiteSpace: int
{
    case Normal = 0;

    case NoWrap = 1;

    case Pre = 2;

    case PreLine = 3;

    case PreWrap = 4;

    /** Resolve a Tailwind `whitespace-<token>` suffix (or a `white-space` attribute value). */
    public static function fromToken(string $token): ?self
    {
        return match (strtolower(trim($token))) {
            'normal' => self::Normal,
            'nowrap' => self::NoWrap,
            'pre' => self::Pre,
            'pre-line' => self::PreLine,
            'pre-wrap' => self::PreWrap,
            default => null,
        };
    }

    /** Resolve from an attribute bag that may carry the parsed class value or a raw attribute. */
    public static function fromAttributes(array $attrs): ?self
    {
        $value = $attrs['whiteSpace'] ?? $attrs['white-space'] ?? null;

        if ($value instanceof self) {
            return $value;
        }
        if (is_int($value)) {
            return self::tryFrom($value);
        }
        if (is_string($value)) {
            return is_numeric($value) ? self::tryFrom((int) $value) : self::fromToken($value);
        }

        return null;
    }

    /**
     * Apply the policy to a text's INTERIOR whitespace. Never trims — whether
     * the edges are meaningful is the caller's decision (a leaf trims, an
     * inline run keeps its edge spaces).
     */
    public function apply(string $text): string
    {
        return match ($this) {
            self::Normal, self::NoWrap => preg_replace('/\s+/', ' ', $text),
            // Newlines survive; spaces/tabs around them are dropped and every
            // other whitespace run collapses — CSS `pre-line` semantics.
            self::PreLine => preg_replace(
                '/[^\S\n]+/',
                ' ',
                preg_replace('/[^\S\n]*\n[^\S\n]*/', "\n", str_replace(["\r\n", "\r"], "\n", $text))
            ),
            self::Pre, self::PreWrap => $text,
        };
    }
}
