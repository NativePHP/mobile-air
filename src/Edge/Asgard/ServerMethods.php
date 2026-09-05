<?php

namespace Native\Mobile\Edge\Asgard;

use Native\Mobile\Attributes\Server;
use Native\Mobile\Edge\Asgard\Contracts\ServerTransport;
use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;

/**
 * The client half of `#[Server]` methods
 * (docs/ASGARD-SERVER-METHODS.md).
 *
 * Three jobs, in the order they happen:
 *
 *  1. **Invoke** (§2). Run the client body, snapshot the component's
 *     public state AFTER it returns, hand method + args + state to the
 *     transport, and keep the closure the body returned.
 *  2. **Deliver** (§4). A response arrives as an `EVENT_NATIVE` frame on
 *     the runloop's event channel — because the request itself is
 *     performed natively while PHP blocks (§3) — and lands here.
 *  3. **Reconcile** (§5). Apply the server's delta prop by prop, but only
 *     where the client's value has not moved since the request left.
 *
 * ## Why the pending table is static
 *
 * The response frame is delivered to whichever component owns the
 * runloop, which is the screen. The request may have been made by a
 * child component several levels down, and several components may have
 * calls in flight at once (§6). Keying by request id in one table means
 * delivery never has to walk a component tree looking for an owner.
 *
 * An entry holds the closure the client body returned, and that closure
 * is bound to the component — so the entry keeps a whole live screen
 * reachable whatever else it stores. Clearing on unmount (`forget()`,
 * called from `NativeComponent::unmount()`) is therefore the mechanism,
 * not a nicety: without it the table pins every screen the user has left.
 * The `WeakReference` is a second line of defence, and it only bites for
 * a call with no continuation, since a closure holds its `$this` strongly.
 * Both routes end the same way — a response for a component that has gone
 * is discarded, closure and all (§4).
 */
class ServerMethods
{
    /**
     * The native event name a response rides in on. Framework-internal,
     * spelled like `__deeplink` rather than like a user event, because no
     * `#[On]` listener should ever see it.
     */
    public const RESPONSE_EVENT = '__asgard_response';

    /**
     * Fingerprint for a prop that had no value at snapshot time — a typed
     * public prop that was never initialised. Distinct from any real
     * value's fingerprint, so "unset then, unset now" compares equal and
     * the server's value applies.
     */
    private const ABSENT = "\0absent";

    /**
     * In-flight requests, keyed by the id the transport returned:
     *
     *   ['component' => WeakReference, 'closure' => ?Closure,
     *    'method' => string, 'sent' => array<string,string>]
     *
     * `sent` is fingerprints, not values: the values themselves went to
     * the transport and nothing here needs them again. Holding
     * fingerprints keeps user objects out of the table and makes the
     * compare in §5.2 a string comparison.
     */
    protected static array $pending = [];

    /** `Class::method` => whether it carries #[Server]. */
    protected static array $attributeCache = [];

    /**
     * Call a `#[Server]` method: run its body here, send the request, and
     * hold its closure until an answer arrives (§2).
     *
     * Returns the request id, or null when nothing is in flight because
     * the transport could not start the request. In that case the closure
     * has already run with a failed response (§7) — an offline device is a
     * failure like any other, and nothing queues.
     */
    public static function call(NativeComponent $component, string $method, array $args = []): ?string
    {
        if (! static::isServerMethod($component, $method)) {
            throw new \RuntimeException(
                'Method ['.$method.'] on ['.$component::class.'] is not marked #[Server], so it '
                .'cannot be called as one. The attribute is the allowlist (ASGARD-SERVER-METHODS.md §8).'
            );
        }

        if (! (new \ReflectionMethod($component, $method))->isPublic()) {
            throw new \RuntimeException(
                'Method ['.$method.'] on ['.$component::class.'] is marked #[Server] but is not public. '
                .'The two halves of a split component are two implementations of one public method.'
            );
        }

        // The client body. Optimistic updates, loading flags and
        // validation all happen here, and the state they leave behind is
        // what the request carries — hence the snapshot below it. It runs
        // through the same guard as every other interaction, so a
        // #[Server] lifecycle hook or a NativeComponent method stays
        // uncallable from a template.
        $closure = ComponentMethodInvoker::invoke($component, $method, $args);

        if ($closure !== null && ! $closure instanceof \Closure) {
            NativeRouter::debugLog(
                'Asgard: #[Server] method '.$component::class.'::'.$method.'() returned a '
                .get_debug_type($closure).' rather than a Closure — no continuation will run.'
            );

            $closure = null;
        }

        $state = static::snapshot($component);

        $request = new ServerRequest(
            component: ComponentAddress::for($component),
            method: $method,
            args: $args,
            state: $state,
        );

        try {
            $id = static::transport()->send($request);
        } catch (\Throwable $e) {
            // §7: network unreachable is a failure like any other. The
            // client body has already run; run the closure so the screen
            // leaves its loading state, and queue nothing.
            NativeRouter::debugLog('Asgard: send failed for '.$request->component.'::'.$method.'() — '.$e->getMessage());

            static::runClosure($component, $closure, ServerResponse::failure('', $e->getMessage()));

            return null;
        }

        if ($id === '') {
            NativeRouter::debugLog('Asgard: transport returned an empty request id for '.$method.'() — treating as a failure.');

            static::runClosure($component, $closure, ServerResponse::failure('', 'The transport returned no request id.'));

            return null;
        }

        static::$pending[$id] = [
            'component' => \WeakReference::create($component),
            'closure' => $closure,
            'method' => $method,
            'sent' => static::fingerprints($state),
        ];

        return $id;
    }

    /**
     * A response arrived (§4). Returns whether it was delivered to a
     * closure; false means it was discarded.
     *
     * Payload shape, as posted by the native layer:
     *
     *   ['id' => string, 'ok' => bool, 'delta' => array,
     *    'fragments' => array, 'error' => ?string, 'code' => int|string|null]
     *
     * `delta` is only the props the server changed (§5.1), never a full
     * state snapshot. A server that sends everything back maximises the
     * clobber surface for no benefit, and every extra prop is one more
     * compare-and-set that can lose.
     */
    public static function deliver(array $payload): bool
    {
        $id = (string) ($payload['id'] ?? '');
        $entry = static::$pending[$id] ?? null;

        if ($entry === null) {
            NativeRouter::debugLog('Asgard: response for unknown request id ['.$id.'] — discarded.');

            return false;
        }

        // Drop the entry before anything else runs. The closure may start
        // another request, and a closure that throws must not leave a
        // resolved request in the table.
        unset(static::$pending[$id]);

        $component = $entry['component']->get();

        if ($component === null) {
            NativeRouter::debugLog(
                'Asgard: response for ['.$id.'] arrived after its component was gone — discarded, closure and all.'
            );

            return false;
        }

        $ok = (bool) ($payload['ok'] ?? true);

        if (! $ok) {
            $code = $payload['code'] ?? null;

            // No state delta is applied for a failed response (§7). Not
            // even a partial one: a server that failed has no claim on
            // the client's state.
            static::runClosure($component, $entry['closure'], ServerResponse::failure(
                id: $id,
                error: isset($payload['error']) ? (string) $payload['error'] : null,
                code: is_int($code) || is_string($code) ? $code : null,
            ));

            return true;
        }

        [$applied, $refused] = static::reconcile(
            $component,
            is_array($payload['delta'] ?? null) ? $payload['delta'] : [],
            $entry['sent'],
        );

        $refusedFragments = static::applyFragments(
            is_array($payload['fragments'] ?? null) ? $payload['fragments'] : []
        );

        static::runClosure($component, $entry['closure'], new ServerResponse(
            id: $id,
            ok: true,
            applied: $applied,
            refused: $refused,
            refusedFragments: $refusedFragments,
        ));

        return true;
    }

    /**
     * Drop every in-flight request owned by a component. Called from
     * `NativeComponent::unmount()`: the table holds the component and its
     * closure, so leaving entries behind keeps a dead screen alive, and a
     * continuation for a screen the user has left has nothing to update.
     */
    public static function forget(NativeComponent $component): void
    {
        foreach (static::$pending as $id => $entry) {
            $owner = $entry['component']->get();

            if ($owner === null || $owner === $component) {
                unset(static::$pending[$id]);
            }
        }
    }

    /** Does this method carry #[Server]? Cached per class and method. */
    public static function isServerMethod(object $component, string $method): bool
    {
        $key = $component::class.'::'.$method;

        if (array_key_exists($key, static::$attributeCache)) {
            return static::$attributeCache[$key];
        }

        if (! method_exists($component, $method)) {
            return static::$attributeCache[$key] = false;
        }

        return static::$attributeCache[$key] = (new \ReflectionMethod($component, $method))
            ->getAttributes(Server::class) !== [];
    }

    /** Ids of the requests currently in flight. */
    public static function pending(): array
    {
        return array_keys(static::$pending);
    }

    public static function isPending(string $id): bool
    {
        return isset(static::$pending[$id]);
    }

    /** Forget every in-flight request. */
    public static function flush(): void
    {
        static::$pending = [];
    }

    // ── Internals ────────────────────────────────────

    protected static function transport(): ServerTransport
    {
        return app()->bound(ServerTransport::class)
            ? app(ServerTransport::class)
            : new NullServerTransport;
    }

    /**
     * Apply the server's delta under the compare-and-set rule (§5.2).
     *
     * For each prop the server changed, compare the client's CURRENT
     * value against the value that was SENT in this request. Apply the
     * server's value only if they still match.
     *
     * That one rule is the whole reconciliation, and it buys two
     * properties without any sequence numbers:
     *
     *  - A field the user typed into while the request was in flight has
     *    diverged from what was sent, so the server's value is refused
     *    and the edit survives.
     *  - A stale response loses on any prop a newer response already
     *    changed, for exactly the same reason. Out-of-order delivery
     *    needs no special case.
     *  - ABA (changed and changed back) matches what was sent, so the
     *    server wins — correct, because the client's value IS the value
     *    the server was reasoning about.
     *
     * @return array{0: array, 1: array} [applied, refused]
     */
    protected static function reconcile(NativeComponent $component, array $delta, array $sent): array
    {
        $applied = [];
        $refused = [];
        $writable = static::publicProperties($component);

        foreach ($delta as $prop => $value) {
            $prop = (string) $prop;

            // Drift: the server changed a prop this half does not have.
            // Silently ignored the way §5.3 ignores a field present on
            // one side only, but reported on the response rather than
            // vanishing — a refusal a developer can see beats one they
            // discover visually.
            if (! isset($writable[$prop])) {
                NativeRouter::debugLog(
                    'Asgard: delta prop ['.$prop.'] is not a public property of '.$component::class.' — ignored.'
                );

                $refused[$prop] = $value;

                continue;
            }

            $current = $writable[$prop]->isInitialized($component)
                ? static::fingerprint($writable[$prop]->getValue($component))
                : self::ABSENT;

            if ($current !== ($sent[$prop] ?? self::ABSENT)) {
                $refused[$prop] = $value;

                continue;
            }

            // Assigned directly rather than through __syncProperty: that
            // path is for user input (it fires updatedFoo() hooks and
            // refuses #[Locked] props). A server delta is neither. It is
            // the authority for the state it changed, and #[Locked]
            // exists to stop a *binding* writing a prop, not to stop the
            // server that owns it.
            $writable[$prop]->setValue($component, $value);

            $applied[$prop] = $value;
        }

        return [$applied, $refused];
    }

    /**
     * Store any fragments the response carried, one per region (§9).
     *
     * A `#[Server]` response is allowed to update a region the same call
     * touched, and it goes through `ServerRegion::applyFragment()` rather
     * than around it — same validation, same stripping of
     * compiler-internal prop keys (§9.3). A server that could write
     * `_press` into props would be choosing which CLIENT method runs, and
     * the fact that the fragment arrived by this route rather than a
     * region render changes nothing about that.
     *
     * `applyFragment()` throws instead of returning false when
     * `app.debug` is on. That is caught here: §7 says the closure always
     * runs unless the component is gone, and a debug-mode throw out of
     * delivery would skip it. The reason is reported on the response
     * instead.
     *
     * @return array region => why the fragment was refused
     */
    protected static function applyFragments(array $fragments): array
    {
        $refused = [];

        foreach ($fragments as $region => $tree) {
            $region = (string) $region;

            if (! is_array($tree)) {
                $refused[$region] = 'the fragment is not a tree';

                continue;
            }

            try {
                if (! ServerRegion::applyFragment($region, $tree)) {
                    $refused[$region] = 'the fragment cannot be rendered by this build';
                }
            } catch (\Throwable $e) {
                $refused[$region] = $e->getMessage();
            }

            if (isset($refused[$region])) {
                NativeRouter::debugLog('Asgard: fragment for region ['.$region.'] refused — '.$refused[$region]);
            }
        }

        return $refused;
    }

    /**
     * Run the continuation the client body returned, bound to the
     * component so `$this->loading = false` means what it reads as.
     *
     * A closure defined inside the method body is already bound; the
     * rebind covers one handed in from elsewhere, and a static closure
     * cannot be bound at all (same check the fluent native-event
     * listeners make).
     */
    protected static function runClosure(NativeComponent $component, ?\Closure $closure, ServerResponse $response): void
    {
        if ($closure === null) {
            return;
        }

        $bound = (new \ReflectionFunction($closure))->isStatic()
            ? $closure
            : \Closure::bind($closure, $component, $component::class);

        $bound($response);
    }

    /**
     * The component's public props — the state a request carries (§2).
     *
     * Public only, and that is a boundary rather than a convenience: a
     * delta can therefore never reach the `native*` internals a component
     * keeps protected, whatever a server puts in it.
     *
     * An uninitialised typed prop is left out rather than read.
     * `ReflectionProperty::getValue()` throws on one, and a screen with
     * `public User $user;` filled in by mount() is an ordinary shape.
     */
    protected static function snapshot(NativeComponent $component): array
    {
        $state = [];

        foreach (static::publicProperties($component) as $name => $prop) {
            if ($prop->isInitialized($component)) {
                $state[$name] = $prop->getValue($component);
            }
        }

        return $state;
    }

    /** @return array<string, \ReflectionProperty> */
    protected static function publicProperties(NativeComponent $component): array
    {
        $props = [];

        foreach ((new \ReflectionClass($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (! $prop->isStatic()) {
                $props[$prop->getName()] = $prop;
            }
        }

        return $props;
    }

    /** @return array<string, string> */
    protected static function fingerprints(array $state): array
    {
        return array_map(static fn ($value) => static::fingerprint($value), $state);
    }

    /**
     * A comparable stand-in for a prop's value.
     *
     * The compare in §5.2 has to answer "is this still what we sent?",
     * and holding the sent values themselves cannot answer it for
     * objects: a snapshot of `$this->filters` is the same instance the
     * component still holds, so mutating it in place would leave the
     * "sent" copy mutated too and the compare would say nothing changed.
     * Serialising at send time freezes the value instead, and makes an
     * equal-but-distinct object (a rebuilt DateTime, say) read as
     * unchanged, which is what "still equals" should mean.
     *
     * Values that cannot be serialised at all (a closure prop, a resource
     * handle) fall back to identity, which errs towards refusing the
     * server's value. Refusing is the safe direction: the user's data
     * survives.
     */
    protected static function fingerprint(mixed $value): string
    {
        try {
            return 'v:'.serialize($value);
        } catch (\Throwable) {
            return 'r:'.(is_object($value) ? spl_object_hash($value) : gettype($value));
        }
    }
}
