<?php

namespace Native\Mobile\Edge\Asgard;

use Illuminate\Support\Facades\Log;
use Native\Mobile\Attributes\Server;
use Native\Mobile\Edge\Asgard\Concerns\UsesServer;
use Native\Mobile\Edge\Asgard\Contracts\ServerTransport;
use Native\Mobile\Edge\Asgard\Contracts\UploadTransport;
use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Asgard\ServerActionFailed;

/**
 * The client half of `#[Server]` methods
 * (docs/ASGARD-SERVER-METHODS.md).
 *
 * Three jobs, in the order they happen:
 *
 *  1. **Invoke** (§2). Run the client body, snapshot the component's
 *     public state AFTER it returns, hand method + args + state to the
 *     transport, and keep the closure the body returned.
 *  2. **Deliver** (§4). The transport performs the request off the UI
 *     thread (§3) and hands the answer to deliver() on it. A transport
 *     may also post it as an `EVENT_NATIVE` frame, which the runloop
 *     routes here too.
 *  3. **Reconcile** (§5). Apply the server's delta prop by prop, but only
 *     where the client's value has not moved since the request left.
 *
 * Uploads (protocol §10.6) take the same path from the transport on:
 * upload() checks the file and sends an UploadRequest with no body and
 * no closure, and its answer is delivered like a call's.
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
 *
 * ## Failures and the timeout
 *
 * A call waits at most ServerRegion::actionTimeout() of its component for an
 * answer. A call that times out, fails to send, or comes back failed runs
 * its closure with a failed ServerResponse, as before, and also fires
 * ServerActionFailed on the screen, so a screen can handle every failed
 * server call in one #[On] listener. A timeout is acted on by the runloop
 * of the screen the component is on (expire()), and an answer that lands
 * after it is dropped.
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

    /** An upload whose file is missing, not a regular file, or unreadable. */
    public const UNREADABLE = 'unreadable';

    /** An upload whose file is over `nativephp.asgard.upload_max`. */
    public const TOO_LARGE = 'too_large';

    /**
     * In-flight requests, keyed by the id the transport returned:
     *
     *   ['component' => WeakReference, 'closure' => ?Closure,
     *    'method' => string, 'sent' => array<string,string>,
     *    'address' => string, 'deadline' => ?float, 'timeout' => float,
     *    'stamp' => string, 'state' => array]
     *
     * `stamp` places the call in ServerRegion's request sequence and `state`
     * is the state a region request would have carried when it went out.
     * Both are for regions the answer carries.
     *
     * `sent` is fingerprints, not values: the values themselves went to
     * the transport and nothing here needs them again. Holding
     * fingerprints keeps user objects out of the table and makes the
     * compare in §5.2 a string comparison.
     */
    protected static array $pending = [];

    /** `Class::method` => whether it carries #[Server]. */
    protected static array $attributeCache = [];

    /** Class => whether it uses UsesServer. */
    protected static array $traitCache = [];

    /**
     * How many send() calls are running. An answer that arrives while one
     * is running may be for the request it is starting, whose id is not in
     * the table yet. A transport that answers inline does that: a faked
     * lane in a test, or one that fails before it starts.
     */
    protected static int $sending = 0;

    /** Answers that arrived during a send(), by id, held until it returns. */
    protected static array $early = [];

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

        static::ensureUsesServer($component, $method);

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
        $address = ComponentAddress::for($component);

        $request = new ServerRequest(
            component: $address,
            method: $method,
            args: $args,
            state: $state,
            platform: ServerRegion::platform(),
        );

        return static::begin(
            $component, $method, $closure, $address, $state,
            ServerRegion::actionTimeout($component),
            fn () => static::transport()->send($request),
        );
    }

    /**
     * Send a file to an `#[Upload]` method on the server (protocol §10.6).
     *
     * There is no body on the device and no closure. The request carries
     * the component's state as it is now, and the answer is handled
     * exactly like a call's: the delta under compare-and-set, then any
     * regions and hints. A failure fires ServerActionFailed with `action`
     * set to the method's name.
     *
     * Two failures happen here, before anything is sent, and fire the
     * event at once: `unreadable` when the path is not a readable regular
     * file, and `too_large` when it is over `nativephp.asgard.upload_max`.
     *
     * Returns the request id, or null when nothing is in flight.
     */
    public static function upload(NativeComponent $component, string $method, string $path, array $args = []): ?string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D', $method)) {
            throw new \InvalidArgumentException('['.$method.'] is not a method name, so it cannot be uploaded to.');
        }

        $address = ComponentAddress::for($component);
        $file = static::readableFile($path);

        if (is_string($file)) {
            NativeRouter::debugLog('Asgard: upload to '.$address.'::'.$method.'() not sent. '.$file);
            static::fireFailed($method, $address, self::UNREADABLE, null, '', $file);

            return null;
        }

        [$real, $size] = $file;
        $max = static::uploadMax();

        if ($max > 0 && $size > $max) {
            $message = 'The file is '.$size.' bytes and the most this app sends is '.$max.'.';
            NativeRouter::debugLog('Asgard: upload to '.$address.'::'.$method.'() not sent. '.$message);
            static::fireFailed($method, $address, self::TOO_LARGE, null, '', $message);

            return null;
        }

        $state = static::snapshot($component);

        $request = new UploadRequest(
            component: $address,
            method: $method,
            args: array_values($args),
            state: $state,
            platform: ServerRegion::platform(),
            path: $real,
            filename: basename($real),
            mime: UploadRequest::guessMime($real),
            size: $size,
        );

        return static::begin(
            $component, $method, null, $address, $state,
            static::uploadTimeout($component),
            fn () => static::uploadTransport()->upload($request),
        );
    }

    /**
     * Seconds an upload from this component waits for its answer. A
     * component that has serverUploadTimeout(), from UsesServer or its
     * own, decides. Any other reads `nativephp.asgard.upload_timeout`,
     * 120 by default.
     */
    public static function uploadTimeout(NativeComponent $owner): float
    {
        return method_exists($owner, 'serverUploadTimeout')
            ? (float) $owner->serverUploadTimeout()
            : (float) config('nativephp.asgard.upload_timeout', 120);
    }

    /** The largest file uploadToServer() sends, in bytes. 0 or less is no limit. */
    public static function uploadMax(): int
    {
        return (int) config('nativephp.asgard.upload_max', 20 * 1024 * 1024);
    }

    /**
     * Hand the request to the transport and put it in the pending table.
     * Shared by calls and uploads. `$send` starts the request and returns
     * its id.
     *
     * @param  \Closure(): string  $send
     */
    protected static function begin(NativeComponent $component, string $method, ?\Closure $closure, string $address, array $state, float $seconds, \Closure $send): ?string
    {
        // Taken as the call goes out, so regions its answer carries can be
        // ordered against region requests (ServerRegion::paintInline()).
        $stamp = ServerRegion::stamp();
        $regionState = ServerRegion::stateOf($component);

        static::$sending++;

        try {
            $id = $send();
        } catch (\Throwable $e) {
            static::sent();

            // §7: network unreachable is a failure like any other. The
            // client body has already run; run the closure so the screen
            // leaves its loading state, and queue nothing.
            NativeRouter::debugLog('Asgard: send failed for '.$address.'::'.$method.'() — '.$e->getMessage());

            static::runClosure($component, $closure, ServerResponse::failure('', $e->getMessage()));
            static::fireFailed($method, $address, ServerRegion::NETWORK, null, '', $e->getMessage());

            return null;
        }

        // An answer that beat send() back, held until the call is known.
        $early = static::$early[$id] ?? null;
        unset(static::$early[$id]);
        static::sent();

        if ($id === '') {
            NativeRouter::debugLog('Asgard: transport returned an empty request id for '.$method.'() — treating as a failure.');

            static::runClosure($component, $closure, ServerResponse::failure('', 'The transport returned no request id.'));
            static::fireFailed($method, $address, ServerRegion::NETWORK, null, '', 'The transport returned no request id.');

            return null;
        }

        static::$pending[$id] = [
            'component' => \WeakReference::create($component),
            'closure' => $closure,
            'method' => $method,
            'sent' => static::fingerprints($state),
            'address' => $address,
            'deadline' => $seconds > 0 ? ServerRegion::now() + $seconds * 1000 : null,
            'timeout' => $seconds,
            'stamp' => $stamp,
            'state' => $regionState,
        ];

        if ($early !== null) {
            static::deliver($early);
        }

        return $id;
    }

    /**
     * A response arrived (§4). Returns whether it was delivered to a
     * closure; false means it was discarded.
     *
     * Payload shape, as posted by the native layer:
     *
     *   ['id' => string, 'ok' => bool, 'delta' => array,
     *    'fragments' => array, 'refresh' => array,
     *    'error' => ?string, 'code' => int|string|null]
     *
     * `fragments` and `refresh` are what refresh() asked for on the server:
     * other regions of the calling component,
     * `{region: {fragment, version?, chrome?}}`, and hints naming other
     * components. The regions are painted after the delta is applied and
     * before the closure runs, as ServerRegion::paintInline() says. The
     * hints go to ServerRegion::hints().
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

        if ($entry === null && static::$sending > 0 && $id !== '') {
            static::$early[$id] = $payload;

            return false;
        }

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
            $error = isset($payload['error']) ? (string) $payload['error'] : null;

            static::runClosure($component, $entry['closure'], ServerResponse::failure(
                id: $id,
                error: $error,
                code: is_int($code) || is_string($code) ? $code : null,
            ));

            // An HTTP status means the server answered. Anything else is
            // the network's (a native error code, or none).
            $status = is_int($code) && $code >= 100 ? $code : null;
            static::fireFailed($entry['method'], $entry['address'], $status === null ? ServerRegion::NETWORK : ServerRegion::SERVER, $status, $id, $error);

            return true;
        }

        [$applied, $refused] = static::reconcile(
            $component,
            is_array($payload['delta'] ?? null) ? $payload['delta'] : [],
            $entry['sent'],
        );

        $refusedFragments = static::applyFragments(
            $component,
            $entry,
            is_array($payload['fragments'] ?? null) ? $payload['fragments'] : []
        );

        if (is_array($payload['refresh'] ?? null) && $payload['refresh'] !== []) {
            ServerRegion::hints($payload['refresh']);
        }

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

    /** Whether the component's class uses the UsesServer trait. */
    public static function usesServer(object $component): bool
    {
        return static::$traitCache[$component::class]
            ??= in_array(UsesServer::class, class_uses_recursive($component), true);
    }

    /**
     * A component with `#[Server]` methods must use UsesServer
     * (ASGARD-SERVER-METHODS.md §1). The trait gives it callServerMethod(),
     * which is the only right way to call one from code. Without it a
     * developer calls the method directly and gets a closure nothing will
     * ever resolve.
     *
     * With `app.debug` on this throws before the body runs. Without it the
     * mistake is logged and the call goes out as normal, so a shipped app
     * keeps working.
     */
    protected static function ensureUsesServer(NativeComponent $component, string $method): void
    {
        if (static::usesServer($component)) {
            return;
        }

        $message = 'Asgard: ['.$component::class.'] has the #[Server] method ['.$method.'] but does not use '
            .'the UsesServer trait. Add `use UsesServer;` ('.UsesServer::class.') to the class.';

        if (config('app.debug')) {
            throw new \LogicException($message);
        }

        Log::warning($message);
    }

    /** Whether any call in flight has a deadline. */
    public static function hasDeadlines(): bool
    {
        foreach (static::$pending as $entry) {
            if ($entry['deadline'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the next call of these components times out, in milliseconds on
     * ServerRegion::now()'s clock, or null when none will.
     *
     * @param  iterable<NativeComponent>  $components
     */
    public static function nextDeadline(iterable $components): ?float
    {
        $mine = static::idsOf($components);
        $next = null;

        foreach (static::$pending as $entry) {
            $owner = $entry['component']->get();

            if ($entry['deadline'] !== null && $owner !== null && isset($mine[spl_object_id($owner)])) {
                $next = min($next ?? $entry['deadline'], $entry['deadline']);
            }
        }

        return $next;
    }

    /**
     * Time out every call of these components whose deadline has passed:
     * its closure runs with a failed response, ServerActionFailed fires
     * with reason `timeout`, and an answer that lands later is dropped.
     * Called by the runloop with its screen's components. Returns whether
     * any timed out.
     *
     * @param  iterable<NativeComponent>  $components
     */
    public static function expire(iterable $components): bool
    {
        $mine = static::idsOf($components);
        $now = ServerRegion::now();
        $due = [];

        foreach (static::$pending as $id => $entry) {
            if ($entry['deadline'] === null || $entry['deadline'] > $now) {
                continue;
            }

            $owner = $entry['component']->get();

            if ($owner === null) {
                unset(static::$pending[$id]);

                continue;
            }

            if (isset($mine[spl_object_id($owner)])) {
                unset(static::$pending[$id]);
                $due[$id] = [$owner, $entry];
            }
        }

        foreach ($due as $id => [$owner, $entry]) {
            $message = 'No answer within '.round($entry['timeout'], 3).' seconds.';
            NativeRouter::debugLog('Asgard: '.$entry['address'].'::'.$entry['method'].'() timed out.');

            static::runClosure($owner, $entry['closure'], ServerResponse::failure($id, $message, 'timeout'));
            static::fireFailed($entry['method'], $entry['address'], ServerRegion::TIMEOUT, null, (string) $id, $message);
        }

        return $due !== [];
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
        static::$early = [];
        static::$sending = 0;
    }

    // ── Internals ────────────────────────────────────

    /**
     * A send() finished. Once none is running, any early answer left over
     * is for a call that never got an id, so it is dropped.
     */
    protected static function sent(): void
    {
        static::$sending = max(0, static::$sending - 1);

        if (static::$sending === 0) {
            static::$early = [];
        }
    }

    protected static function fireFailed(string $method, string $component, string $reason, ?int $status, string $id, ?string $message): void
    {
        ServerRegion::fire(new ServerActionFailed(
            action: $method,
            component: $component,
            region: null,
            reason: $reason,
            status: $status,
            requestId: $id,
            message: $message,
        ));
    }

    /**
     * @param  iterable<NativeComponent>  $components
     * @return array<int, NativeComponent>
     */
    protected static function idsOf(iterable $components): array
    {
        $ids = [];

        foreach ($components as $component) {
            $ids[spl_object_id($component)] = $component;
        }

        return $ids;
    }

    protected static function transport(): ServerTransport
    {
        return app()->bound(ServerTransport::class)
            ? app(ServerTransport::class)
            : new NullServerTransport;
    }

    protected static function uploadTransport(): UploadTransport
    {
        return app()->bound(UploadTransport::class)
            ? app(UploadTransport::class)
            : new NullUploadTransport;
    }

    /**
     * The real path and size of a file the app can read, or why not.
     *
     * Only a plain local path: no stream wrapper (`php://`, `phar://`, a
     * URL) and no NUL byte. It must be a regular file, not a directory, and
     * not a symlink, so a link the app was handed cannot point the upload
     * somewhere else. The path is resolved once here and the lane reads
     * that resolved path.
     *
     * @return array{0: string, 1: int}|string
     */
    protected static function readableFile(string $path): array|string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            return 'The path is not a local file path.';
        }

        clearstatcache(true, $path);

        if (is_link($path)) {
            return 'The path is a symlink.';
        }

        $real = realpath($path);

        if ($real === false || ! is_file($real)) {
            return 'There is no file at the path.';
        }

        if (! is_readable($real)) {
            return 'The file cannot be read.';
        }

        $size = filesize($real);

        if ($size === false) {
            return 'The file cannot be read.';
        }

        return [$real, $size];
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
     * Paint the regions the response carried (§9), through
     * ServerRegion::paintInline(): only regions this component drew, only
     * when no newer request for them went out, and through the same checks
     * and stripping of compiler-internal prop keys as any fragment (§9.3).
     * A server that could write `_press` into props would be choosing which
     * CLIENT method runs, and the route a fragment took changes nothing
     * about that.
     *
     * A fragment that cannot be rendered never throws out of delivery, even
     * with `app.debug` on: §7 says the closure always runs unless the
     * component is gone. The reason is reported on the response instead.
     *
     * @param  array{stamp: string, state: array}  $entry
     * @return array region => why the fragment was refused
     */
    protected static function applyFragments(NativeComponent $component, array $entry, array $fragments): array
    {
        return $fragments === []
            ? []
            : ServerRegion::paintInline($component, $entry['stamp'], $fragments, $entry['state']);
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
