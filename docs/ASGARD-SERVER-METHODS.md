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

The method must be **public**. A protected one passes the attribute check and
then fatals on invocation, so it is rejected with a clear exception instead.

### 1.1 How it gets called, and the trap in this shape

Dispatch is intercepted in two places: `NativeComponent::__invokeInteraction()`,
which every `@tap` and every `TestableComponent::call()` goes through, and an
explicit `callServerMethod()`. Both run the client body through
`ComponentMethodInvoker`, so `#[Server]` never makes callable a method the
interaction guard refuses: lifecycle hooks, non-public methods and anything
declared on `NativeComponent` stay out of reach.

**A direct `$this->refreshChart('30d')` is a silent no-op.** The body runs, a
closure comes back, and nothing is sent, because PHP gives no way to intercept
an ordinary method call. The shape in §1 invites exactly that mistake: it looks
like a normal call and reads like one.

This is a known flaw in the design, not in the implementation. Fixing it
properly means changing the shape so the method hands back a request object
that has to be acted on, rather than a closure that can be dropped on the
floor. Until then, `callServerMethod()` is the only supported programmatic
entry point.

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

**The response does not come back to the component that made the call.** The
`EVENT_NATIVE` frame lands on whichever component owns the runloop, which is
the screen, but the request may have been made by a child several levels down.
So the pending table is keyed by request id and shared, not held per component.

**The closure keeps its component alive**, through its bound `$this`. Holding
the component weakly in the table therefore buys nothing on its own; a weak
reference only bites for a call with no continuation. Clearing the entry on
unmount is the mechanism that actually works, and it is not optional.

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

**Equality is a fingerprint, not a value comparison, and this matters.** The
naive implementation keeps the sent values and compares them to the current
ones. For an object prop that is wrong in a way that loses data: the table
holds the same instance the component holds, so mutating it in place mutates
the "sent" copy too, every comparison says unchanged, and the user's edit is
overwritten. The table stores `serialize()` of each prop at send time and
compares strings.

Two consequences worth knowing. An equal-but-rebuilt object reads as unchanged,
which is the right answer. And a value that cannot be serialised falls back to
identity, which errs towards refusing the server.

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

A call waits at most its component's `serverActionTimeout()` for an answer:
`nativephp.asgard.action_timeout`, 15 seconds by default, or 0 for no limit.
After that its closure runs with a failed response (`code` is `timeout`), and
an answer that lands later is dropped. The timer is acted on by the runloop of
the screen the component is on, which wakes for it with no input needed.

Every failure, a timeout included, also fires
`Native\Mobile\Events\Asgard\ServerActionFailed` on the screen, the same
event a failed fragment action fires (protocol §10.2). `action` is the
method's name and `region` is null, so a screen can handle every failed server
call in one place:

```php
#[On(ServerActionFailed::class, when: ['action' => 'refreshChart'])]
public function refreshFailed(string $reason, ?int $status): void {}
```

`reason` is `timeout`, `network` (it could not be sent, or the native layer
reported no HTTP status) or `server` (the failure carried an HTTP status,
which is `status`).

**Delivery never throws.** §5.3 of the protocol doc wants a throw in debug when
the two halves have drifted, and that collides with the promise above: a throw
out of delivery skips the closure and strands whatever it was going to clean
up. Drift is reported as a refusal plus a debug log instead. The same applies
to a fragment that fails §9.4 validation, which is reported rather than raised.

**A send that never starts runs its closure inline**, before the calling method
has returned. That is worth knowing before writing a closure that assumes it
runs on a later frame.

## 8. Security

`#[Server]` is the allowlist. A method without it is not callable from this
path on either side, which closes the `method_exists()` breadth noted in §10 of
the protocol doc.

Everything else is inherited: signed and encrypted under the session secret,
identity from the bearer token and never from the payload, state and arguments
treated as untrusted input, hydration against exact class allowlists.

## 9. Open questions

1. Whether a dedicated failure hook reads better than branching inside one
   closure. **Still open.**
2. **The silent direct call in §1.1.** The largest open question in this
   design, and the one that decides whether the closure-return shape survives.
3. A closure starting another `#[Server]` call is **safe** but unbounded: the
   pending entry is dropped before the closure runs, so there is no re-entrancy
   hazard, but nothing stops a loop.

### Answered by implementing

- **Returning no closure** works, and the delta still applies. It is also the
  trap it looked like: a `: void` client body is indistinguishable from a
  forgotten `return`.
- **Refused props are not a debug error.** A refusal is the rule working
  correctly, not a fault, and raising on it would train people to route around
  the protection.

## 10. Adjacent, pre-existing

`NativeComponent::getPublicProperties()` throws on an uninitialised typed
public property, so a component with one cannot render at all today,
independently of anything here. State snapshots skip such props and treat
absent-then, absent-now as unchanged.
