# The Asgard Protocol

**Status:** draft v1, unimplemented. Names and numbers here are proposals, not
commitments.

Asgard lets a server render part of a NativePHP app's native UI. A client app
marks a region of a screen as server-rendered, sends its state to a server, and
gets back a description of a native element subtree to paint in place. A
server can also own whole screens inside the app's shell, with their own
chrome and routes (§12).

The point of writing this as a protocol rather than a Laravel package is that
the server half is replaceable. Nothing below requires the server to be PHP.
`nativephp/asgard` is the reference server implementation; `nativephp/midgard`
is the reference client. A Rails or Go server that speaks this document is a
first-class participant.

## 1. Who owns what

The client owns the shell and its own screens. The server may own screens
inside that shell.

It is not Jump. Jump is a dev bridge: the dev machine owns the whole screen,
renders every frame, and ships full view state to a device that renders and
sends events back. State lives on the dev box.

Asgard inverts that. The **client owns the shell**: the app, its navigation
stack, its own screens and their state. It ships locally, renders offline,
and asks a server only for what it has been told to ask about. That is
either a region inside one of its own screens (§3), or a whole server screen
at a route the server listed (§12). A server screen lives inside the shell:
the client opens it, keeps it on its stack, wraps it in chrome the server
described, and never lets it replace a route of the app's own. The server is
a renderer for fragments and screens, not the owner of the app.

It is also not remote code. The server sends a description of elements. It
never sends Blade, PHP, or anything the client compiles or executes. A
server may still write its fragments in Blade (§9.5), but it compiles them
itself. What crosses the wire is the same fragment JSON a tree built in code
would make, and the client cannot tell the two apart.

## 2. Roles

| Term | Meaning |
|---|---|
| **Client** | The mobile app. Runs `nativephp/mobile` for rendering and `nativephp/midgard` for transport, session and crypto. |
| **Server** | Any HTTPS server implementing this document. |
| **Region** | A named, server-rendered part of a client screen. |
| **Client component** | The half of a split component that ships in the app. Owns state, renders the placeholder. |
| **Server component** | The half that lives on the server. Renders the fragment. |
| **Fragment** | The element subtree a server returns for one region. |
| **Server screen** | A whole screen the server owns, opened at a route the server listed (§12). Its body is one region. |
| **Chrome** | A server screen's nav bar, tab bar and other bars, sent next to its fragment (§12.2). |

## 3. Regions

A client view marks a server-rendered region with a Blade directive. This
is the app's own Blade, compiled on the device like the rest of the screen:

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

The app still hears about it, so it can swap a skeleton for a message. A
region request that times out or fails fires `ServerRegionFailed` on the
screen (§3.1), and the view can paint something else in the placeholder:

```blade
@server('stats')
    @if ($statsFailed)
        <native:text>Couldn't load stats.</native:text>
        <native:button @tap="retryStats">Retry</native:button>
    @else
        <x-skeleton-card />
    @endif
@endserver
```

### 3.1 When the client asks

After a frame in which a region painted its placeholder, the component that
rendered it asks the bound `FragmentSource` for a fragment, once per
component instance. Regions belong to the component whose view declares
them, so a region in a child component is requested and delivered as that
child. `NativeComponent::refreshServerRegions()` asks again after the next
frame, with the fragment on screen kept until the new one lands. Fragments
are held per component instance, so two screens with a region of the same
name never share one.

The source is fire and forget. It answers later with
`ServerRegion::deliver($request, $tree)`, or with
`$this->receiveFragment($request, $tree)` from an AsyncTask `->finished()`
callback. An answer to a request that a newer one superseded, or for a
component that has gone, is dropped. The default `NullFragmentSource` asks
nobody.

#### Several regions in one request

When one component instance wants several regions after the same frame, it
sends one request for all of them rather than one each. That happens on the
first frame of a screen with several regions, on `refreshServerRegions()`
with no name, and when several hints or signals for one instance land
before it is drawn. The body names `regions` instead of `region`:

```json
{"protocol":1,"component":"app.native.screens.home","regions":["stats","feed"],
 "state":{…},"platform":"ios"}
```

`regions` holds 1 to 16 distinct names, and a body has `region` or
`regions`, never both. A batch never carries an `action`. The server checks
every name before it renders anything, and answers an unknown one with the
same `404 unknown_component` a single region gets. The answer has one part
per region, each shaped like a single answer's:

```json
{"protocol":1,"fragments":{
 "stats":{"version":"q3V0bG9yZW1pcHN1","fragment":{…}},
 "feed":{"version":"bG9yZW1pcHN1cTNW","fragment":{…}}}}
```

A server screen's part carries `chrome` too. The counters for every
region's token are read once, before any of them renders.

On the device each region in a batch is still its own request, with its own
id, its own timer and its own supersede rule. The source hands the answer to
`ServerRegion::deliverBatch()`, which delivers each part as if it had been
asked for alone. A region the answer left out fails with reason `invalid`. A
batch that fails as a whole goes to `ServerRegion::failedBatch()`, and every
region in it fails and fires its own `ServerRegionFailed`. More than 16
regions go out as several batches.

The answer is compressed only when every region in it allows it (§7.4). A
batch that mixes a region with `compress: false` and others is sent
uncompressed, so the opted-out fragment is never in a compressed body.

#### Collapsing

A refresh is a mark on an instance and a region, not a request. Many marks
for the same instance and region before it is drawn (signals, hints,
`refreshServerRegions()` calls) make one fetch, after the next frame that
draws it. A signal whose version the instance already holds makes no mark at
all (§8.1).

#### Timeouts and failures

Every region request waits at most its region timeout for the answer: 15
seconds by default, from `nativephp.asgard.region_timeout`, or the owning
component's `serverRegionTimeout()`. 0 means no limit. That covers the first
fetch, `refreshServerRegions()`, and a fetch a push stream signal or a
refresh hint started (§8.1, §10.2). A region in a batch has its timer too.
A hard failure reported through `ServerRegion::failed()` counts at
once, with the reasons of §10.2.

A failed region keeps what it had and fires
`Native\Mobile\Events\Asgard\ServerRegionFailed` on the screen it is on: the
owner, or the screen a child component that owns it is on. Its public
properties are `region`, `component` (the address), `reason`, `status` (the
HTTP status, or null), `requestId`, `message` and `placeholder`.
`placeholder` is true when no fragment ever landed, so the placeholder is
still up, and false when an earlier fragment stays on screen. The screen
hears it with `#[On]`, filters included, and draws a frame after:

```php
#[On(ServerRegionFailed::class, when: ['region' => 'stats'])]
public function statsFailed(bool $placeholder): void
{
    $this->statsFailed = $placeholder;
}

public function retryStats(): void
{
    $this->statsFailed = false;
    $this->refreshServerRegions('stats');
}
```

A failed region is not asked for again on its own. Later frames leave it
alone, so a server that is down is not asked on every frame. To try again,
call `refreshServerRegions()` on the component that owns the region, from the
handler or a retry button. The retry gets a timer of its own.

An answer that lands after the failure is dropped. A request that a newer one
superseded fires nothing. A covered screen's timers wait until it is on top
again, as in §10.2. A server screen's own body (§12.1) has its own timer and
`ServerScreenFailed`, and never fires `ServerRegionFailed`. Regions cannot
sit inside a fragment (§9.3), so there are no others to leave out.

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

Instead the server resolves it through a map the developer writes. The
reference server takes it from `config('asgard.components')`
(`address => class`) and from `Asgard::component($address, $class)` calls at
boot. Server screens join the same map when they are registered (§12.1).
Nothing is discovered by scanning, and nothing outside the map resolves. The
convention is that the address is the client component's class in dot
notation, so the two halves of one component share a name, but the map is
what decides. A non-PHP server keeps the same kind of table.

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
authorise every action against the authenticated identity from §7.5, never
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
cheap to reject early. **They are not authentication.** See §7.5.

They deliberately do not reuse cookie semantics. Cookies ride along
automatically; this exchange is explicit, so a client always knows whether it
has a session and a server never issues one it was not asked for.

### 6.1 Handshake

The client holds `asgard_key`: a per-app, per-environment public identifier
compiled into the build. The server holds the same value in its config and
refuses a handshake that names any other. It is not a secret and it is not key
material. It only lets a server turn away clients built for some other app.
The server package generates one (`php artisan asgard:key`), and it is
documented as unique per app and per environment.

The handshake agrees a session secret without sending it. Each side makes an
ephemeral P-256 key pair for this one handshake and they run ECDH, in the
spirit of RFC 8291's Web Push key agreement.

```
→  POST /_asgard/session
   Content-Type: application/json
   Authorization: Bearer <token>              (when the app has one)
   { "key": "<asgard_key>", "protocol": 1, "accepts": ["tree/json"],
     "client_public": "<base64url P-256 point>" }

←  200
   { "session": "<opaque id>", "server_public": "<base64url P-256 point>",
     "expires_in": 1800, "encoding": "tree/json" }
```

- `client_public` and `server_public` are uncompressed points,
  `0x04 || X || Y`, 65 bytes, in base64url without padding. A point that is
  malformed or not on the curve is a 422.
- `session` is an opaque identifier. It goes in a header on every later
  request.
- Both sides compute the ECDH shared secret (the 32-byte X coordinate) and
  derive the session secret from it with HKDF-SHA256
  ([RFC 5869](https://www.rfc-editor.org/rfc/rfc5869)):

  ```
  secret = HKDF-SHA256(salt = empty,
                       IKM  = ECDH shared secret,
                       info = "asgard/1 session" || 0x00 || client_public || server_public,
                       L    = 32)
  ```

  The two public keys go into `info` as their 65 raw bytes. That ties the
  secret to the exact pair of keys this exchange used.
- Neither side keeps its private key once the secret is derived. The server
  stores the secret against the session id. The client stores it with the id
  and the expiry (§6.2).
- A key the server does not know is `403 {"error":"unknown_key"}`, and so is
  any key while the server has none configured. A protocol the server does
  not speak is `422 {"error":"unsupported_protocol","protocols":[1]}`. An
  `accepts` with no encoding the server speaks is
  `422 {"error":"unsupported_encoding"}`.

The handshake itself is neither signed nor encrypted. There is nothing to
sign it with yet, and nothing in it is secret. §7.1 says what that does and
does not leave open.

### 6.2 Use and renewal

Every region request carries `Asgard-Session-Id: <session>`. Each accepted
request slides the expiry forward on the server. The client slides its own
copy when a response verifies.

The client keeps the session where every part of the app that makes requests
can read it. On a device, render calls run on AsyncTask lanes and stream
requests are signed on the UI lane. Those are separate PHP interpreters with
no shared memory, so the session lives in the app's cache store (SQLite by
default), keyed by server URL and `asgard_key`. Only the 32-byte secret is
stored, and the four keys are derived again on read. The client treats a
session as gone 60 seconds before `expires_in` says it lapses, so a request
never races the server's expiry.

### 6.3 Loss

Either side can lose a session: expiry on the client, eviction on the server.
Both resolve to the same move, and it must be invisible to the app developer
and the user.

| Condition | Status | Client behaviour |
|---|---|---|
| Session unknown or expired | `419` | Re-handshake once, retry the request once. |
| Digest, signature or decryption failed, signature expired, nonce reused | `403` | **Do not retry.** Surface an error. |
| Bearer token rejected | `401` | Hand to the app's auth flow. |
| Unknown component | `404` | Keep the placeholder, log. |

The split between 419 and 403 matters. A signature bug that returned 419 would
make every client re-handshake and retry forever. Exactly one re-handshake and
one retry per originating request; a second failure surfaces.

Some failures only the client can see: a response or pushed event whose
signature does not verify, that does not decrypt, that names a coding the
client does not know, or that inflates past the cap in §7.4. The client
treats all of them alike. It keeps the placeholder (or the last fragment it
painted), logs why, and does not retry.

## 7. Security

Asgard does not define its own signing or encryption. It uses published
specs and says which parts of each it needs:

- [RFC 9421](https://www.rfc-editor.org/rfc/rfc9421), HTTP Message
  Signatures, for integrity in both directions.
- [RFC 9530](https://www.rfc-editor.org/rfc/rfc9530), `Content-Digest`, to
  bring the body under the signature.
- [RFC 8188](https://www.rfc-editor.org/rfc/rfc8188), the `aes128gcm`
  content encoding, for the bodies.
- [RFC 5869](https://www.rfc-editor.org/rfc/rfc5869), HKDF, and P-256 ECDH
  for the key agreement in §6.1.

All of them have existing libraries and test vectors, and a reviewer can check
an implementation against the RFC instead of against this document. Both
packages test against the published vectors (RFC 8188 §3.1 and §3.2, RFC 9421
Appendix B.2.5, RFC 9530 Appendix B.1, RFC 5869 Appendix A), and against a
shared set of Asgard vectors that both must reproduce byte for byte.

### 7.1 Threat model

Assume: TLS everywhere, but intermediaries that terminate it and log. Assume
the client binary is readable and `asgard_key` is extractable, because it is
compiled into an app anyone can download. Assume some clients are hostile.

The key agreement in §6.1 is what defeats the logging proxy. From the
handshake the proxy learns `asgard_key`, both public keys and the session id.
None of those, alone or together, gives it the secret: getting the secret from
the public keys is the elliptic-curve Diffie-Hellman problem. A proxy that
logs every byte of every request, the handshake included, can decrypt nothing
and forge nothing.

An active attacker that terminates TLS and rewrites traffic is a different
case. It can run one handshake with the client and another with the server
and relay between them. Asgard does not stop that. The client has no
long-term server key to check the handshake against, and giving it one would
be certificate pinning by another name. Authenticating the server is TLS's
job. Asgard protects against intermediaries that read, not ones that
rewrite.

### 7.2 Keys

Every key comes from the session `secret` agreed in the handshake (§6.1).
Neither side uses `secret` directly. Each derives four keys from it with
HKDF-SHA256 ([RFC 5869](https://www.rfc-editor.org/rfc/rfc5869)), with an
empty salt and these `info` labels:

| Key | `info` | Used for |
|---|---|---|
| `sig_c` | `asgard/1 sig client` | HMAC signing of requests |
| `sig_s` | `asgard/1 sig server` | HMAC signing of responses |
| `enc_c` | `asgard/1 enc client` | IKM for request bodies |
| `enc_s` | `asgard/1 enc server` | IKM for response bodies |

Each key is 32 bytes, except `enc_c` and `enc_s`, which are 16. Separate keys
per direction mean a captured request can never be passed off as a response,
and separate keys per purpose mean a bug in one use cannot leak another.

**`keyid` is the session id.** It is already on every request in
`Asgard-Session-Id` and is not secret, so naming the key with it tells an
observer nothing new. The receiver looks the session up by `keyid`, derives
the key for the direction it is checking, and verifies. An unknown `keyid` is
the 419 in §6.3; a known one that fails is the 403.

The secret itself never crosses the wire.

### 7.3 Signatures (RFC 9421)

Both sides sign with `hmac-sha256` (RFC 9421 §3.3.3) under the label `asgard`,
with `tag="asgard/1"`, so a signature made for Asgard cannot be confused with
any other signature the app's HTTP stack adds.

Bodies are covered through `Content-Digest`
([RFC 9530](https://www.rfc-editor.org/rfc/rfc9530)) using `sha-256`. The
digest is taken over the content as sent, which is the ciphertext from §7.4.
That is what lets the receiver check the signature before it decrypts
anything (§7.6).

**A region request** covers:

```
("@method" "@authority" "@path" "content-type" "content-encoding"
 "content-digest" "asgard-protocol" "asgard-session-id")
;created=…;expires=…;keyid="<session>";alg="hmac-sha256";tag="asgard/1"
```

`@method` is the method on the wire. `@authority` is the host in lowercase,
with the port when it is not the scheme's default. `@path` is the path as
sent, without the query.

Two more headers are covered only when the request carries them, after the
rest and in this order:

- `"x-asgard-method"`, when the client falls back to POST plus
  `X-Asgard-Method: QUERY` (§8), so the verb it meant is signed too.
- `"authorization"`, when the app sends a bearer token. Covering it binds the
  token to the session: a token cannot be lifted from one signed request and
  replayed under another session's signature. RFC 9421 cannot cover a
  component the message does not have, so a request without a token does not
  list it.

The receiver works the list out from the headers it actually received and
refuses a signature whose list differs in any way. Stripping the
`Authorization` header, or adding one, therefore breaks the signature.

The parameters are exactly `created`, `expires`, `keyid`, `alg` and `tag`,
plus `nonce` where the next paragraph says. A receiver refuses any other
parameter, a missing one, an `alg` other than `hmac-sha256`, a `tag` other
than `asgard/1`, and a `keyid` that is not the `Asgard-Session-Id`.

A server action (a request with an `action` member, §10.2) is not a safe
request, and neither is a `#[Server]` call (§10.5), so its signature also
carries a `nonce` parameter: 16 to 64 base64url characters, which the client
fills with 16 random bytes. The server remembers nonces per session until
their `expires`, plus the clock skew allowance, and refuses a repeat with 403.
An action or call whose signature has no nonce is
`403 {"error":"nonce_required"}`. Region renders and stream requests
are reads and skip the nonce.

**A response** covers:

```
("@status" "content-type" "content-encoding" "asgard-content-coding"
 "content-digest" "asgard-protocol" "signature";req;key="asgard")
;created=…;keyid="<session>";alg="hmac-sha256";tag="asgard/1"
```

`"signature";req;key="asgard"` pulls the request's own signature into the
response's (RFC 9421 §2.4). A response therefore only verifies against the
request it answers, and a fragment captured from one request cannot be
replayed as the answer to another.

Every answer to a request that passed the checks in §7.6 is sealed this way,
whatever its status. A 404 or a 422 is signed and encrypted like a 200. The
early rejections (405, 419 and the 403 for a failed check) are plain,
unsigned JSON: there is either no session to sign with or no reason to trust
the one claimed. A client acts on the status of an unsigned answer only to
re-handshake on 419 and to stop on 403, and reads nothing else from it.

Clock skew: `created` may be up to 60 seconds in the future, and `expires`
is at most 300 seconds after `created`. A request past its `expires` is
refused, so a captured request can be replayed for at most five minutes, and
an action not even once. A response has no `expires`. The client refuses one
whose `created` is more than 60 seconds ahead of its clock or more than 300
seconds behind it.

What a valid signature proves: the message was produced by something holding
a live session secret and arrived unmodified. It does not prove the sender is
a genuine copy of the app, and it cannot. `asgard_key` is public by
construction, so anyone can complete a handshake. The signature is an
integrity check and a cheap early filter, not authentication.

### 7.4 Encryption (RFC 8188)

Request and response bodies use `Content-Encoding: aes128gcm`. The input key
material is `enc_c` for requests and `enc_s` for responses. Every message gets
a fresh random 16-byte salt, so the content-encryption key and nonce RFC 8188
derives from them never repeat. The `keyid` in the RFC 8188 header is the
session id, the same as in the signature, and the receiver refuses a body
whose `keyid` is any other. The record size is the default 4096. A body
longer than one record is split across several, and a receiver refuses one
that is truncated: the last record must carry the `0x02` delimiter.

A client must not let its HTTP library decode the body for it. curl, for one,
fails outright on a content coding it does not know.

Inside TLS this is redundant against a network attacker. It is not redundant
against a logging proxy, which is the case it exists for.

This is why the session id and the secret are different things. If the
session identifier in the header were also the key material, any intermediary
that logs headers and bodies could decrypt everything it logged, and the
encryption would protect against nothing it was added for. The identifier
travels on every request. The secret never travels at all.

Fragments in `tree/json` are encrypted before signing, and the `Content-Type`
stays `application/json`. The encoding is a property of the transfer, not of
the fragment format, so §9 is unchanged.

**Compression.** Nothing between the server and the client can compress an
encrypted body, so the server compresses fragments itself, before encrypting
them. A 200-row list fragment of about 52 KB went over the wire as 2.4 KB.

- Only responses and pushed events are compressed. Requests never are: they
  are small, and they carry client state. A server refuses a request with any
  coding other than `aes128gcm`.
- `Content-Encoding` stays `aes128gcm`. Every sealed response also carries
  `Asgard-Content-Coding`, which is `gzip` when the plaintext was gzipped
  before encryption and `identity` when it was not. The header is a covered
  component of every response (§7.3), so it cannot be stripped or flipped.
  The receiver undoes the steps in reverse: it decrypts, then gunzips when
  the header says `gzip`. Any other value, or no header, is refused.
- Why not the stacked `Content-Encoding: gzip, aes128gcm` that RFC 9110 §8.4
  allows: Cloudflare rewrites that header to `aes128gcm` and passes the body
  through unchanged, so the signature fails and the response is lost. A
  header of Asgard's own is left alone.
- Only a region's fragment is compressed, and only when it is at least 1024
  bytes. Below that the saving is too small to matter. An action's answer is
  the region's fragment, so it follows the region. Errors are never
  compressed: they are small and may echo what the client sent.
- The gzip member has a fixed header (no file name, mtime 0, OS 255) and is
  deflated at level 6, so the same input gives the same bytes on any server.
- A client inflates in small steps and gives up as soon as the output passes
  **8 MiB** (8,388,608 bytes). Going over is a failure like a bad signature
  (§6.3). No real fragment comes near it, and it stops a small body from
  expanding into one that exhausts the device's memory.

**When to turn it off.** Compressing a body that holds both a secret and text
someone else can choose leaks the secret through the body's length. That is
the BREACH attack, and encryption does not stop it, because encryption hides
the bytes and not how many there are. An attacker who can put guesses into a
fragment (a search term echoed back, a display name they set) and see the
size of the answer can recover the secret a character at a time. So a region
whose fragment shows a secret, such as a token, a recovery code or a full
account number, next to anything another person controls, turns compression
off: `#[Region('account', compress: false)]` on the server. Its responses and
events then go out as plain `aes128gcm`. A region that shows only the user's
own data, or only public data, has nothing to leak and keeps it on.

### 7.5 Authentication is separate

**A client never tells the server who the user is.** Identity comes from an
ordinary `Authorization: Bearer` credential the server verifies independently:
its own session, an OAuth access token, a personal access token. The Asgard
session correlates state; the bearer token establishes identity. A request with
a valid signature and an invalid token is rejected.

### 7.6 Rejecting early

Order of checks, cheapest and most decisive first: session id known →
`Content-Digest` matches the body → signature valid and fresh → nonce not seen
before (actions) → decrypt → bearer token → component resolvable → hydrate.
A request that fails any of the first five is discarded before anything parses
its body. On the server the first five run ahead of the app's own middleware,
so bearer auth only ever sees a request already known to be intact.

A client checks a response in the same order and keeps the placeholder on any
failure. It never renders a fragment whose signature did not verify, and the
same holds for pushed events (§8.1).

## 8. Transport

Region requests prefer **HTTP QUERY** over HTTPS: a safe method that carries a
body. A body is required (state can be large and structured) and a GET query
string is the wrong place for it: length-limited, and logged by everything in
the path.

**QUERY cannot be the only transport.** OWASP Core Rule Set ships
`tx.allowed_methods=GET HEAD POST OPTIONS` as its default
(`REQUEST-901-INITIALIZATION.conf`), and rule 911100 blocks everything else at
paranoia level 1, which is on by default. CRS rides inside Cloudflare's managed
rules, Azure Front Door and App Gateway DRS, and Google Cloud Armor. That makes
the failure follow any customer who switches on a managed WAF, on any platform,
rather than being confined to particular vendors. Akamai is worse: `allowOptions`
permits GET, HEAD and OPTIONS and 501s the rest, with per-verb allow behaviours
and no `allowQuery` among them, so an Akamai customer has no configuration path
to enable it at all.

So the handshake negotiates transport, and the client latches the answer per
installation:

1. **QUERY** where it works.
2. **POST plus `X-Asgard-Method: QUERY`** where it does not.

The override header must be package-owned. All three conventional names
(`X-HTTP-Method-Override`, `X-HTTP-Method`, `X-Method-Override`) are in CRS's
default `tx.restricted_headers_basic` and are blocked at PL1, and CloudFront
strips the first independently. The `_method` parameter is blocked at PL2+ too.

Two more rules fall out of the same research:

- **Always send `Content-Type`.** RFC 10008 requires it on QUERY, and Envoy
  1.40 will reject a QUERY without one on every protocol. Traffic that works
  today would break on an upgrade.
- **Uppercase the verb** on both clients.

On the server side, Laravel 14 has `Route::query()` and treats QUERY as
CSRF-exempt. On Laravel 13 and earlier the route is `Route::match(['QUERY'], …)`
and CSRF exemption is the package's job.

One endpoint handles all region requests, in the shape of Livewire's `/update`
and mobile-web's `EdgeEndpoint`. The path is derived per installation from the
app key rather than being a well-known constant, so scanners cannot target it
without already having a client:

```
/_asgard-<8 hex>/render
```

### 8.1 The push stream

A client holds one Server-Sent Events stream open per device, so the server
can say a region changed without being asked. The stream carries a
**subscription** for each component instance the device has on screen, and
nothing else.

By default the stream renders nothing. When a region changes it sends a
**signal**: the region and its new version. The client fetches the region
through a normal region request, with its own credentials. A region the
server marks **shared** renders the same for everyone with the same state,
and for those the stream sends the fragment inline instead. Shared is
opt-in, so a region left unmarked costs a round trip, never a leak.

**Cost.** An open stream holds one server worker (a PHP-FPM child, say) for
its whole lifetime. That is why a device opens one stream, not one per
component, and why it drops what is no longer on screen. A device with no
server regions on screen holds no stream at all.

**Opening it.** `{path}/stream`, in the same two forms as render. The body
lists every subscription the device wants:

```json
{"protocol":1,"platform":"ios","subscriptions":[
 {"id":"3f9a0c1d2e4b5a6c","component":"promos.show","regions":["screen"],
  "state":{"id":"1"},"versions":{"screen":"q3V0bG9yZW1pcHN1"}},
 {"id":"8b2d77e01c9f4a35","component":"promos.show","regions":["screen"],
  "state":{"id":"2"}}]}
```

- `subscriptions` holds 1 to 16 subscriptions.
- `id` is the client's name for a subscription: 1 to 32 characters of
  `A-Z a-z 0-9 _ -`, unique within the request. The server only echoes it.
- `component`, `state` and each name in `regions` follow the rules of a
  region request (§4, §5). `regions` holds 1 to 16 distinct names.
- `versions` is optional. It maps a region to the version token the client
  holds for it (below).
- There is no `action`, at the top level or in a subscription, and a
  subscription has no other members.

A subscription is one component instance's view of the server: one address
and one state. Two instances with the same address and the same state share a
subscription. Two with different state (`/promos/1` under `/promos/2`) are
two subscriptions, and each gets the pushes rendered for its own state.

The server checks every subscription before it streams anything, and a
request it refuses gets the answer render would give, sealed the same way:
a 422 for a bad body, a 404 `unknown_component` for an unknown address or
region in any subscription, a 500 `invalid_fragment` for a fragment it cannot
send. It works out each subscription's channels from that subscription's
state (§12, `channels()`), and reads every counter of every subscription in
one read per poll.

The request is sealed exactly like a region request (§7.3, §7.4). It is a
read, so it has no nonce.

**The device declares the whole set.** The client works the set out from what
is on screen: for each live component instance, each region that holds a
fragment from a good answer, grouped by address and by the state the answer
was rendered for. Only answered regions are watched, so a session already
exists when the stream opens. Whenever the set changes (a region gets its
first answer, a state changes, an instance unmounts) the client opens the
stream again with the new set, signed afresh. An empty set closes it. A
change that leaves the set as it was does nothing, so subscription ids are
derived from the address and the canonical state, and an unchanged set is
recognisably unchanged. Changes that land together, such as a navigation
that unmounts one screen and mounts another, make one reopen.

**Version tokens.** Reopening is frequent, so the server does not send every
region again each time. Every region answer carries a `version`: an opaque
token of up to 64 characters of `A-Z a-z 0-9 _ -`, derived from the push
counters that region watches for that address, state and set of channels.

```json
{"protocol":1,"region":"screen","version":"q3V0bG9yZW1pcHN1","fragment":{…}}
```

The client keeps the latest token per instance and region, from render
answers and from fragment events, and sends it in `versions`. On connect the
server sends an event only for regions whose current token differs from the
client's, or that have none. The server reads the counters before it renders
a region answer, so a push that lands between that answer and the stream
opening leaves the client holding a stale token, and the stream sends the
region again. Nothing is lost. Instances that share a subscription but hold
different tokens for a region send none for it.

**Shared regions.** A server renders a shared region once per push for each
distinct address, region, platform, canonical state and version, and every
stream that needs that answer reuses it (from a short-lived cache, say),
sealing it for its own session. Because that cache is not keyed by user, a
server renders a shared region on the stream with no authenticated user. A
shared region therefore must not depend on the user or on secrets, which
also makes compressing it safe (§7.4). The region request itself is not
affected: a shared region renders there as any other, with the caller's
credentials.

**The answer** is `200 text/event-stream`, and its headers are not signed. The
device's native code holds the connection and PHP never sees those headers, so
a signature on them would protect nothing. Each event carries its own instead.

**Events.** There are two, `signal` and `fragment`, sealed the same way:

```
id: 7
event: signal
data: {"body":"<base64url>","enc":"identity","sig":"<base64url>"}

id: 7
event: fragment
data: {"body":"<base64url>","enc":"gzip","sig":"<base64url>"}
```

The three members are always present, in this order, and there are no others.

A `signal`'s message is `{"protocol":1,"subscription":…,"region":…,"version":…}`
and is never compressed. A `fragment` event is sent only for a shared region,
and its message adds the fragment, with `chrome` too for a server screen
(§12.2): `{"protocol":1,"subscription":…,"region":…,"version":…,"fragment":…}`.

`body` is the message, compressed when `enc` says so, encrypted as `aes128gcm` under `enc_s` with
the session id as `keyid`, a fresh salt per event and record size 4096, then
encoded as base64url without padding.

`enc` is `gzip` when the message was gzipped before encryption and
`identity` when it was not. The rules are those for responses in §7.4: the
1024-byte minimum, the region's opt-out and the 8 MiB cap.

`sig` is HMAC-SHA256 under `sig_s`, in base64url without padding, of this
string:

```
asgard/1 event
<the stream request's signature>
<id>
<event>
<enc>
<body>
```

The six lines are joined by a single LF (0x0A), with no LF after `body`. The
stream request's signature is the 32 raw bytes from its `Signature` header,
in base64 with padding (RFC 4648 §4), as that header carries it. `id` and
`event` are the SSE fields as sent. `body` is the base64url text exactly as it
appears in `data`.

Covering the request's signature binds every event to the one request that
opened its stream, the way `"signature";req` binds a response (§7.3). An event
replayed into another session, another stream, or the same stream after it
reconnects carries the wrong request signature and fails. Covering `id` and
`event` stops an event being renumbered or renamed. Covering `enc` stops the
marker being stripped or flipped, which would otherwise make a client gunzip
a body that was never compressed, or paint a compressed one as it stands. The fixed first line means
the string can never be read as an RFC 9421 signature base.

The client verifies `sig`, then decrypts, then gunzips when `enc` is `gzip`,
then checks that `subscription` is one it opened this stream with and that
`region` is one of that subscription's. An event that fails any of those
checks is logged and dropped, never acted on. Then it acts only on instances
of that address that asked with that subscription's state:

- On a `signal`, each instance whose token for the region differs from the
  signal's is marked to fetch the region again, exactly as a refresh would
  (§3.1). It fetches after the next frame that draws it, so an instance on a
  covered screen fetches when that screen is drawn again, and several
  signals before then make one fetch. The fragment on screen stays until the
  answer lands, and a server screen's chrome comes with it. An instance that
  already holds that version ignores it. A refresh hint (§10.2) is handled
  the same way, without a version.
- On a `fragment`, it paints the fragment and keeps its `version`. `retry:` lines and `: ping` comments are not covered. They
carry nothing a client paints. Within one live connection, ordering and
duplication are TLS's job, as for any response.

**Reconnecting.** A stream request's signature expires 300 seconds after its
`created`, like any other. So a client never reconnects by replaying the
request it opened with: once the signature has expired the server refuses
it, and before then the server would be accepting a replay. Every connection
is signed afresh.

1. When a connection drops for a reason worth retrying (a network error, a
   5xx, or the server ending the stream), the native side waits out its
   backoff: the server's `retry`, doubled for each failure in a row, at most
   30 seconds. Then it tells PHP the stream should be reopened. It does not
   reconnect by itself.
2. PHP signs a new stream request with the current session and opens the
   stream again. The native side carries `Last-Event-ID` and the backoff over
   to the new connection.
3. A 419 gets one new handshake, off the UI lane, and one more try. That
   allowance resets once a connection succeeds.
4. A 403, a 204 or any other 4xx ends the stream. The regions keep their last
   fragments, and the next change to the set opens it again.

A reconnect sends the tokens the client holds, so it only hears about what
it missed. A token that is behind only costs an extra event. `Last-Event-ID` is not signed, and a server ignores it: the
tokens decide what is sent on connect.

The server sends a `: ping` comment every 5 seconds. A server only learns
that a client went away when a write fails, so the ping is also how soon a
dead connection gives its worker back.

The signature is checked once, when the stream opens. How long a server then
keeps the stream open (`asgard.stream.lifetime`, 300 seconds by default) has
nothing to do with the signature window.

*Implementer note.* A signal carries nothing rendered, so the stream does not
have to be held by the application that renders. Something else could hold
it later, as long as it can seal events: it would need the session keys, or a
ticket the application issues for them.

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

### 9.1 Node shape

```jsonc
{
  "type": "column",           // required, an element type name
  "key": "total",             // optional, stable identity among siblings
  "props": { "text": "42" },  // optional, see 9.4
  "class": "p-4 gap-2",       // optional, Tailwind, resolved client-side
  "on_press": {"call": "select", "args": ["a"]},  // optional callback slots, see 10.1
  "children": [ /* nodes */ ]
}
```

### 9.2 The client owns its identifiers, in two separate namespaces

A server returns **no node ids and no callback ids**. These are not one
mechanism and getting only the first right leaves fragment callbacks colliding
with the screen's own.

- **Node ids** are a hash of a **key path**. The region node is keyed with its
  region name, so a fragment key `total` lands at `/sales-summary/total`. The
  existing parent-to-child path derivation does this on its own; a fragment
  needs no id arithmetic.
- **Callback ids** are a hash of a **registry scope** plus an expression
  string. A fragment's callbacks register into their own scope
  (`server:<region>`), pinned on the region node so the whole subtree inherits
  it.

A server sends relative keys and callback **slots** (§10.1), never ids. The
client turns each slot into an expression and registers it. The server never
needs to know how either id is computed.

Placeholder callbacks stay in the screen's own scope. The placeholder is the
client's markup, and a server-scoped id on it would silently fail to dispatch.

### 9.3 What a fragment may not contain

A fragment describes a subtree inside a region. It is not a screen.

- **No screen chrome.** `top_bar`, `bottom_nav`, `bottom_bar`, `fab` and the
  root stack/tabs elements are rejected. A region is a subtree; chrome belongs
  to the screen that owns it. A server screen describes its chrome too, but
  as its own checked part of the answer (§12.2), never inside a fragment.
- **No nested `server_region`.** Regions are declared by the client, in client
  markup. A server cannot create one.
- **No compiler-internal prop keys.** `props` is a free-form bag authored by a
  server, and the collector's attribute vocabulary includes spellings that are
  not presentational: `_press` selects which method a press dispatches,
  `_navigate` moves the app, `native-key` sets identity. A server writing
  `_press` into `props` would choose which **client** method runs and walk
  straight around the `#[NativeAction]` rule in §10.

  Keys beginning `_`, `native-` or `native:` are stripped from `props` before
  it reaches the collector. This is the fragment-side equivalent of the
  hydration allowlist in §5.1, and for the same reason: the payload is
  genuinely server-authored and the client must not let it reach into
  machinery that isn't presentational.
- **No local media.** A prop that loads a URL (`src` on any element,
  `leadingAvatar` and `leadingImage` on `list_item`) must be an absolute
  `https:` URL. See §9.6.
- **No php-mode web view.** A `webview` with `php` set loads the app's own
  routes. That is the app's to open, not a server's.

### 9.4 Fragments the client cannot render

A fragment may name an element type this build does not have: a plugin the app
never installed, or a type from a newer client. The natural implementation
throws mid-render and takes the whole screen down, which is the opposite of
what §3 promises.

A fragment is therefore **validated before it is stored**, not during render.
One that fails validation is rejected whole and the region keeps its
placeholder, exactly as if the request had failed.

### 9.5 Server Blade

A server may author a fragment in the same Blade dialect an app uses for its
native screens. This is a server convenience. The wire format does not
change: the server compiles and renders the view itself, turns it into a
§9.1 tree, and validates it under §9.3 exactly as it would a tree built in
code. Blade never crosses the wire, and a client cannot tell the two apart.

The reference server accepts a view anywhere it accepts a tree: a region
method's return value, a server screen's body (§12.1), and the chrome
content slots `bottom_bar` and `tab_accessory` (§12.2).

**Tags.** `<native:x-y>` always becomes a node of type `x_y`. The bare
`<x-y>` does too when `x_y` is a known type: the server keeps a list of
core and first-party UI element types, plus any it adds. The list includes
chrome types such as `top_bar`, so a bare `<top-bar>` compiles to a node
and is then refused by §9.3, rather than vanishing as markup. An unknown
hyphenated bare tag is a compile error in debug and is reported and left
out otherwise. Other markup is dropped. Several root tags make a list
fragment.

**Attributes.**

| Blade | Fragment |
| --- | --- |
| `class="..."`, `:class="[...]"` | `class`, passed through as written (Tailwind is resolved on the device) |
| `key="..."`, `:key="$id"`, `native:key="..."` | `key` |
| `@press="m(args)"` | `on_press: {call: "m", args: [...]}` (server) |
| `@press.client="m(args)"` | `on_press: {call: "m", args: [...], target: "client"}` |
| `@navigate="/path/{{ $id }}"` | `on_press: {navigate: "/path/7"}` |
| `@navigate.replace.fade="/path"` | `on_press: {navigate: "/path", replace: true, transition: "fade"}` |
| `@navigate.back`, `@navigate.back.fade` | `on_press: {back: true}`, with `transition` when given |
| any other `name="..."` / `:name="expr"` | `props.name`, under the name as written |

`<text>` and `<button>` slots become `props.text` and `props.label`, with
entities decoded and whitespace collapsed the way the device collapses it.
A `<text>` holding nested `<text>` runs becomes a `text` node with `text`
children.

**The `.client` rule.** In server Blade an event names a server `#[Action]`
by default, because the server owns the fragment (§10). `.client` is the
only way to target the device, and it maps to `"target": "client"`, so the
device still applies every check in §10.3. The same rule covers `@press`,
`@longPress`, `@doubleTap`, `@change` and `@submit` (and the `@tap` /
`@longTap` spellings). Any other event has no slot and is a compile error,
not a silently dropped handler.

**Args.** Call arguments are parsed when the view compiles, not at render
time. Each must be a literal (quoted string without interpolation, number,
`true`, `false`, `null`, or a `[...]` array of those) or one whole
`{{ $expr }}`, which is evaluated and sent as a JSON value. `{{ }}` inside a
quoted string is refused, so data can never add or reshape arguments. The
result is then checked against §10.1 like any other callback.

**`@navigate`.** It fills the `on_press` slot with a navigation (§12.4).

- The value is a path, not a PHP expression. `{{ }}` is interpolated into
  it as text, so the result is always a string, and the server checks it is
  an app path when the node is built and again under §12.4.
- Modifiers are the device's own: `replace`, `back`, and one transition in
  camelCase (`fade`, `slideFromRight`, `slideFromLeft`, `slideFromBottom`,
  `fadeFromBottom`, `scaleFromCenter`, `parallaxPush`, `morph`, `none`),
  which maps to the snake_case value in §12.4.
- `.back` takes no path and cannot be combined with `.replace`.
- Compile errors: `.exitToWeb`, since §12.4 has no way to leave the app; any
  other modifier; more than one transition; a value that does not start
  with `/` or `{{`; and the device's `@navigate('/x', [...])` form, whose
  data the slot has no room for.
- `@navigate` and `@press` (or `@tap`, or `.client` forms) on one element
  are a compile error. On the device `@navigate` wins and the press is
  ignored without a word. In a fragment a press does one thing, so the
  server refuses rather than drop a handler. Long press and double tap are
  separate slots and combine freely with `@navigate`.

**Not supported.** `native:model`, `@model` and `native:poll` are compile
errors. A fragment has no client props to sync and re-renders by push.

**Isolation.** A server must compile region views apart from its web views
(separate compiler and cache), so bare tags like `<button>` in ordinary web
pages are never rewritten.

### 9.6 Media URLs

In an app's own view, `src="img/x.png"` is an asset the app shipped in
`public/`, and `src="/var/..."` is a file on the device. From a server
neither means what its author meant, and a server should not be able to
point at the app's files at all.

So in a fragment, and in chrome (`nav_bar.logo.src`, an action's `image`),
every media URL is absolute:

- The client accepts `https:` URLs with a host, or an empty string for no
  image. With `app.debug` on it also accepts `http:`, for a server on a
  developer's machine. Anything else (relative paths, `/` paths, `//host`,
  `file:`, `content:`, `data:`, `javascript:`) fails §9.4 and the whole
  fragment is rejected. The check is on the prop name: `src` on any type,
  so a plugin element that loads one is covered, plus `leadingAvatar` and
  `leadingImage`.
- The server turns what its author wrote into that form before it sends
  it. The reference server resolves `img/x.png`, `./img/x.png` and
  `/img/x.png` against its own URL (`asset()`, or `asgard.media_url`),
  gives `//host/x` the request's scheme, passes `http:` and `https:`
  through, and refuses every other scheme. It refuses `data:` too: support
  for it differs between platforms, and a fragment is re-sent on every
  push, so an inline image costs every redraw instead of being cached once.

App-authored views are not affected. They resolve `src` as they always have.

**Media is public.** The device loads these URLs itself, with no Asgard
session and none of the user's auth. Anything private needs a URL that
carries its own permission: a signed route
(`URL::temporarySignedRoute()`) or a storage disk's `temporaryUrl()`.

## 10. Events

A tap inside a fragment runs on the server unless the fragment says otherwise.
The server owns the fragment, so by default it owns what the fragment's
buttons do.

### 10.1 Callback slots

The slots are `on_press`, `on_long_press`, `on_double_tap`, `on_change` and
`on_submit`. Each holds an object, never a string:

```json
"on_press": {"call": "shuffle", "args": [3]}
"on_press": {"call": "celebrate", "args": [], "target": "client"}
```

- `call` is a method name: `^[A-Za-z_][A-Za-z0-9_]{0,99}$`.
- `args` is a list of at most 16 JSON values (null, booleans, numbers,
  strings, and lists or objects of those), nested at most 4 deep.
- `target` is absent, `"server"` or `"client"`. No other keys are allowed.

A slot may instead move the app (§12.4):

```json
"on_press": {"navigate": "/promos/42", "replace": true, "transition": "fade"}
"on_press": {"back": true}
```

A server always sends `args`, even when empty. A client treats a missing
`args` as empty. For `on_change` and `on_submit`, the control's value is
appended to `args` on either target, the way client callbacks already work.

The object is structured on purpose. Neither side parses a call expression out
of a string, so there is no expression language to get wrong.

A slot of any other shape is dropped and the node renders without that
handler. A client in debug mode throws instead, so the mistake shows up while
the server is being written.

### 10.2 Server actions (the default)

On a tap, the client sends the usual region request (§5.1) plus an `action`
member, to the same endpoint:

```json
{"protocol": 1, "component": "app.native.screens.home", "region": "stats",
 "state": {...}, "platform": "ios",
 "action": {"call": "shuffle", "args": [3]}}
```

The server hydrates the component as for a render, runs the action, renders
the region and answers with an ordinary fragment response. The client applies
it the same way it applies a render.

A server runs `call` only when all of these hold. Otherwise it answers
`404 unknown_action`.

- The method is public, not static, not magic, and declared on the app's own
  component class rather than on the package's base class.
- It carries `#[Action]`. A public method without it is not callable, which
  is the same rule `#[Region]` applies to rendering.
- It is not a `#[Region]` method.
- Its parameters are all scalar or array typed and taken by value. Arguments
  are passed positionally. There is no container injection and no implicit
  model or enum binding, so a client cannot make the server load a record by
  sending its id where a model was expected.

An argument the method's types refuse is a `422`, not a `500`. Everything in
`args` is untrusted input in the same sense as client state (§5.1): an action
authorises against the bearer identity (§7.5), never against what it was sent.

An action is stateless. Changes it makes to public properties last for that
request only and are not sent back as client state. A server that needs to
remember something across taps stores it itself.

#### Redrawing other regions

An action, or a `#[Server]` method (§10.5), can say which other regions need
drawing again, with `$this->refresh()`. There are three cases.

**Same component, inline.** `$this->refresh('stats')`, with one or more
region names of the same component. The server already has the component
hydrated with the request's state, so after the method runs it renders
those regions too, as the request's user, and puts them in the same answer.
An action's own region is always answered as before, and is left out of
`fragments` if the action names it as well.

```json
{"protocol":1,"region":"greeting","version":"…","fragment":{…},
 "fragments":{"stats":{"version":"q3V0bG9yZW1pcHN1","fragment":{…}}}}
```

Each part is shaped like a batch part (§3.1). Its token is read after the
method runs and before anything renders. The device paints a part only on
the instance that sent the request, and only when that instance drew the
region. It skips a region with a request that went out after this one,
because that answer is newer. A painted part supersedes any older request
for its region still in flight. Each part is checked as a region render is
(§9.4), and one that is refused fires nothing. A name that is not a region
of the component throws on the server, which answers `500`.

**Other components on this phone, a hint.** `$this->refresh(InboxScreen::class,
'unread')`, with a class or an address and at most one region. Leaving the
region out means all of them. The server does not have that component's
state, so it does not render it. It only names it, after checking the address
and region exist as `Asgard::push()` does:

```json
{"protocol":1,"region":"greeting","fragment":{…},
 "refresh":[{"component":"app.native.screens.inbox","region":"unread"}]}
```

A class registered under several addresses gives one hint per address. A
hint for a whole component covers hints for its regions, and an answer
carries at most 16 hints and 16 inline regions.

On the device a hint is handled like a stream signal (§8.1): every live
instance at that address that drew the region is marked, and fetches it with
its own current state after its next frame. An instance on a covered screen
fetches when that screen is drawn again. A hint for a region nobody drew, or
for a screen that has closed, does nothing.

**Other phones.** `Asgard::push()` and `Asgard::to()` (§8.1), as before.
`refresh()` only reaches the phone that sent the request.

`fragments` and `refresh` are only present when the method asked for them.
An answer that carries inline regions is compressed only when its own region
and all of them allow it (§7.4).

#### Timeouts and failures

The client waits at most its action timeout for the answer: 15 seconds by
default, from `nativephp.asgard.action_timeout`, or the sending component's
`serverActionTimeout()`. 0 means no limit. The same applies to a chrome
action (§12.2) and to a `#[Server]` method call. The transport's own HTTP
timeout is set longer, so this is the one that fires and a slow server reads
as `timeout` rather than `network`.

An action fails when the timeout passes, or at once on a hard failure. The
transport reports one with `ServerRegion::failed($request, $reason, $status)`:

| reason    | when                                                                       |
|-----------|----------------------------------------------------------------------------|
| `timeout` | no answer in time                                                          |
| `network` | the request never reached the server, or nothing came back                |
| `server`  | the server answered with an error, including a 419 after its one retry    |
| `invalid` | the answer failed verification, or its fragment cannot be rendered (§9.4) |

A failed action fires `Native\Mobile\Events\Asgard\ServerActionFailed` on
the screen it was sent from: the owner, or the screen a child component that
sent it is on. Its public properties are `action` (the call), `component`
(the address), `region`, `reason`, `status` (the HTTP status, or null),
`requestId` and `message`. The screen hears it with `#[On]`, filters included,
and draws a frame after; listeners anywhere in the app hear it with
`Event::listen`:

```php
#[On(ServerActionFailed::class, when: ['action' => 'like'])]
public function likeFailed(string $reason): void {}
```

The region keeps what it had. An answer that lands after the failure is
dropped. A failure for an action that a newer request for the same region
has superseded fires nothing, since its answer would have been dropped
anyway. Timers are acted on by the runloop of the screen the component is
on, which wakes for the deadline with no input needed. A covered screen's
timers wait until it is on top again.

### 10.3 Client actions

`"target": "client"` asks the device to run a method of its own. This is how a
server-drawn button opens a sheet, plays a sound, or changes something on the
screen around the region without a round trip.

The server names the method, but the app decides whether it may. The device
runs `call` only when:

- the method is on the component that owns the region;
- it carries `#[NativeAction]`;
- it passes the same guard as any other UI interaction: public, not static,
  not a lifecycle hook, not declared on `NativeComponent`;
- its parameters are scalar or array typed and taken by value, with no
  injection or binding, as in §10.2.

If any of these fail, nothing runs and the device logs it. It **never** falls
back to sending the call to the server. A misspelt client action that quietly
became a server call would change where code runs without anyone noticing.

Framework callbacks are out of reach. A fragment cannot name `navigate`,
`emit`, `__syncProperty` or any other `NativeComponent` method, and §9.3
already strips the attribute spellings that would bind them. The one way a
fragment moves the app is the navigation slot in §12.4, which the device
checks against its own routes.

**What this leaves a hostile server.** It can only trigger what the app chose
to expose with `#[NativeAction]`, and it could already draw a button the user
would tap. It does choose the label. A button reading "Cancel" can call
whatever `#[NativeAction]` method the server likes. Keep destructive methods
off `#[NativeAction]`, or have them confirm with the user themselves.

### 10.4 Scopes

Fragment callbacks register in the region's own registry scope
(`server:<region>`, §9.2). The scope stores the target with the call, so a
server callback can never be dispatched as a client one, and a fragment cannot
reach the screen's callbacks or another region's.

Callback ids are short hashes, so a hostile server could search for an
expression whose id matches one of the screen's own. A client therefore drops
any id claimed by more than one scope, and logs it, rather than run either
handler. The worst a server can do this way is disable one button.

A fragment's callbacks go when the fragment is replaced or cleared. A tap that
arrives for a fragment that is no longer current does nothing.

**Inherited wrinkle.** Child components mount during render and own their
callbacks in per-child registries. A region inside a child component belongs
to that child, and its client actions run on the child. Its scope is the
child's scope followed by `server:<region>`, so two sibling cards showing the
same fragment never share callback ids.

### 10.5 `#[Server]` method calls

A `#[Server]` method (ASGARD-SERVER-METHODS.md) is a method call rather than
a tap in a fragment. It goes to its own endpoint, `{prefix}/call`, in the same
two forms as render (§8), signed and encrypted like everything else and with a
nonce (§7.3):

```json
{"protocol": 1, "component": "app.native.screens.home", "method": "roll",
 "args": [], "state": {...}, "platform": "ios"}
```

Its own endpoint rather than render with another member, because nothing
about it is a region: there is no region to name, and the answer is a
different shape. Folding it into render would have made every
render check carry a "unless this is a call" branch.

The server hydrates the component as for a render, runs the method of that
name, and answers with only the public props whose `serialize()` value
differs from what hydration left (a prop the method filled in counts):

```json
{"protocol": 1, "delta": {"roll": 4}}
```

`delta` is always an object. Each value must be a plain JSON value, or the
answer is `500 invalid_delta`.

A method that called `$this->refresh()` (§10.2) also gets `fragments`, the
regions of this component it named, rendered after the delta is worked out,
and `refresh`, the hints for other components:

```json
{"protocol": 1, "delta": {"roll": 4},
 "fragments": {"stats": {"version": "q3V0bG9yZW1pcHN1", "fragment": {…}}}}
```

The device applies the delta first, then paints the regions on the calling
instance under the rules of §10.2, then handles the hints, then runs the
closure. A call answer is never compressed, fragments or not, since the
delta can mix the server's values with text the device chose (§7.4). A `#[Locked]` prop the method changed is in it:
locking stops the client writing a prop, not the server. The client applies
each prop under compare-and-set (ASGARD-SERVER-METHODS.md §5.2).

A server runs `method` only under the rules of §10.2, with `#[Server]` in
place of `#[Action]`. The two are separate allowlists, so an `#[Action]` is
not callable as a call and the other way round. Otherwise it answers
`404 unknown_method`. An unknown address is `404 unknown_component`, and a
malformed body, an unknown member, or state or args that do not fit their
types is `422 invalid_request`.

Failures and timeouts are as in §10.2, with `region` null on the event.

## 11. Versioning

`protocol` in the handshake is an integer. A server that does not speak the
client's version says so at handshake, not mid-render. Clients support exactly
one version per build; servers may support several.

## 12. Server screens

A region fills part of a screen the app owns. A server screen is a whole
screen the server owns: its body, its chrome, and the route that opens it.
It still lives inside the app's shell. The app opens it, stacks it, and
can always go back from it, and an app route always wins over a server
route.

### 12.1 Screens

On the server a screen is a class with a route:

```php
#[Screen('/promos/{id}')]
class PromoScreen extends ServerScreen
{
    public int $id;

    #[Action]
    public function like(): void { /* ... */ }

    public function render(): Node
    {
        return Node::column(Node::text("Promo {$this->id}"));
    }

    public function chrome(): Chrome
    {
        return Chrome::make()->navBar(NavBar::make()->title('Promo')->back());
    }
}
```

The reference server registers screens by class, in
`config('asgard.screens')` or with `Asgard::screen()`, and reads the route
from the attribute. That keeps the route next to the class, and keeps the
same rule as §4: the developer writes the map, and nothing from the wire
names a class. Registering a screen also registers it as a component at
its **screen address**, by default its class name in dot notation, or the
attribute's `name`. Two screens may not share a route or an address.

On the device a server route always opens one generic screen,
`ServerScreen`. It never opens one of the app's own screen classes, so a
server cannot point a route at app code. Its body is a single region named
`screen`, so everything in §3, §8.1 and §10 applies unchanged:

- It asks for its fragment with the usual region request. `component` is
  the screen address and `region` is `screen`.
- Its `state` is the route's parameters over its query string. Route
  values are strings on the wire. A server converts a numeric string to an
  `int` or `float` property and `"true"`, `"false"`, `"1"` or `"0"` to a
  `bool` property, for screens only. Anything else must match exactly, as
  in §5.1.
- Until an answer lands, it shows a placeholder skeleton. It does not wait
  for ever: see "Giving up" below.
- Server actions, client actions and pushed fragments work as in regions.
  A client target can only reach a `#[NativeAction]` on the generic screen,
  and it has none, so a client target in a server screen runs nothing.
- A server screen has one body. `#[Region]` methods on a screen class are
  an error.
- `render()` may return a Blade view (§9.5). Chrome comes only from
  `chrome()`, so a `<top-bar>` in the body view is refused like any other
  chrome in a fragment (§9.3).

The answer to a screen's render, action or push carries the chrome next to
the fragment:

```json
{"protocol": 1, "region": "screen", "fragment": {...}, "chrome": {...}}
```

`chrome` is always an object for a screen, `{}` for none, and absent for
any other component. On a push it rides in the same event body (§8.1).

#### Giving up

A server screen waits at most its screen timeout for its first body: 15
seconds from its first frame by default, from
`nativephp.asgard.screen_timeout`, or the screen class's `screenTimeout()`.
It gives up when that passes, or at once when the request for the body fails
hard (the reasons in §10.2: `network`, `server` or `invalid`). Giving up
means:

1. It goes back to the screen below. When there is none, a cold deep link
   straight onto a server screen, it replaces itself with the app's `/`
   route.
2. Once that screen is the live one,
   `Native\Mobile\Events\Asgard\ServerScreenFailed` fires, with `uri`,
   `address`, `reason`, `status` and `message`. The screen hears it with
   `#[On]`, and the app with `Event::listen`:

```php
#[On(ServerScreenFailed::class)]
public function serverScreenFailed(string $uri, string $reason, ?int $status): void {}
```

An answer that lands after the screen gave up is dropped. The timer stops for
good once a body lands: a later refresh, action or push that fails leaves
that body on screen, as for any region. Leaving the screen stops it too.

Every server route opens the class named in `nativephp.asgard.screen`, which
must extend `ServerScreen`, or `ServerScreen` itself when that is unset. An
app uses its own subclass to change the skeleton or the timeout.

### 12.2 Chrome

Chrome is to a server screen what a layout is to an app screen. In the app,
a `NativeLayout` gives a screen its nav bar, tab bar and bottom content, and
the screen tweaks them with `navigationOptions()`, `tabBarOptions()` and
the `$hidesNavBar` / `$hidesTabBar` shortcuts. A server screen has no layout
class, so its chrome says all of that at once.

The client checks chrome again before it uses it, against a closed
vocabulary: every key known, every value its type, every button slot a
valid slot (§10.1, §12.4), every tab URL an app path, every content slot a
valid fragment (§9.3, §9.4). Chrome that fails keeps the screen on the
chrome it had and is logged, or throws with `app.debug` on. Until the first
answer, a screen wears the chrome it last had in this process, or a plain
nav bar with a back button when it is not the root.

Buttons in chrome register in the `screen` region's callback scope (§10.4),
so a chrome tap dispatches exactly like a tap in the body.

| Layout capability today | Chrome field |
|---|---|
| `NativeLayout::usesNativeChrome()` | `native` (bool, default false) |
| `NativeLayout::$font` | `font` |
| `NativeLayout::navBar()` returning a bar, or null | `nav_bar` present, or absent |
| `NavBar::title()` / `subtitle()` | `nav_bar.title` / `nav_bar.subtitle` |
| `NavBar::back()` | `nav_bar.back` |
| `NavBar::backgroundColor()` / `textColor()` / `font()` / `elevation()` | `nav_bar.background_color` / `text_color` / `font` / `elevation` (0 to 64) |
| `NavBar::displayMode()` | `nav_bar.display_mode`: `large`, `inline`, `automatic` |
| `NavBar::scrollBehavior()` | `nav_bar.scroll_behavior`: `collapse`, `pinned`, `enterAlways` |
| `NavBar::logo()` | `nav_bar.logo`: `{"src", "height"?}`, `src` a URL (§9.6) |
| `NavBar::titleView()` | `nav_bar.title_view`: a fragment |
| `NavBar::searchBar()` | `nav_bar.search`: `{"placeholder"?, "debounce_ms"?, "on_query"?}`. `on_query` is a call slot; the query is appended to its args |
| `NavBar::action()`, `NavAction` | `nav_bar.actions[]`: `{"id", "label"?, "a11y_label"?, "icon"?, "ios_icon"?, "android_icon"?, "image"? (a URL, §9.6), "destructive"?, "disabled"?, "on_press"?}` |
| `NavAction::items()`, `NavAction::divider()` | `actions[].items[]`, one level deep; `{"id", "divider": true}` |
| `$hidesNavBar`, `NavBarOptions::hidden()` | `nav_bar.hidden` |
| `NativeLayout::tabBar()` returning a bar, or null | `tab_bar` present, or absent |
| `TabBar::activeColor()` / `backgroundColor()` / `textColor()` / `font()` | `tab_bar.active_color` / `background_color` / `text_color` / `font` |
| `TabBar::labelVisibility()` / `dark()` / `minimizeOnScroll()` | `tab_bar.label_visibility` / `dark` / `minimize_on_scroll` |
| `TabBar::presentation()` | `tab_bar.presentation`: `{"id", "configuration"?}` |
| `Tab::link()` / `Tab::action()` / `Tab::search()` | `tab_bar.tabs[]`: `{"id", "label", "url"?, "icon"?, "ios_icon"?, "android_icon"?, "badge"?, "badge_color"?, "news"?, "active"?, "search"?, "on_press"?}` |
| `Tab::press()` | `tabs[].on_press` |
| `$hidesTabBar`, `TabBarOptions::hidden()` | `tab_bar.hidden` |
| `TabBarOptions::highlight()` | `tab_bar.highlight` |
| `NativeLayout::bottomBar()` | `bottom_bar`: a fragment (native chrome only, as in the app). The reference server takes a Blade view here too (§9.5) |
| `NativeLayout::tabBarAccessory()` | `tab_accessory`: a fragment (native chrome only), or a Blade view on the reference server |
| `$this->setNavBar()` / `setTabBar()` | Not needed: the next answer's chrome replaces the old one. |
| Inline `<native:top-bar>`, `<native:bottom-nav>`, `<native:side-nav>`, `<native:fab>` | Not in chrome. Side nav and FAB have no layout builder in the app either. |
| `searchItems()` / `onSearchQuery()` for the search tab | Not yet. A search tab shows only while selected, as for a screen without them. |
| Chrome contributors (plugins) | Still apply. They run on the device, as for any screen. |

A link tab opens its route with `replace`, like an app tab, but through the
checked navigation path in §12.4. Its URL must match a route when tapped.

### 12.3 Routes

A server lists its screens' routes at one more endpoint, sealed like render
(§7.3, §7.4) and read-only like it, so it carries no nonce:

```
→  QUERY {path}/routes        (or POST plus X-Asgard-Method: QUERY)
   {"protocol": 1}

←  200
   {"protocol": 1, "version": "3f9c0a1b2c3d4e5f",
    "routes": [{"uri": "/promos/{id}", "screen": "promos.show"}]}
```

- `uri` is a route pattern of literal segments (`A-Za-z0-9._~-`) and
  `{name}` parameters, with an optional `{name?}` allowed last. `/` is the
  root. Nothing else.
- `screen` is a dot-notation screen address, as in §4.
- `version` changes whenever the list does.

The client fetches the list **in the background, never in the way of boot
or a frame.** At boot it registers the list it cached last time, from the
same shared cache store as the session (§6.2), with no network. Once the
first screen has mounted, it fetches the list on an AsyncTask lane, caches
it, and registers it if the version changed. A failed fetch keeps the
cached list and may be retried a minute later. An app that has never
reached its server has no server routes.

Registering replaces the whole server table. The router checks each entry
again and drops one that is malformed, or whose pattern is already an app
route. Server routes are matched only after every app route, literal and
parameterized, so a server route can add a screen but never replace one of
the app's.

A server route can also arrive as a request rather than a navigation: a
cold deep link the shell loads, or the hot-reload restore that sends the
app back to the screen that was on top. The app's HTTP router has no route
for it. So on the device, a page load (`Accept: text/html`, or no `Accept`
at all for a direct dispatch from the shell) for a path that no HTTP route
matches, and that a registered server route does, opens the generic
`ServerScreen` the way an app route opens its screen. Every HTTP route
keeps priority, including the app's native routes. A catch-all fallback
route does not count as a match. Because the cached list is registered at
boot, this works offline for any route the app has seen before.

The shells' boot planners only know the app's routes, so a cold link to a
server route never boots straight into it. It reaches PHP as a WebView page
load, or is replayed as a warm link once the start screen is up, and opens
from there.

### 12.4 Navigation

A press slot (`on_press`, `on_long_press`, `on_double_tap`) in a fragment or
in chrome can move the app instead of calling a method:

```json
{"navigate": "/promos/42"}
{"navigate": "/inbox?unread=1", "replace": true, "transition": "fade"}
{"back": true, "transition": "slide_from_left"}
```

- `navigate` is an app path: one leading `/`, no scheme or host, no
  backslash, no whitespace or control characters, at most 2048 bytes. A web
  URL is never one.
- `replace` is a boolean. `transition` is one of the client's transitions:
  `slide_from_right`, `slide_from_left`, `slide_from_bottom`, `fade`,
  `fade_from_bottom`, `scale_from_center`, `parallax_push`, `morph`,
  `none`.
- `{"back": true}` takes only `transition`.
- No other keys. `on_change` and `on_submit` cannot navigate, and neither
  can a search field's `on_query`.

The server checks the shape. The client checks it again, and when the tap
lands it checks that the path matches a route it knows right now: one of
its own or a server screen's. Any route qualifies; no opt-in is needed. A
path that matches nothing runs nothing and is logged, or throws with
`app.debug` on. It never falls through to the web the way an unmatched
`navigate()` in app code does, and there is no way to exit to the web.

## 13. Open questions

1. Whether a region can be rendered without a bearer token at all, for public
   screens shown before sign-in.
2. Fragment caching, **in the application only**. Nothing in front of the app
   will help: no CDN or reverse proxy caches QUERY, and Fastly converts a
   non-GET/POST request to GET on cache lookup and drops the body, which is
   documented as not overridable. Symfony's `HttpCache` does mix the request
   body into the cache key, so an in-app cache works. Do not design around
   edge-cached fragments.
3. ~~Navigation from inside a fragment.~~ Settled: a press slot can hold a
   navigation (§12.4). Any route the app knows is a target, its own or a
   server screen's, and the client checks the route exists when the tap
   lands. Web URLs and exiting to the web are not possible. `_navigate` in
   `props` is still stripped (§9.3).
4. Offline queueing of events fired against a fragment that is no longer
   current.
5. ~~`server_region` has no native renderer on iOS or Android.~~ Settled: the
   element serialises as a bare `column` (`Element::wireType()`), keyed with
   the region name and carrying no props of its own, on the flat-buffer, Jump
   and streaming paths alike. `server_region` remains the PHP-side type, so
   validation still refuses one inside a fragment. No native change needed.
6. ~~The handshake response carries the session `secret` in its body, so a
   logging proxy that sees the handshake sees every key derived from it.~~
   Settled: the handshake is an ephemeral P-256 ECDH exchange, in the spirit
   of RFC 8291, and the secret never crosses the wire (§6.1). An active
   attacker that terminates TLS is still TLS's to stop (§7.1).
7. The push stream holds a server worker per device for its lifetime
   (§8.1). One stream per device keeps that to one worker, but a server
   with many devices on server regions at once still needs a worker each.
   Whether pushes should move to something that does not hold a PHP worker
   (a separate SSE service fed by the push counters, say) is open.
8. Bursts after a wide push. A push to a channel many devices joined sends
   each of them a signal at about the same time, and each fetches the region
   at once. Whether clients should spread those fetches out (a random delay
   of a second or two, say) is open. Nothing does it today.
9. Timeouts are fixed per component or screen class, not per action. Whether
   an action should be able to say it needs longer (an upload, say) is open.
   Regions have one too (§3.1), and the same question applies.
10. Whether a region that failed should retry by itself, with a backoff. Today
    it waits for `refreshServerRegions()`, so the app decides when.

## 14. Notes for implementers

`NativeElementCollector::reset()` is not a frame boundary, despite reading like
one. It is called at both ends of a Blade render, and not at all for a screen
that returns a programmatic `Element` tree. The real boundaries are
`NativeComponent::renderToElement()` and `streamView()`.
