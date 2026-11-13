<?php

namespace Native\Mobile\Edge\Asgard;

/**
 * What a `#[Server]` method's closure receives
 * (docs/ASGARD-SERVER-METHODS.md §7).
 *
 *     return function (ServerResponse $response) {
 *         $this->loading = false;
 *
 *         if ($response->failed()) {
 *             $this->error = 'Could not refresh.';
 *         }
 *     };
 *
 * The closure always runs, unless the component is gone, so success and
 * failure are one code path and this object carries which one happened.
 * The state delta is already applied by the time it arrives: `applied`
 * is what was written, `refused` is what the server sent and the client
 * kept its own value for (§5.2). A developer can react to a refusal
 * rather than discovering it visually.
 */
final class ServerResponse
{
    /**
     * @param  string  $id  The request id this answers.
     * @param  bool  $ok  Whether the server completed the call.
     * @param  array  $applied  prop => value written to the component.
     * @param  array  $refused  prop => value the server sent that was not
     *                          written, either because the value moved
     *                          locally while the request was in flight
     *                          (§5.2) or because the prop is not a public
     *                          prop of this component (drift, §5.3).
     * @param  array  $refusedFragments  region => why its fragment was not
     *                                   stored (§9.4).
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $ok,
        public readonly array $applied = [],
        public readonly array $refused = [],
        public readonly array $refusedFragments = [],
        public readonly ?string $error = null,
        public readonly int|string|null $code = null,
    ) {}

    public static function failure(string $id, ?string $error = null, int|string|null $code = null): self
    {
        return new self(id: $id, ok: false, error: $error, code: $code);
    }

    public function succeeded(): bool
    {
        return $this->ok;
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /** Did the server try to set this prop and lose the compare-and-set? */
    public function wasRefused(string $prop): bool
    {
        return array_key_exists($prop, $this->refused);
    }
}
