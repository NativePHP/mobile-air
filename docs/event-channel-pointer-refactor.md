# Plan: pointer-backed native→PHP event channel (lift the 4KB cap)

## Context

Native events (camera/gallery/scanner results, any plugin event dispatched back to PHP —
the "type-20" events that fire `then()`/`#[On]` callbacks) cross from native into the embedded
PHP runtime through a **fixed inline buffer in the shared-memory region**:

```c
/* nphp_element.h + bridge_jni.cpp (mirrored struct), iOS reads it by offset */
uint8_t event_buffer[4096];
```

After a ~23-byte header the usable payload is ~4073 bytes. Anything larger is **truncated** by
the writer (`Event data too large — truncating`) and **dropped** by the reader
(`event_size > sizeof(event_buffer)`), corrupting the JSON. We raised this from 512→4096 to fix
the gallery `MediaSelected` case, but 4KB is still a hard ceiling — large scanner/barcode results,
big lists, or any chunky plugin payload will hit it.

**Goal:** make the channel effectively unlimited while keeping the simple JSON-dispatch model
plugin authors rely on. The enabler: PHP is embedded **in the same process** (php_embed via JNI on
Android, static-linked on iOS), so a native-side heap pointer is valid to the PHP reader — no
cross-process serialization is fundamentally required. The region **already** stores heap pointers
this way (`flat_buffer`/`prop_buffer`, the 512KB render buffers). Events are the lone holdout still
inline.

## ⚠️ Approach revised after reading the code (centralized C function)

Two findings changed the design from the original mirror+offset sketch below:

1. **Two `uint16_t` framing caps, not just the buffer.** A native event's `data_size` is u16, and
   inside it the JSON payload is written/read via `npui_*_str` with a u16 length prefix. So a
   growable buffer alone lifts the cap from **4KB → ~64KB**, not to infinity. Split the work:
   - **Phase 1 (now):** growable heap buffer → ~64KB per event. Covers realistic payloads.
   - **Phase 2 (later, optional):** widen `data_size` + the payload `npui_*_str` length to u32 on
     both sides → truly unlimited.

2. **Centralize the write in one C function — don't mirror/offset on the native side.** Today the
   header framing + buffer write is duplicated in `bridge_jni.cpp` (C++) and `NativeElementBridge.swift`
   (poking the region by hardcoded offset). Replace both with a single extension function:

   ```c
   void nphp_element_post_event(int type, int callback_id, int node_id,
                                const uint8_t *data, uint32_t data_len);
   ```

   It owns the `event_mutex`, the growable buffer, and the header framing (single source of truth for
   the wire format). The native producers become thin:
   - **Android** `element_write_event` (JNI) → extract the `jbyteArray` and call `nphp_element_post_event`.
     No stack buffer, no truncation, no event-field poking. The bridge's mirror struct needs **no
     change** (it no longer reads event fields; appended struct fields don't move existing offsets).
   - **iOS** `NativeElementBridge.swift` event write → call `nphp_element_post_event` via
     `@_silgen_name` (same mechanism already used for `_persistent_php_boot`). **No hand-computed
     struct offset** — this removes the riskiest part of the original plan.

   Only the **consumer** (`nphp_element_wait_event`) + region init/teardown touch the new appended
   struct fields.

This keeps the struct change append-only (below), but the producers never see the struct layout.

---

## Design — region-owned growable heap buffer, appended

Replace the *use* of the inline buffer with a single **region-owned, growable heap buffer**:

- Add at the **tail** of the region struct: `uint8_t *event_heap;` + `uint32_t event_heap_cap;`.
- **Keep `event_buffer[4096]` in the struct, vestigial/unused**, so every existing byte offset
  stays put (append-only ABI change — see offset note below). It's ~4KB of dead space; reclaim it
  in a future major layout bump if desired.
- Producer (native): before writing, `realloc` `event_heap` if the event (or accumulated events)
  exceed `event_heap_cap`; write the full JSON event, no truncation; publish `event_size`.
- Consumer (PHP): read from `event_heap` instead of the inline array; drop the
  `event_size > sizeof(event_buffer)` cap (sanity-check against `event_heap_cap` instead).
- Allocated once at region init (e.g. 8KB), grown to high-water-mark, freed at teardown. **No
  per-event alloc, no ownership transfer, no leak-on-overwrite** — one buffer, reused. Everything
  stays under the **existing `event_mutex`/`event_cond`**, so concurrency is already handled.

Single code path (always heap) — simpler than an inline-fast-path/overflow hybrid, and it
preserves the iOS behaviour of **accumulating multiple events** between consumes (it just grows the
heap buffer as needed instead of bailing at 4096).

### Why append, not replace (offset evidence)

iOS reads the region by hardcoded byte offsets (`NativeElementBridge.swift` `RegionOffset`):
`eventBuffer = 264`, then `flatBuffer = 4360`, `propBuffer = 4368`, `typeCount = 4404`,
`typeTable = 4662`. `event_buffer[4096]` occupies 264..4360. **Replacing** it with an 8-byte
pointer shifts every field after it by ~4088 bytes — all those iOS constants (and the C struct)
would have to be recomputed. **Appending** `event_heap`/`event_heap_cap` after the last field
leaves every existing offset untouched; iOS just adds two new constants at the tail.

### Optional: lift the 64KB framing cap

The event's internal payload length is a `uint16_t` (`data_size = npui_read_u16(&rd)` on the read
side; `write_u16` on the write side). Even pointer-backed, a single event's data field is capped at
64KB by this field. Widen it to `uint32_t` (write + read, both platforms) **only if** payloads can
exceed 64KB. For most "largest realistic payloads" 64KB is plenty; do this as a follow-on if needed.

## Changes per side

### 1. PHP extension — `build-scripts/shared/nativephp/` (→ requires a PHP-binary rebuild)
- `nphp_element.h`: append `uint8_t *event_heap;` + `uint32_t event_heap_cap;` to the region struct
  (after the current last field). Bump the struct-layout version constant (see Version handshake).
- `nphp_element.c`:
  - region init: `event_heap = malloc(INITIAL)`, `event_heap_cap = INITIAL`.
  - `nphp_element_wait_event`: read from `r->event_heap` (not `r->event_buffer`); replace the
    `> sizeof(event_buffer)` guard with `> r->event_heap_cap`.
  - teardown: `free(r->event_heap)`.
  - (optional) read `data_size` as `u32`.

### 2. Android — `mobile-air/resources/androidstudio/app/src/main/cpp/bridge_jni.cpp`
- Mirror the appended struct fields exactly (same order/types).
- `element_write_event`: grow `region->event_heap`/`event_heap_cap` via `realloc` when needed; build
  the event straight into `event_heap` (drop the 4096 stack buffer + truncation branch); set
  `event_size`. The realloc happens under the existing `event_mutex`.
- (optional) write `data_size` as `u32`.

### 3. iOS — `mobile-air/resources/xcode/NativePHP/NativeRender/NativeElementBridge.swift`
- Add `RegionOffset.eventHeap` / `eventHeapCap` constants at the struct tail (after `typeTable`).
- Event write path (~line 500): instead of the `currentSize + totalSize <= 4096` guard + writing at
  `RegionOffset.eventBuffer`, read the `event_heap` pointer from the region, `realloc` it (grow) when
  `currentSize + totalSize > event_heap_cap`, and write there.
- **Allocator gotcha:** use C `malloc`/`realloc`/`free` (callable from Swift) — **not** Swift's
  `UnsafeMutableRawPointer.allocate`/`.deallocate` — so the C consumer/teardown manages the same
  allocation. Keep the existing `pthread_mutex_lock(mutexPtr)` / cond-signal.
- (optional) write `data_size` as `u32`.

### 4. Version handshake (do this in the same change)
Bump the region struct-layout version (the post-release `struct_layout_version` idea). Both the
native producer and the PHP extension check it at region init and **fail fast** on mismatch, so a
half-updated build (new PHP binary vs old native, or vice versa) can't silently corrupt the region.

## Ownership & threading (summary)
- The region owns `event_heap`; producers `realloc` it under `event_mutex`; the consumer reads under
  the same mutex; freed once at teardown.
- No per-event allocation, no pointer handed across as owned memory, no double-free, no overwrite
  leak. Pointers are valid because native + embedded PHP share one address space.

## Verification
- **Round-trip a >4KB event** (e.g. a synthetic `MediaSelected` with many long paths, or a large
  scanner result) on **both** platforms; assert the PHP side decodes it intact and **no truncation
  log** appears.
- If the u32 widening is done, round-trip a **>64KB** event too.
- Confirm normal small events (taps, single photo) are byte-identical and unaffected.
- Memory: confirm `event_heap` grows once to the high-water mark and is stable thereafter (no
  per-event churn), and is freed on teardown.
- Exercise the iOS multi-event accumulation path (several events between consumes) to confirm the
  grow logic preserves it.

## Risks / notes
- **ABI lockstep + PHP rebuild.** Three codebases must agree on the struct (C extension via
  build-scripts → rebuild the PHP binary; Android cpp; iOS Swift). The version handshake is what
  makes this safe — land it in the same change.
- **iOS specifics** are the fiddly part: the C-allocator rule and computing the two new tail
  offsets. Keep the existing mutex/cond usage.
- **Vestigial inline buffer** leaves ~4KB dead space in the region; harmless, reclaim in a future
  major layout bump.
- Keep the **"references not blobs"** convention for genuinely unbounded data (file lists, blobs) —
  pointer-backed events remove the practical cap, but a synchronous per-event channel still
  shouldn't carry megabytes; pass a path/id and read the bulk out-of-band.

## Effort
Small logic, cross-cutting coordination. ~1 focused day including both-platform verification — most
of it testing and the iOS offset/allocator details, not code volume.

## Key files
- `build-scripts/shared/nativephp/nphp_element.h` — region struct (+ layout version)
- `build-scripts/shared/nativephp/nphp_element.c` — `nphp_element_wait_event`, region init/teardown
- `mobile-air/resources/androidstudio/app/src/main/cpp/bridge_jni.cpp` — region mirror + `element_write_event`
- `mobile-air/resources/xcode/NativePHP/NativeRender/NativeElementBridge.swift` — `RegionOffset` + event write

---

## Phase 2 — uint16 → uint32 framing (unlimited payloads), format_version 3

Phase 1 made the *transport* growable (pointer+length into a realloc-doubling
shm heap), but two `uint16` length **fields** still capped the channel at 65,535
bytes — and on iOS `UInt16(text.count)` *traps* (crashes the app) past that;
Android silently wraps/corrupts. Phase 2 widens both to `uint32` (~4 GB,
RAM-bound = effectively unlimited). This is a wire-format change → `format_version`
bumped 2 → 3 (native readers hard-fail on mismatch, so all layers move in lockstep).

Widened (event channel only — render-direction flat/prop buffers untouched):
1. **Header `data_size`** — `nphp_element_post_event` writes u32; `nphp_element_wait_event`
   reads u32. No more `0xFFFF` clamp.
2. **Body string length prefixes** — new `npui_read_str32`/`npui_write_str32`
   (u32) in `nativephp_ui.h`, used by the consumer for TEXT_CHANGE / SUBMIT /
   RADIO_CHANGE / SELECT_CHANGE / NATIVE(name+payload). Generic `npui_*_str`
   (u16) stays the node/prop codec.
3. **Native body builders** — iOS `NativeElementBridge.swift` (`UInt16`→`UInt32`,
   2→4 byte prefix) and Android `NativeElementBridge.kt` (`putShort(...toShort())`
   →`putInt(...)`, `allocate(2+…)`→`allocate(4+…)`) across the 5 send*Event funcs.
4. **Version handshake** — `NPHP_FORMAT_VERSION` (nphp_element.h),
   `expectedFormatVersion` (Swift), `EXPECTED_FORMAT_VERSION` (Kotlin) all → 3.

`tabChange` keeps its u16 (it's an index, not a length).

Render-direction caps (`npui_write_str` u16, node `prop_size`/`raw_children` u16)
are unchanged — they bound a single rendered node's props at 64KB, a separate
concern from the native→PHP event channel.

Requires a PHP-bins rebuild (`build-scripts`) + app rebuild.
