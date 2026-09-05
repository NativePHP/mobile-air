# `#[Server]` methods

**Status:** draft, unimplemented. Companion to `ASGARD-PROTOCOL.md`, which
defines the transport, session, crypto and component addressing this reuses
wholesale.

`@server` regions are declarative: a part of a screen renders on a server.
`#[Server]` methods are the imperative counterpart: a method call goes to a
server, and a continuation runs on the client when it answers.

They are two primitives over one protocol. Same handshake, same session, same
signing and encryption, same dot-notation component addressing.

## 1. Shape

```php
#[Server]
public function refreshChart(string $range): Closure
{
    // Runs on the CLIENT, before anything is sent.
    $this->loading = true;
    $this->range = $range;

    // Runs on the CLIENT, when the server answers.
    return function (ServerResponse $response) {
        $this->loading = false;
    };
}
```

The server half is an ordinary method of the same name on the server
component:

```php
#[Server]
public function refreshChart(string $range): void
{
    // Runs on the SERVER.
    $this->chartData = $this->warehouse->salesFor($range);
}
```

Two separate implementations sharing a name. The client body is a local
pre-step: optimistic updates, a loading flag, validation. The server body is
the remote work.

## 2. What crosses the wire

The method name, its arguments, and a snapshot of the component's public state
taken **after the client body returns**. That ordering is the point: local
mutations made in the body are part of the request.

**No client-side logic is sent.** The closure is never serialised, inspected,
or transmitted. It is a PHP value held on the device for the lifetime of the
request. The server receives data and a method name, exactly as it does for a
region render, and resolves that name through the same boot-time registry
(§4 of the protocol doc) rather than by reflection on a wire string.

Arguments are client-authored and therefore untrusted, on the same footing as
state. See §5.1 of the protocol doc.

## 3. The request must be issued natively

PHP on device runs a blocking runloop: it waits on `nativephp_element_wait_event()`,
dispatches, re-renders, waits again. A blocking HTTP call inside that loop
freezes the UI for the whole round trip, which is exactly what the closure
design exists to avoid.

So the request is handed to the native layer and performed asynchronously
there. On completion, native posts an `EVENT_NATIVE` frame (type 20) carrying
the response. The runloop wakes on its existing event channel and
`NativeComponent::dispatchNativeEvent()` routes it.

This needs a bridge function on both platforms. It is the main new native work
for this feature, and the reason `#[Server]` is not implementable purely in PHP.

## 4. Delivery

When a response arrives:

1. Look up the pending request by id.
2. If the owning component is no longer mounted, **discard**: drop the state
   delta and the closure, log at debug. A continuation for a screen the user
   has left has nothing to update.
3. Apply the state delta under the rule in §5.
4. Invoke the closure bound to the component instance.
5. Re-render.

The pending-request table holds a reference to the component and its closure,
so it must be cleared on unmount or it keeps a dead screen alive.

## 5. Reconciliation

A response may be seconds old. The user may have typed into a field, toggled a
switch, or navigated within the screen since the request left. None of that may
be silently overwritten.

Two rules, together:

### 5.1 The server returns a delta

Only the props the server actually changed, never a full state snapshot.
Returning everything would maximise the clobber surface for no benefit.

### 5.2 The client applies a prop only if it has not moved

For each prop in the delta, compare the client's **current** value against the
value that was **sent** in the request. Apply the server's value only if they
are still equal.

That is compare-and-set, and it gives the behaviour asked for directly: a field
the user touched while the request was in flight has diverged from what was
sent, so the server's value is refused and the user's edit survives.

Two properties fall out of it for free:

- **Out-of-order responses.** A stale response loses on any prop a newer
  response already changed, because the local value no longer matches what the
  stale request sent. No sequence numbers needed.
- **ABA.** A value changed and changed back matches what was sent, so the
  server wins. Correct: the client's value is the value the server was
  reasoning about.

Refused props are reported on the `ServerResponse` handed to the closure, so a
developer can react rather than discovering it visually.

## 6. Concurrency

Multiple `#[Server]` calls may be in flight at once, including several of the
same method. §5.2 keeps them safe rather than ordered.

An explicit policy for superseding calls (cancel the previous in-flight call of
the same method, or debounce) is deliberately **not** in v1. It is an attribute
argument when it is needed: `#[Server(supersede: true)]`.

## 7. Failure

The closure always runs, unless the component is gone. `ServerResponse` carries
the outcome, so the failure path is the same code path:

```php
return function (ServerResponse $response) {
    $this->loading = false;

    if ($response->failed()) {
        $this->error = 'Could not refresh.';
    }
};
```

No state delta is applied for a failed response.

Network unreachable is a failure like any other. A `#[Server]` method called
offline runs its client body, fails fast, and runs its closure. Nothing queues
in v1.

## 8. Security

`#[Server]` is the allowlist. A method without it is not callable from this
path on either side, which closes the `method_exists()` breadth noted in §10 of
the protocol doc.

Everything else is inherited: signed and encrypted under the session secret,
identity from the bearer token and never from the payload, state and arguments
treated as untrusted input, hydration against exact class allowlists.

## 9. Open questions

1. Whether a dedicated failure hook reads better than branching inside one
   closure.
2. Whether the closure should be allowed to call another `#[Server]` method,
   and what stops a loop if so.
3. Whether a `#[Server]` method may return no closure at all (fire and forget),
   and whether that is a useful shape or a trap.
4. Whether refused props (§5.2) should be a hard error in debug builds, the way
   the field-compatibility rule is.
