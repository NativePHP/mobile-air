# The Asgard Protocol

**Status:** draft v1, unimplemented. Names and numbers here are proposals, not
commitments.

Asgard lets a server render part of a NativePHP app's native UI. A client app
marks a region of a screen as server-rendered, sends its state to a server, and
gets back a description of a native element subtree to paint in place.

The point of writing this as a protocol rather than a Laravel package is that
the server half is replaceable. Nothing below requires the server to be PHP.
`nativephp/asgard` is the reference server implementation; `nativephp/midgard`
is the reference client. A Rails or Go server that speaks this document is a
first-class participant.

## 1. What this is not

It is not Jump. Jump is a dev bridge: the dev machine owns the whole screen,
renders every frame, and ships full view state to a device that renders and
sends events back. State lives on the dev box.

Asgard inverts that. The **client owns the screen and the state**. It ships
locally, renders offline, and asks a server only for the regions it has been
told to ask about. The server is a renderer for a fragment, not the owner of
the app.

It is also not remote code. The server sends a description of elements. It
never sends Blade, PHP, or anything the client compiles or executes.

## 2. Roles

| Term | Meaning |
|---|---|
| **Client** | The mobile app. Runs `nativephp/mobile` for rendering and `nativephp/midgard` for transport, session and crypto. |
| **Server** | Any HTTPS server implementing this document. |
| **Region** | A named, server-rendered part of a client screen. |
| **Client component** | The half of a split component that ships in the app. Owns state, renders the placeholder. |
| **Server component** | The half that lives on the server. Renders the fragment. |
| **Fragment** | The element subtree a server returns for one region. |

## 3. Regions

A client view marks a server-rendered region with a Blade directive:

```blade
@server('sales-summary')
    <x-skeleton-card />
@endserver
```

The block body is the **placeholder**: what the client paints immediately,
before and during the request. It is ordinary client-side markup and it must
render offline. When a fragment arrives, the client replaces the placeholder
subtree with it.

This is the same shape as the existing `#[Lazy]` attribute
(`src/Attributes/Lazy.php`), which paints a placeholder frame while a slow
`mount()` runs. `@server` is that idea applied to a subtree instead of a whole
screen, with the work happening on a server instead of locally.

A region that never resolves keeps its placeholder. A screen with no network
is a screen with skeletons where the server regions would be, and everything
else working. That is the intended degraded state, not an error case.

## 4. Addressing components

A region names its server component by the client component's fully-qualified
class name converted to dot notation:

```
App\Native\Components\SalesSummary   →   app.native.components.sales_summary
```

Backslashes become dots; each segment is snake-cased.

**The server MUST NOT convert this identifier back into a class name.** The
identifier arrives from a client and is therefore attacker-controlled. Turning
it into a class name and instantiating it is arbitrary object instantiation.

Instead the server resolves it through a map built at boot:

- **Autodiscovery (default).** The package scans the configured namespace and
  builds `dot-notation → FQCN` for every server component it finds. Convention:
  same FQCN on both sides means the two halves of one component.
- **Explicit mapping.** Autodiscovery is switchable off in the published config.
  With it off, the developer registers pairs in a service provider. This is the
  supported path for apps whose two halves do not share a namespace, and the
  only path for non-PHP servers.

An unknown identifier is a 404. It is never a lookup failure that leaks whether
a class exists.

## 5. State

Two pools, with different owners and different trust.

### 5.1 Client state

The client component's public props. The client authors it, mutates it locally,
and sends it with each region request.

It is **untrusted input**. The signature in §7 proves it arrived intact from a
holder of a valid session. It does not prove the values are true, because the
client that produced them is a program on someone else's device. A server must
authorise every action against the authenticated identity from §7.4, never
against something the state claims.

Hydration on the server follows the rules `EdgeSnapshot` already uses in
`nativephp/mobile-web`: scalars, arrays, backed enums, datetimes and
Collections, with tagged markers resolved against **exact class allowlists**.
Nothing in a payload may cause an arbitrary class to be constructed. In
mobile-web that allowlist is defence in depth behind a server-authored seal.
Here the payload really is client-authored, so it is the primary control.

### 5.2 Server session state

State the server keeps across requests, keyed by session (§6). This is where
anything the client must not control belongs.

### 5.3 Compatibility

Halves drift. A field present on one side and not the other is **silently
ignored** in production and **throws** when `config('app.debug')` is on, the
way Eloquent's `preventSilentlyDiscardingAttributes` works. The behaviour is
configurable independently of `app.debug` for teams that want it strict in
staging.

## 6. Sessions

Asgard sessions exist to correlate state across requests and to make requests
cheap to reject early. **They are not authentication.** See §7.4.

They deliberately do not reuse cookie semantics. Cookies ride along
automatically; this exchange is explicit, so a client always knows whether it
has a session and a server never issues one it was not asked for.

### 6.1 Handshake

The client holds `asgard_key`: a per-app, per-environment public identifier
compiled into the build. The server holds `asgard_secret`, its private
counterpart. Both are generated by the packages; both are documented as
unique per app and per environment.

```
→  POST /_asgard/session
   Authorization: Bearer <token>
   { "key": "<asgard_key>", "protocol": 1, "accepts": ["tree/json"] }

←  200
   { "session": "<opaque id>", "secret": "<session secret>",
     "expires_in": 1800, "encoding": "tree/json" }
```

- `session` is an opaque identifier. It goes in a header on every later
  request.
- `secret` is high-entropy, returned **once**, stored client-side, and **never
  transmitted again**. It keys the signature and the encryption in §7.
- The server derives both from `asgard_secret` plus an internal session record.

### 6.2 Use and renewal

Every region request carries `Asgard-Session-Id: <session>`. Each accepted
request slides the expiry forward.

### 6.3 Loss

Either side can lose a session: expiry on the client, eviction on the server.
Both resolve to the same move, and it must be invisible to the app developer
and the user.

| Condition | Status | Client behaviour |
|---|---|---|
| Session unknown or expired | `419` | Re-handshake once, retry the request once. |
| Signature or decryption failed | `403` | **Do not retry.** Surface an error. |
| Bearer token rejected | `401` | Hand to the app's auth flow. |
| Unknown component | `404` | Keep the placeholder, log. |

The split between 419 and 403 matters. A signature bug that returned 419 would
make every client re-handshake and retry forever. Exactly one re-handshake and
one retry per originating request; a second failure surfaces.

## 7. Security

### 7.1 Threat model

Assume: TLS everywhere, but intermediaries that terminate it and log. Assume
the client binary is readable and `asgard_key` is extractable, because it is
compiled into an app anyone can download. Assume some clients are hostile.

### 7.2 What a signature proves

Requests are signed with a key derived from the session `secret` from §6.1.
A valid signature proves the request was produced by something holding a live
session secret and arrived unmodified.

It does not prove the sender is a genuine copy of the app, and it cannot.
`asgard_key` is public by construction, so anyone can complete a handshake.
The signature is an integrity check and a cheap early filter, not
authentication.

### 7.3 Encryption

Bodies are encrypted with AEAD under a key derived from the session `secret`.
Inside TLS this is redundant against a network attacker. It is not redundant
against a logging proxy, which is the case it exists for.

This is why §6.1 returns a `secret` distinct from the `session` id. If the
session identifier in the header were also the key material, any intermediary
that logs headers and bodies could decrypt everything it logged, and the
encryption would protect against nothing it was added for. The identifier
travels on every request; the key never travels again after the handshake.

### 7.4 Authentication is separate

**A client never tells the server who the user is.** Identity comes from an
ordinary `Authorization: Bearer` credential the server verifies independently:
its own session, an OAuth access token, a personal access token. The Asgard
session correlates state; the bearer token establishes identity. A request with
a valid signature and an invalid token is rejected.

### 7.5 Rejecting early

Order of checks, cheapest and most decisive first: session id known → signature
valid → decrypt → bearer token → component resolvable → hydrate. A request that
fails any of the first three is discarded before anything parses its body.

## 8. Transport

Region requests use **HTTP QUERY** over HTTPS: a safe method that carries a
body. A body is required (state can be large and structured) and a GET query
string is the wrong place for it: length-limited, and logged by everything in
the path.

> **Open:** QUERY is a recent method and intermediaries may not pass it. If
> deployment testing shows common infrastructure rejecting it, the protocol
> needs a documented fallback (`POST` plus an override header) negotiated in
> the handshake. Do not treat this section as settled until that testing is
> done.

One endpoint handles all region requests, in the shape of Livewire's `/update`
and mobile-web's `EdgeEndpoint`. The path is derived per installation from the
app key rather than being a well-known constant, so scanners cannot target it
without already having a client:

```
/_asgard-<8 hex>/render
```

## 9. Fragment format

A server returns a **logical tree**: element type names, props, and children.
It does not return the client's in-memory node layout.

This is forced. The device's flat node format encodes each node's type as an
index into a table the device accumulates over a session, in insertion order,
and it is host-endian. Indices produced anywhere else mean something else on
arrival. A server also cannot know the device's platform-resolved icon names,
its `ios:`/`android:` Tailwind variants, or which fonts are in its bundle.

So the server emits names and the **client encodes**. The client interns types
into its own table, resolves icons and platform variants for the device it is
actually running on, and writes its own flat buffer. One encode per fragment,
in exchange for a payload that is immune to endianness, platform, and format
version drift.

`accepts` in the handshake exists so a future encoding can be added without a
breaking change. v1 defines one: `tree/json`.

### 9.1 The client owns its own identifiers

A server returns **no node ids and no callback ids**.

Node ids on the device are a hash of a key path; callback ids are a hash of a
scope plus an expression string. Both are namespaced to where they sit in the
client's tree. A server has no way to know that namespace, and requiring it to
would leak the client's internals into the protocol and make third-party
servers hard to write.

Instead the server returns relative keys and callback **expressions**. The
client prefixes keys with the region's path and derives ids in its own scope
when it encodes. The server never needs to know how ids are computed.

## 10. Events

A tap inside a server-rendered fragment resolves on the server, because that is
where the method lives.

The fragment carries its callback expression map, exactly as mobile-web's
snapshot carries `callbacks`. Callback ids are content-addressed, so replay
does not depend on render order: the server rebuilds a fresh registry from the
map, dispatches, re-renders, and returns a new fragment.

The client sends the expression, not its locally-derived id, because the two
sides derive ids in different scopes.

**Inherited wrinkle.** Child components mount during render and own their
callbacks in per-child registries. mobile-web handles this by carrying a merged
`childCallbacks` map and running one extra mounting render before dispatching a
child-owned event. Asgard needs the same, and it needs a test that would fail
without it.

**Callable surface.** Callbacks resolve today via bare `method_exists()`, which
means any public method on the component. When the expression comes from a
server, that is too wide. Only methods marked with an explicit attribute
(`#[NativeAction]`, alongside the existing `#[Computed]`, `#[Locked]`, `#[On]`)
are dispatchable from a fragment.

## 11. Versioning

`protocol` in the handshake is an integer. A server that does not speak the
client's version says so at handshake, not mid-render. Clients support exactly
one version per build; servers may support several.

## 12. Open questions

1. QUERY viability through real infrastructure, and whether a fallback is
   required. §8.
2. Whether a region can be rendered without a bearer token at all, for public
   screens shown before sign-in.
3. Fragment caching. Regions that do not depend on client state could be cached
   and shared, but nothing here defines a cache key yet.
4. What happens when a region's fragment wants to navigate. Navigation is
   screen-level and a region is a subtree.
5. Offline queueing of events fired against a fragment that is no longer
   current.
