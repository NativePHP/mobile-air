<?php

namespace Native\Mobile\Edge\Web\Protocol;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Native\Mobile\Attributes\Locked;

/**
 * Sealed state snapshots for the EDGE web target.
 *
 * The wire snapshot is `{data: {...}, checksum: "..."}` where the checksum
 * is an HMAC-SHA256 of a canonical JSON encoding of `data`, keyed with the
 * app key (`config('app.key')`, materialized the way Laravel's encrypter
 * does: `base64:` prefix stripped and decoded). The sealed unit covers the
 * component class, uri, params, public props AND the callback/nav registry
 * maps together, so no piece can be tampered with independently — a client
 * can echo the snapshot back verbatim or get a 419, nothing in between.
 *
 * Canonicalization guards against JSON round-trip drift through the
 * browser (`JSON.parse` → `JSON.stringify`):
 *   - assoc keys are sorted (JS reorders integer-like object keys), and
 *   - integral floats are normalized to ints (JS serializes 72.0 as 72,
 *     which PHP decodes as int).
 *
 * Also home to typed prop (de)hydration — see dehydrate()/hydrate() — and
 * #[Locked] prop scanning.
 */
class EdgeSnapshot
{
    /**
     * Exact datetime classes hydrate() may instantiate. Never extend this
     * from payload data — hydration must not construct arbitrary classes.
     */
    protected const DATETIME_CLASSES = [
        \Carbon\Carbon::class,
        \Carbon\CarbonImmutable::class,
        \Illuminate\Support\Carbon::class,
        \DateTimeImmutable::class,
        \DateTime::class,
    ];

    // ── Seal / unseal ───────────────────────────────

    /** Wrap snapshot data with its integrity checksum. */
    public static function seal(array $data): array
    {
        return [
            'data' => $data,
            'checksum' => static::checksum($data),
        ];
    }

    /**
     * Verify a sealed snapshot from the wire and return its data.
     * Aborts 419 on any mismatch — wrong shape, wrong key, tampered data.
     */
    public static function unseal(array $sealed): array
    {
        $data = $sealed['data'] ?? null;
        $checksum = $sealed['checksum'] ?? null;

        if (! is_array($data) || ! is_string($checksum)
            || ! hash_equals(static::checksum($data), $checksum)) {
            abort(419, 'Snapshot integrity check failed');
        }

        return $data;
    }

    protected static function checksum(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode(static::canonicalize($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            static::key(),
        );
    }

    /** The app key, materialized like Laravel's encrypter does. */
    protected static function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new \RuntimeException('EDGE web snapshots require an application key (config app.key).');
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return $key;
    }

    /**
     * Deterministic structure for checksumming: keys sorted at every
     * level, integral floats collapsed to ints (see class docblock for
     * why both are needed to survive a JS JSON round trip).
     */
    protected static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = static::canonicalize($v);
            }
            ksort($out, SORT_STRING);

            return $out;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return $value;
    }

    // ── Typed prop (de)hydration ────────────────────

    /**
     * JSON-safe form of a public prop value. Scalars/null pass through,
     * arrays recurse, and the supported object types become tagged
     * `{__edge: ...}` markers that hydrate() reverses:
     *
     *   BackedEnum          {__edge:'enum', class, value}
     *   DateTimeInterface   {__edge:'datetime', class, iso}   (class is the
     *                       nearest DATETIME_CLASSES entry, so subclasses
     *                       round-trip to their allowlisted parent)
     *   Support\Collection  {__edge:'collection', items}
     *
     * Eloquent models (and any other object) throw: silently serializing
     * a model onto the wire would leak hidden attributes and desync from
     * the DB, so it's an explicit "not yet" instead.
     */
    public static function dehydrate(mixed $value, string $prop): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = static::dehydrate($v, $prop);
            }

            return $out;
        }

        if ($value instanceof BackedEnum) {
            return ['__edge' => 'enum', 'class' => get_class($value), 'value' => $value->value];
        }

        if ($value instanceof DateTimeInterface) {
            return [
                '__edge' => 'datetime',
                'class' => static::datetimeClassFor($value),
                'iso' => $value->format('Y-m-d\TH:i:s.uP'),
            ];
        }

        if (class_exists(\Illuminate\Database\Eloquent\Model::class)
            && $value instanceof \Illuminate\Database\Eloquent\Model) {
            throw new \RuntimeException(
                "Public property \${$prop} is an Eloquent model; not yet supported on the web target — store the key and refetch, or mark it #[Locked]"
            );
        }

        if ($value instanceof Collection) {
            return ['__edge' => 'collection', 'items' => static::dehydrate($value->all(), $prop)];
        }

        throw new \RuntimeException(
            "Public property \${$prop} holds a ".get_class($value).' instance; the web snapshot supports scalars, arrays, backed enums, datetimes and Collections.'
        );
    }

    /**
     * Reverse of dehydrate(). Only the tagged markers above are honored,
     * and only against exact class allowlists — a snapshot can never make
     * hydration instantiate an arbitrary class. (The HMAC seal already
     * guarantees the markers are server-authored; this is defense in
     * depth.)
     */
    public static function hydrate(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $tag = $value['__edge'] ?? null;

        if ($tag === null) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = static::hydrate($v);
            }

            return $out;
        }

        return match ($tag) {
            'enum' => static::hydrateEnum($value),
            'datetime' => static::hydrateDatetime($value),
            'collection' => new Collection(static::hydrate((array) ($value['items'] ?? []))),
            default => throw new \RuntimeException("Unknown snapshot value tag '{$tag}'."),
        };
    }

    protected static function hydrateEnum(array $marker): ?BackedEnum
    {
        $class = $marker['class'] ?? null;

        if (! is_string($class) || ! enum_exists($class) || ! is_subclass_of($class, BackedEnum::class)) {
            throw new \RuntimeException('Snapshot enum marker does not reference a backed enum.');
        }

        return $class::tryFrom($marker['value'] ?? null);
    }

    protected static function hydrateDatetime(array $marker): DateTimeInterface
    {
        $class = $marker['class'] ?? null;

        if (! is_string($class) || ! in_array($class, static::DATETIME_CLASSES, true) || ! class_exists($class)) {
            throw new \RuntimeException('Snapshot datetime marker does not reference an allowlisted class.');
        }

        return new $class((string) ($marker['iso'] ?? 'now'));
    }

    /** Nearest allowlisted class for a datetime instance. */
    protected static function datetimeClassFor(DateTimeInterface $value): string
    {
        foreach (static::DATETIME_CLASSES as $class) {
            if (class_exists($class) && $value instanceof $class) {
                return $class;
            }
        }

        return \DateTimeImmutable::class;
    }

    // ── #[Locked] props ─────────────────────────────

    /**
     * Names of public non-static props marked #[Locked]. Currently
     * informational (the seal is the enforcement — see the attribute's
     * docblock); later phases key stricter semantics off this list.
     *
     * @return string[]
     */
    public static function lockedProps(object $component): array
    {
        $names = [];

        foreach ((new \ReflectionObject($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic() && $prop->getAttributes(Locked::class) !== []) {
                $names[] = $prop->getName();
            }
        }

        return $names;
    }
}
