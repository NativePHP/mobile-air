# EDGE Runtime Architecture: From Shared Memory to PHP-JSI

**Author:** Shane Rosenthal
**For:** Simon Hamp (yes Simon, you need to read the whole thing)
**Date:** February 2026
**Status:** Architectural proposal — current system documented + future direction

---

## Table of Contents

1. [The Current System (What Simon Needs to Understand)](#1-the-current-system)
2. [The Binary Wire Protocol](#2-the-binary-wire-protocol)
3. [The Synchronization Dance](#3-the-synchronization-dance)
4. [React Native JSI — What the Cool Kids Did](#4-react-native-jsi)
5. [Where We're Similar (Simon Will Like This Part)](#5-similarities)
6. [Where We're Different (Simon Will Like This Less)](#6-differences)
7. [The Problem Space](#7-the-problem-space)
8. [The Solution: PHP-JSI](#8-the-solution-php-jsi)
9. [What We Delete](#9-what-we-delete)
10. [What We Build](#10-what-we-build)
11. [Migration Path](#11-migration-path)

---

## 1. The Current System

Simon, here's how the native rendering pipeline actually works right now. Every time a Blade component renders on Android, this entire machine spins up. Get comfortable.

### 1.1 The Big Picture

```
PHP Thread                          Kotlin UI Thread
    |                                      |
    |  Blade renders component             |
    |  PHP builds nested zval array        |
    |         |                            |
    |  nativephp_ui_render($tree)          |
    |         |                            |
    |  npui_serialize_tree()               |
    |  [walks PHP array, writes binary     |
    |   to mmap'd buffer byte-by-byte]     |
    |         |                            |
    |  atomic_store(tree_size)             |
    |  atomic_fetch_add(tree_version)      |
    |  pthread_cond_signal(tree_cond)      |
    |         |                            |
    |         +--- shared memory --------->|
    |              2.8 MB mmap region      |
    |                                      |  npui-tree-watcher thread wakes
    |                                      |  nativeGetTreeBuffer()
    |                                      |  [copies entire buffer to Java byte[]]
    |                                      |       |
    |                                      |  NativeUITreeReader.read()
    |                                      |  [parses binary into NativeUINode tree]
    |                                      |       |
    |                                      |  Handler.post { currentTree.value = tree }
    |                                      |       |
    |                                      |  Compose re-renders
    |                                      |
```

That's seven steps between "PHP has a tree" and "pixels on screen." Simon, seven. Let's walk through each one.

### 1.2 The PHP Extension

Lives at `~/AndroidStudioProjects/build-scripts/php-build/nativephp/`. Two files do all the heavy lifting:

**`nativephp_ui.h`** — The header that defines everything:
- `npui_shared_region_t` struct (the shared memory layout)
- Buffer sizes (2MB tree, 512KB patches, 256KB events)
- Binary writer/reader inline functions
- 44 interned prop key constants
- 10 value type tags
- 15 event types

**`nativephp_ui.c`** — The implementation:
- `npui_init()` — Creates the shared memory region via `mmap(MAP_SHARED|MAP_ANONYMOUS)`
- `npui_serialize_tree()` — Walks a PHP zval array and writes binary V2 format
- `npui_serialize_patches()` — Same but for incremental updates
- `npui_wait_event()` — Blocks on a condvar until Kotlin writes an event
- `npui_shutdown()` — The terrifying 200ms-sleep-and-pray cleanup

Simon, here's the `mmap` call. This is where our ~2.8MB shared memory region is born:

```c
// nativephp_ui.c:69-71
void *mem = mmap(NULL, NPUI_REGION_TOTAL_SIZE,
                 PROT_READ | PROT_WRITE,
                 MAP_SHARED | MAP_ANONYMOUS, -1, 0);
```

`MAP_SHARED | MAP_ANONYMOUS` means: give me a chunk of memory that multiple threads can see, but don't back it with a file. It's RAM-only. The `fd=-1` confirms no file descriptor. This is basically the OS giving us a scratch pad that both the PHP thread and the JNI bridge thread can touch.

### 1.3 The Shared Memory Layout

Simon, picture this as a big flat byte array with sections:

```
Offset 0                    ┌──────────────────────────────┐
                            │  npui_shared_region_t header  │
                            │  (~300 bytes)                 │
                            │                               │
                            │  magic: 0x4E505632 ("NPV2")   │
                            │  tree_offset, tree_size        │
                            │  tree_version (atomic u32)     │
                            │  tree_version_ack (atomic u32) │
                            │  patch_offset, patch_size      │
                            │  patch_version (atomic u32)    │
                            │  event_offset, event_size      │
                            │  event_count (atomic u32)      │
                            │                               │
                            │  pthread_mutex_t event_mutex   │
                            │  pthread_cond_t  event_cond    │
                            │  pthread_mutex_t tree_mutex    │
                            │  pthread_cond_t  tree_cond     │
                            │                               │
                            │  shutdown (atomic u32)         │
                            │  running  (atomic u32)         │
Offset sizeof(header)       ├──────────────────────────────┤
                            │  Tree Buffer (2 MB)           │
                            │  [binary V2 tree data]        │
Offset header + 2MB         ├──────────────────────────────┤
                            │  Patch Buffer (512 KB)        │
                            │  [binary NPP1 patch data]     │
Offset header + 2MB + 512KB ├──────────────────────────────┤
                            │  Event Buffer (256 KB)        │
                            │  [binary NPEV event data]     │
                            └──────────────────────────────┘
                            Total: ~2.8 MB
```

All the atomic fields use `_Atomic uint32_t` on the C side and `std::atomic<uint32_t>` on the C++ side. These are layout-compatible on ARM64 Android — both are lock-free, 4-byte aligned. The mutexes and condvars are initialized with `PTHREAD_PROCESS_SHARED` so they work across threads that share the memory region.

### 1.4 The JNI Bridge

`bridge_jni.cpp` is the C++ glue between the mmap region and Kotlin. It has two personas:

**Persona 1: The God Method Router**

When PHP calls `nativephp_call('Dialog.Alert', '{"title":"Hey Simon"}')`, the extension does `dlopen("libphp_wrapper.so")` + `dlsym("NativePHPCall")`, which lands in `bridge_jni.cpp`. The C++ code then does a JNI call up to Kotlin's `BridgeRouterKt.nativePHPCall()`, which looks up `Dialog.Alert` in the `BridgeFunctionRegistry` and executes it. The result JSON string comes back down through JNI → C++ → PHP.

Simon, every single `nativephp_call()` does a `dlopen`. Every time. We should cache that handle. But that's a different doc.

**Persona 2: The UI Memory Bridge**

Eight JNI native methods registered at init time:

| JNI Method | What It Does |
|---|---|
| `nativeIsUIReady()` | Checks if `g_npui_direct_ptr` is set and magic matches |
| `nativeGetTreeVersion()` | `atomic_load(tree_version)` — one instruction |
| `nativeGetTreeBuffer()` | `NewByteArray(size)` + `SetByteArrayRegion` — **full copy** |
| `nativeWaitTreeUpdate()` | `pthread_cond_timedwait` on `tree_cond` — blocks the thread |
| `nativeWriteEvent()` | Serializes event to stack buffer, `memcpy` to shared region, signal |
| `nativeGetPatchVersion()` | `atomic_load(patch_version)` |
| `nativeGetPatchBuffer()` | Same copy pattern as tree buffer |
| `nativeAckPatchVersion()` | `atomic_store(patch_version_ack)` |

The critical thing Simon needs to understand: `nativeGetTreeBuffer()` does this:

```cpp
// bridge_jni.cpp:366-369
jbyteArray result = env->NewByteArray(size);       // allocate Java byte array
if (result == nullptr) return nullptr;
env->SetByteArrayRegion(result, 0, size, (jbyte*)tree_buf);  // memcpy into it
return result;
```

That's a full `memcpy` of the tree buffer into a GC-managed Java byte array. Every. Single. Render. For a 200-node tree, that's maybe 20-40KB per copy. Not huge, but it's pure overhead that doesn't need to exist.

### 1.5 The Android Linker Namespace Problem

Simon, this one's fun. On Android 7+, each `.so` file lives in its own "linker namespace." `dlsym(RTLD_DEFAULT, "some_symbol")` only finds symbols in your own namespace, not across `.so` boundaries. So when `nativephp_ui.c` (inside `libnativephp.so`) tries to find `NativeUI_RegisterRegion` (inside `libphp_wrapper.so`), `RTLD_DEFAULT` fails silently.

The fix:

```c
// nativephp_ui.c:128-133
void *wrapper = dlopen("libphp_wrapper.so", RTLD_NOLOAD | RTLD_NOW);
if (wrapper) {
    reg = (register_fn)dlsym(wrapper, "NativeUI_RegisterRegion");
}
```

`RTLD_NOLOAD` means "don't actually load it, just give me a handle to the already-loaded library." This gets us into `libphp_wrapper.so`'s namespace where we can find the symbol. It's ugly, Simon, but it works.

### 1.6 The Kotlin Side

**`NativeUIBridge.kt`** — Spawns a daemon thread (`npui-tree-watcher`) that:
1. Polls `nativeIsUIReady()` every 50ms until the PHP extension initializes the region
2. Enters a loop calling `nativeWaitTreeUpdate(lastVersion, 100)` — blocks on the condvar with 100ms timeout
3. On new version: copies buffer, parses tree, posts to main handler
4. On timeout: checks for patches, applies them incrementally
5. On shutdown (-1): exits cleanly

**`NativeUITreeReader.kt`** — Parses the V2 binary format from a `ByteArray` into a tree of `NativeUINode` Kotlin data classes. Wraps the byte array in a `ByteBuffer` with `LITTLE_ENDIAN` order and walks through it reading fields.

**`NativeUIPatchReader.kt`** — Same idea but for the NPP1 patch format. Also contains `applyPatches()` which does copy-on-write tree updates — only nodes on the path from root to the patched node get cloned.

**`NativeUIRenderer.kt`** — The Compose renderer. A `@Composable` function for each node type (column, row, text, button, text_input, toggle, scroll_view, card, list_item, tabs, bottom_sheet, icon, select, slider, checkbox, radio_group, chip, badge, progress_bar, activity_indicator, spacer, divider, image, stack). Plus all the modifier building, alignment resolution, and the scroll view flattening optimization.

Simon, the scroll view flattening (`flattenScrollContent()`) is worth calling out. The common Blade pattern is `<scroll-view><column p-4 gap-4>...items...</column></scroll-view>`. Without flattening, `LazyColumn` sees ONE child (the wrapper column) and eagerly composes every descendant — defeating virtualization entirely. The function extracts the wrapper's children, padding, gap, and alignment so `LazyColumn` can virtualize the actual items. It's a performance-critical hack.

---

## 2. The Binary Wire Protocol

Simon, we designed a custom binary protocol because JSON was too slow and too fat. Here's the full spec.

### 2.1 Tree Format (V2)

```
Tree Header:
  [4 bytes] magic    = 0x4E505632 ("NPV2")
  [4 bytes] version  = monotonically increasing uint32
  [4 bytes] callback_count (currently unused placeholder)
  [node]    root

Node:
  [4 bytes] id       = unique node identifier
  [string]  type     = "column", "text", "button", etc.
  [1 byte]  has_layout  (0 or 1)
  [1 byte]  has_style   (0 or 1)
  [1 byte]  has_props   (0 or 1)
  [4 bytes] on_press    = callback ID (0 = none)
  [4 bytes] on_long_press = callback ID (0 = none)
  [layout]  if has_layout
  [style]   if has_style
  [props]   if has_props
  [2 bytes] child_count
  [nodes]   children (recursive)

String:
  [2 bytes] length (uint16, max 65535)
  [N bytes] UTF-8 data

Layout (68 bytes):
  [f32] width          [u8] width_mode
  [f32] height         [u8] height_mode
  [f32] padding_top    [f32] padding_right   [f32] padding_bottom  [f32] padding_left
  [f32] margin_top     [f32] margin_right    [f32] margin_bottom   [f32] margin_left
  [f32] flex_grow      [f32] flex_shrink
  [u8]  align_self     [u8]  align_items     [u8] justify_content
  [f32] gap            [u8]  safe_area

Style (24 bytes):
  [u32] bg_color       (ARGB)
  [f32] border_radius
  [f32] border_width
  [u32] border_color   (ARGB)
  [f32] opacity
  [f32] elevation
```

### 2.2 Self-Describing Props

This is the clever bit, Simon. Props are typed key-value pairs where both the key and the value carry their own type information:

```
Generic Props:
  [1 byte]  prop_count
  per prop:
    [1 byte]  key_index
              0x00-0x2B = interned key (lookup table below)
              0xFF      = full string follows
    [string]  key (only if key_index == 0xFF)
    [1 byte]  value_type_tag
    [N bytes] value (depends on tag)

Value Type Tags:
  0 = U8      [1 byte]
  1 = U16     [2 bytes]
  2 = U32     [4 bytes]
  3 = I32     [4 bytes]
  4 = F32     [4 bytes]
  5 = BOOL    [1 byte]
  6 = STRING  [2+N bytes]  (length-prefixed)
  7 = COLOR   [4 bytes]    (ARGB)
  8 = CALLBACK [4 bytes]   (callback ID)
  9 = STRING_ARRAY [2 bytes count, then count strings]
```

### 2.3 Interned Key Table

Simon, 44 keys that get compressed to a single byte index. This saves ~8-15 bytes per prop occurrence vs sending the full string:

```
 0: text             1: label            2: value           3: color
 4: on_press         5: on_change        6: on_submit       7: on_dismiss
 8: disabled         9: placeholder     10: font_size      11: font_weight
12: text_align      13: max_lines       14: src            15: fit
16: tint_color      17: label_color     18: keyboard       19: secure
20: max_length      21: multiline       22: horizontal     23: shows_indicators
24: min             25: max             26: step           27: track_color
28: size            29: name            30: options        31: count
32: text_color      33: variant         34: headline       35: supporting
36: overline        37: leading_icon    38: trailing_icon  39: headline_color
40: supporting_color 41: selected_index 42: icon           43: visible
```

The interning happens at serialization time in `npui_intern_key()` — a linear scan of the 44-entry table. Not the fastest lookup (O(n) where n=44), but the table is tiny and it's called per-prop, not per-frame.

### 2.4 Patch Format (NPP1)

```
Patch Header:
  [4 bytes] magic = 0x4E505031 ("NPP1")
  [4 bytes] patch_version
  [2 bytes] operation_count

Per Operation:
  [1 byte]  op_type
  [4 bytes] node_id
  [N bytes] op-specific data

Op Types:
  1 = UPDATE_PROPS     [generic_props]
  2 = UPDATE_LAYOUT    [layout - 68 bytes]
  3 = UPDATE_STYLE     [style - 24 bytes]
  4 = UPDATE_CALLBACKS [4 bytes on_press] [4 bytes on_long_press]
```

### 2.5 Event Format (NPEV)

Events flow in the opposite direction — Kotlin writes them, PHP reads them:

```
Event:
  [4 bytes] magic = 0x4E504556 ("NPEV")
  [1 byte]  type (0=press, 1=long_press, 2=text_change, ...)
  [4 bytes] callback_id
  [4 bytes] node_id
  [8 bytes] timestamp (milliseconds since epoch)
  [2 bytes] data_size
  [N bytes] event-specific data

Event Data by Type:
  PRESS / LONG_PRESS:    [f32 x] [f32 y]
  TEXT_CHANGE / SUBMIT:  [u16 len] [N bytes UTF-8]
  TOGGLE / CHECKBOX:     [u8 value]
  SLIDER:                [f32 value]
  RADIO / SELECT:        [u16 len] [N bytes UTF-8]
  SCROLL:                [f32 offset_x] [f32 offset_y] [f32 content_w] [f32 content_h]
  TAB_CHANGE:            [u16 index]
  SHEET_DISMISS:         (no data)
  SYSTEM_BACK:           (no data)
  HOT_RELOAD:            (no data)
```

---

## 3. The Synchronization Dance

Simon, this is the part where it gets hairy. Two threads, shared memory, and no guardrails except what we put there ourselves.

### 3.1 Tree Publishing (PHP → Kotlin)

```
PHP thread:
  1. npui_serialize_tree(zval *tree, buffer, capacity, &size)
     → walks PHP array recursively, writes binary to buffer
  2. atomic_store(&region->tree_size, size)          // publish size first
  3. atomic_fetch_add(&region->tree_version, 1)      // then bump version
  4. pthread_mutex_lock(&region->tree_mutex)
  5. pthread_cond_signal(&region->tree_cond)          // wake the watcher
  6. pthread_mutex_unlock(&region->tree_mutex)

Kotlin watcher thread:
  1. nativeWaitTreeUpdate(lastVersion, 100)           // blocks on condvar
  2. → wakes up, checks version
  3. nativeGetTreeBuffer()                            // copies buffer to byte[]
  4. NativeUITreeReader(buffer).read()                // parses binary to Kotlin objects
  5. Handler.post { currentTree.value = tree }        // post to main thread
  6. → Compose observes currentTree, re-renders
```

The version number only goes up (monotonically increasing, never reset). This is critical — if we reset it, the watcher could see a "new" version that's actually stale data from before the reset. The `tree_version_ack` field lets PHP know Kotlin consumed the update, but PHP never actually waits on it. It's informational.

### 3.2 Event Delivery (Kotlin → PHP)

```
Kotlin UI thread:
  1. User taps button
  2. NativeUIBridge.sendPressEvent(callbackId, nodeId)
  3. → allocates ByteBuffer, writes [f32 x, f32 y]
  4. nativeWriteEvent(type, callbackId, nodeId, data)
  5. → JNI: serializes to stack buffer, memcpy to shared event buffer
  6. → pthread_cond_signal(&region->event_cond)

PHP thread:
  1. nativephp_ui_wait_event(timeout_ms)              // blocking
  2. → pthread_cond_timedwait on event_cond
  3. → wakes, reads event from shared buffer
  4. → npui_read_* functions parse binary
  5. → converts to PHP array: ['type' => 0, 'callback_id' => 42, ...]
  6. → PHP dispatches to registered callback
```

### 3.3 Patches (PHP → Kotlin, Incremental)

The watcher thread checks for patches during its 100ms timeout cycles:

```
PHP thread:
  1. nativephp_ui_patch($patches_array)
  2. npui_serialize_patches() to patch buffer
  3. atomic bump patch_version, signal tree_cond

Kotlin watcher thread (on timeout):
  1. nativeGetPatchVersion() — compare to lastPatchVersion
  2. nativeGetPatchBuffer() — copy patch buffer
  3. NativeUIPatchReader.read() — parse patch ops
  4. applyPatches(currentTree, patches) — copy-on-write tree update
  5. nativeAckPatchVersion(ver) — tell PHP we applied it
```

### 3.4 The Shutdown Problem

Simon, this is the scariest code in the project:

```c
// nativephp_ui.c:149-184
void npui_shutdown(void) {
    // 1. Signal shutdown
    atomic_store(&g_npui_region->shutdown, 1);
    atomic_store(&g_npui_region->running, 0);

    // 2. Wake anyone blocking
    pthread_cond_broadcast(&g_npui_region->event_cond);
    pthread_cond_broadcast(&g_npui_region->tree_cond);

    // 3. Tell JNI bridge to null its pointer
    unreg();  // NativeUI_UnregisterRegion()

    // 4. PRAY
    usleep(200000);  // 200ms for watcher thread to see shutdown and exit

    // 5. Destroy everything
    pthread_mutex_destroy(&g_npui_region->event_mutex);
    pthread_mutex_destroy(&g_npui_region->tree_mutex);
    pthread_cond_destroy(&g_npui_region->event_cond);
    pthread_cond_destroy(&g_npui_region->tree_cond);
    munmap(g_npui_region, NPUI_REGION_TOTAL_SIZE);
}
```

That `usleep(200000)` is load-bearing, Simon. Without it, the watcher thread might still be inside `pthread_cond_wait` when we `munmap` the region — SIGSEGV. The JNI bridge has a defensive check after waking:

```cpp
// bridge_jni.cpp:407-410
if (g_npui_direct_ptr == nullptr && get_ui_region() == nullptr) {
    pthread_mutex_unlock(&region->tree_mutex);
    return -1;  // region died while we were sleeping
}
```

But there's a race window between the condvar wake and this check where the region could be unmapped. The 200ms sleep is our safety margin. It works. I don't love it.

---

## 4. React Native JSI

Simon, React Native faced the exact same problem we did — a scripting language (JavaScript) needs to describe native UI and respond to native events. Their first attempt was eerily similar to ours. Their second attempt is what we should learn from.

### 4.1 The Old React Native Bridge (Our Evil Twin)

React Native's original architecture (pre-2024):

```
JavaScript → JSON.stringify() → Message Queue → JSON.parse() → Native
Native → JSON.stringify() → Message Queue → JSON.parse() → JavaScript
```

Every single interaction — every button press, every text change, every scroll event — went through JSON serialization, a FIFO message queue, and JSON deserialization. Meta's internal data identified bridge traffic as the #1 performance bottleneck.

Simon, this is basically what we built, except we used binary instead of JSON and shared memory instead of a message queue. We're better than their v1. But they didn't stop there.

### 4.2 JSI: The Replacement

JSI (JavaScript Interface) is a C++ abstraction layer over the JavaScript engine (Hermes). The key insight: **Hermes is a C++ library.** It runs in the same process as the native code. There's no reason to serialize anything — you can just pass pointers.

```cpp
// This is how React Native calls into JS now
auto runtime = facebook::hermes::makeHermesRuntime();
runtime->evaluateJavaScript(buffer, "app.js");
auto result = runtime->global().getProperty(*runtime, "render");
// result is a C++ jsi::Value — a direct reference to a JS object
// No serialization. No copying. No bridge.
```

When JavaScript calls a native method:
1. The JS engine resolves the call to a C++ `jsi::HostFunction`
2. Arguments arrive as `jsi::Value` references — **pointers into the JS heap**
3. Primitives (numbers, booleans) are passed by value
4. Objects and strings are passed by reference
5. Return values work the same way in reverse

### 4.3 JSI Memory Model

Simon, this is the part that matters. JSI does NOT use shared memory. It doesn't need to. Both JS and native code live in the same process, in the same address space. They share memory by default — it's the same heap.

The core types:

- **`jsi::Value`** — A variant that holds JS primitives or a pointer to a `jsi::Object`. Primitives copy. Objects reference.
- **`jsi::HostObject`** — A C++ class that appears as a JS object. Property access (`get`/`set`) dispatches to virtual C++ methods. This is how native modules expose themselves to JS.
- **`jsi::ArrayBuffer`** — Raw `uint8_t*` pointer into backing store. True zero-copy.

Ownership bridges two regimes:
- JavaScript side: garbage-collected heap
- C++ side: `std::shared_ptr` reference counting

When JS holds a reference to a HostObject, the shared_ptr prevents the C++ object from being destroyed. When the JS GC collects it, the shared_ptr decrements, eventually destroying the C++ object. Elegant, Simon. Annoyingly elegant.

### 4.4 JSI Threading

The `jsi::Runtime` is NOT thread-safe. All access must come from one thread at a time. React Native uses a `RuntimeExecutor` to queue callbacks onto the JS thread:

```cpp
runtimeExecutor([](jsi::Runtime &rt) {
    // This runs on the JS thread, safe to access runtime
    auto value = rt.global().getProperty(rt, "something");
});
```

For UI rendering, they use immutable data structures. The Shadow Tree is never mutated — updates create new trees with structural sharing (only changed nodes + ancestors are cloned). Multiple threads can read the same tree without locks because no one ever writes to it.

### 4.5 Fabric Renderer (Their EDGE Equivalent)

Three-phase pipeline:

1. **Render (JS Thread):** React executes components, creates Shadow Nodes in C++ via JSI — synchronously
2. **Commit (Background Thread):** Yoga (C++ layout engine) computes positions, tree is promoted
3. **Mount (UI Thread):** Diff old vs new tree in C++, apply minimal mutations to platform views

The entire Shadow Tree lives in C++. JS creates it through synchronous JSI calls. No serialization, no buffer, no copy.

### 4.6 Performance Numbers

Simon, brace yourself:

| Operation (100k calls) | Turbo Modules (HostObject) | Nitro Modules (NativeState) |
|---|---|---|
| `addNumbers(a, b)` | 115ms | **7ms** |
| `addStrings(a, b)` | 179ms | **30ms** |

A synchronous JSI function call is a C++ vtable dispatch — nanoseconds, not milliseconds. No JSON. No binary. No buffer. No copy.

For context, Meta migrated the entire Facebook app to JSI/Fabric and reported the biggest wins in:
- **Synchronous layout** (no more visual jumps from async measurement)
- **Concurrent rendering** (React 18 features)
- **High-throughput data** (camera frames at ~30 MB/frame)
- **Startup time** (lazy module loading)

---

## 5. Similarities

Simon, despite the different mechanisms, the high-level architecture is the same. Both NativePHP and React Native solve this problem:

```
Scripting Language          Native Renderer
      |                          |
      |-- render(tree) --------->| display
      |                          |
      |<-- event(tap, text) -----|
      |                          |
      |-- patch(changes) ------->| update
```

Specific parallels:

| Concept | NativePHP | React Native |
|---|---|---|
| Script runtime | PHP (Zend Engine, C) | JavaScript (Hermes, C++) |
| UI tree format | Binary V2 (NPV2) | C++ Shadow Nodes |
| Incremental updates | NPP1 patch ops | Immutable tree diffing |
| Event system | NPEV binary events via condvar | JSI direct dispatch |
| Renderer | Jetpack Compose (`NativeUIRenderer.kt`) | Fabric → platform views |
| Layout system | PHP-side (Blade) + Compose intrinsics | Yoga (C++ flexbox engine) |
| Component registry | `NativeRendererRegistry` | Fabric component registry |
| Interned keys | 44-entry prop key table | Codegen-generated type-safe bindings |
| Copy-on-write updates | `applyPatches()` tree cloning | Immutable structural sharing |
| Scroll optimization | `flattenScrollContent()` | View flattening (automatic) |

We even have similar naming: their "Shadow Tree" is our "NativeUITree." Their "ShadowNode" is our "NativeUINode." Their "Fabric" is our "NativeUIRenderer."

Simon, we independently converged on the same architecture that a billion-dollar company spent years building. That's either brilliant or concerning. Probably both.

---

## 6. Differences

Here's where it hurts, Simon. These are the gaps:

### 6.1 Serialization Tax

**Us:** Every render cycle serializes the entire tree (or patches) into binary, copies it across a memory boundary, and deserializes it on the other side.

**Them:** Zero serialization. JS calls C++ directly to create Shadow Nodes. The tree lives in C++ and both sides access it through pointers.

For a 200-node tree with average 5 props each:
- **Us:** ~1000 `npui_write_*` calls + `memcpy` + ~1000 `ByteBuffer.get*` calls
- **Them:** ~200 synchronous C++ function calls, zero copying

### 6.2 The Copy

**Us (`bridge_jni.cpp:366-369`):**
```cpp
jbyteArray result = env->NewByteArray(size);
env->SetByteArrayRegion(result, 0, size, (jbyte*)tree_buf);
```

Allocates a Java byte array, copies the entire tree buffer into it. Every render.

**Them:** No copy. The Shadow Tree IS the data structure both sides use.

### 6.3 Event Latency

**Us:** User taps → Compose handler → ByteBuffer allocation → JNI call → serialize event → write to mmap → condvar signal → PHP wakes → parse event → dispatch callback → re-render → serialize tree → condvar signal → Kotlin wakes → copy buffer → parse tree → Compose update.

**Them:** User taps → Native handler → JSI call → JS callback executes → Shadow Tree updated → diff → mount. All synchronous for discrete events.

### 6.4 Thread Model

**Us:** Two threads communicating through shared memory with mutexes/condvars. The PHP thread blocks waiting for events. The Kotlin watcher thread blocks waiting for tree updates. The UI thread receives trees via `Handler.post`.

**Them:** One JS thread, one UI thread, one background thread. The JS thread creates the tree synchronously. The UI thread mounts it. The background thread does layout. No blocking waits, no shared memory regions.

### 6.5 Shutdown

**Us:** `usleep(200000)` and hope the watcher thread noticed. Race conditions between `munmap` and thread wakeup.

**Them:** `shared_ptr` reference counting + GC. When nothing references the runtime, it cleans up. No 200ms prayers.

---

## 7. The Problem Space

Simon, here's what we're optimizing for and why it matters:

### 7.1 The Render Path Is Too Long

Current render path for a button press that changes UI:

```
1. User taps button                                            t=0ms
2. Compose click handler fires
3. NativeUIBridge.sendPressEvent() → ByteBuffer → JNI         t=0.1ms
4. JNI: serialize to stack buffer, memcpy to mmap, signal     t=0.2ms
5. PHP watcher wakes from condvar                              t=0.5ms (condvar latency)
6. PHP reads event, parses binary                              t=0.6ms
7. PHP dispatches to callback, re-renders Blade component      t=2ms (depends on complexity)
8. PHP builds zval array for new tree                          t=3ms
9. nativephp_ui_render() serializes to binary                  t=4ms
10. atomic_store + condvar signal                               t=4.1ms
11. Kotlin watcher wakes                                        t=4.5ms
12. nativeGetTreeBuffer() → NewByteArray + memcpy              t=5ms
13. NativeUITreeReader.read() parses binary                     t=6ms
14. Handler.post to main thread                                 t=6.5ms (handler scheduling)
15. Compose observes new tree                                   t=7ms
16. Compose diffs and re-renders                                t=10ms
```

~10ms tap-to-pixels for a simple interaction. Most of that is overhead, not actual work.

With a JSI-like approach:

```
1. User taps button                                            t=0ms
2. Compose click handler fires
3. JNI call to C bridge                                        t=0.05ms
4. C bridge calls zend_call_function(callback)                 t=0.1ms
5. PHP executes, returns zval* tree                            t=2ms
6. C bridge walks zvals, returns data via JNI                  t=2.5ms
7. Compose updates                                             t=5ms
```

~5ms. Half the latency by eliminating the round-trip through shared memory.

### 7.2 Memory Overhead

Current: 2.8MB mmap region (always allocated, even for simple pages) + Java byte array copies (allocated and GC'd per render) + Kotlin data class tree (allocated per render) + PHP zval array (allocated per render).

JSI-like: One set of C structs (persistent, mutated in place) + Kotlin reads through JNI accessors (no copy).

### 7.3 Complexity Budget

Simon, look at what we maintain for the current system:

- `nativephp_ui.h` — 302 lines of protocol definitions
- `nativephp_ui.c` — 984 lines of serialization, lifecycle, synchronization
- `bridge_jni.cpp` — 508 lines of JNI bridging (UI portion: ~250 lines)
- `NativeUIBridge.kt` — 287 lines of watcher thread, event helpers
- `NativeUITreeReader.kt` — 162 lines of binary parsing
- `NativeUIPatchReader.kt` — 230 lines of binary parsing + tree patching
- `NativeUINode.kt` — 198 lines of data models + interned key tables

That's **~2,670 lines** of code dedicated to moving data between two threads that are already in the same process. Simon, two thousand six hundred and seventy lines.

---

## 8. The Solution: PHP-JSI

Simon, here's the pitch. We treat the Zend Engine the same way React Native treats Hermes — as an embeddable C library that we call synchronously.

### 8.1 The Core Idea

The Zend Engine has an **embed SAPI** (`sapi/embed/`). It's designed for exactly this use case:

```c
php_embed_init(argc, argv);                           // boot the engine
zend_eval_string("require 'app.php'", NULL, "init");  // load the app
zend_call_function(&fci, &fcc);                       // call PHP, get zval* back
php_embed_shutdown();                                  // tear down
```

No separate thread. No event loop. No mmap. You call PHP like a function and read the return value as a `zval*` — a C struct you can walk without serialization.

### 8.2 The New Architecture

```
User taps button (UI thread)
    |
    +-- Kotlin calls JNI
            |
            +-- C bridge calls zend_call_function("handle_press", callback_id)
            |       |
            |       +-- PHP executes callback
            |       +-- PHP re-renders component
            |       +-- returns zval* (the new UI tree)
            |
            +-- C bridge walks the zval* tree IN PLACE
            |   reads node types, props, children directly from zval structs
            |   no serialization needed — it's just C struct pointer chasing
            |
            +-- returns node data to Kotlin via JNI
    |
    +-- Compose re-renders
```

Compare to the current 16-step path. This is 4 steps.

### 8.3 The C Module API

Simon, this is what we'd build — a new runtime bridge:

```c
// nphp_runtime.h — the "PHP-JSI"

typedef struct {
    int initialized;
    // Zend engine state lives in globals, but we track our lifecycle here
} nphp_runtime_t;

// Boot PHP once at app startup. Load the Laravel app.
int nphp_init(nphp_runtime_t *rt, const char *app_path);

// Call a PHP function synchronously. Returns a zval* you read directly.
// This is the equivalent of jsi::Runtime::evaluateJavaScript()
zval* nphp_render(nphp_runtime_t *rt, const char *component, zval *props);

// Dispatch a UI event to PHP. Returns the updated tree (or NULL for no change).
// This is the equivalent of calling a JS callback through JSI.
zval* nphp_dispatch(nphp_runtime_t *rt, uint32_t callback_id, zval *event_data);

// Direct zval accessors — these are the "JSI accessors"
// Kotlin calls these via JNI instead of parsing a binary buffer
const char* nphp_node_type(zval *node);
int         nphp_node_id(zval *node);
zval*       nphp_node_prop(zval *node, const char *key);
zval*       nphp_node_children(zval *node);
int         nphp_node_child_count(zval *node);
zval*       nphp_node_layout(zval *node);
zval*       nphp_node_style(zval *node);
float       nphp_node_layout_width(zval *node);
uint8_t     nphp_node_layout_width_mode(zval *node);
// ... etc for all layout/style/prop fields

void nphp_shutdown(nphp_runtime_t *rt);
```

### 8.4 The JNI Bridge (Simplified)

Simon, look how thin this gets:

```cpp
// Current: 250+ lines of mmap reading, buffer copying, event serialization

// Proposed: ~30 lines
static jstring node_get_type(JNIEnv* env, jclass, jlong nodePtr) {
    zval* node = (zval*)nodePtr;
    const char* type = nphp_node_type(node);
    return env->NewStringUTF(type);
}

static jint node_get_child_count(JNIEnv*, jclass, jlong nodePtr) {
    zval* node = (zval*)nodePtr;
    return nphp_node_child_count(node);
}

static jlong node_get_child(JNIEnv*, jclass, jlong nodePtr, jint index) {
    zval* node = (zval*)nodePtr;
    zval* children = nphp_node_children(node);
    zval* child = zend_hash_index_find(Z_ARRVAL_P(children), index);
    return (jlong)(uintptr_t)child;
}

static jlong dispatch_event(JNIEnv*, jclass, jint callbackId, jint type, jbyteArray data) {
    // Build zval event, call PHP, return new tree pointer
    zval* new_tree = nphp_dispatch(g_runtime, callbackId, &event);
    return (jlong)(uintptr_t)new_tree;
}
```

### 8.5 The Kotlin Side (Simplified)

```kotlin
// Current: NativeUITreeReader parsing ByteBuffer byte-by-byte
// Current: NativeUIBridge watcher thread polling condvars
// Current: NativeUIPatchReader parsing patch buffers

// Proposed: direct JNI reads
fun buildTree(nodePtr: Long): NativeUINode {
    val type = nativeNodeGetType(nodePtr)
    val childCount = nativeNodeGetChildCount(nodePtr)
    val children = (0 until childCount).map { i ->
        buildTree(nativeNodeGetChild(nodePtr, i))
    }
    return NativeUINode(
        id = nativeNodeGetId(nodePtr),
        type = type,
        props = readPropsFromNative(nodePtr),
        children = children,
        // ...
    )
}

// On event:
fun onButtonPress(callbackId: Int) {
    val newTreePtr = nativeDispatchEvent(callbackId, EVENT_PRESS, null)
    if (newTreePtr != 0L) {
        val newTree = buildTree(newTreePtr)
        currentTree.value = newTree
    }
}
```

No watcher thread. No condvars. No binary parsing. Simon, it's just function calls.

### 8.6 The I/O Problem (And Three Solutions)

Simon, here's the one catch. If PHP runs synchronously on a thread tied to the UI, and PHP does `Http::get('...')`, the UI freezes. JavaScript has the same problem — that's why everything in JS is async. PHP historically isn't async.

**Solution A: Split Rendering from I/O (Recommended)**

Keep a background PHP thread for I/O (HTTP, database, file operations). Run rendering synchronously on demand:

```
Background PHP thread:
  - Handles HTTP requests, DB queries, filesystem
  - Updates a shared state store (C struct or protected zval hash)
  - Signals "state changed" when new data arrives

UI thread (on demand):
  - Calls nphp_render(component, current_state)
  - PHP render function is PURE — reads state, returns tree
  - No I/O, no blocking, just computation
  - Returns zval* tree immediately
```

Simon, this is literally React's model. Components are pure functions of state. Side effects happen elsewhere. Your Blade components already work this way — `@foreach($users as $user)` doesn't make an HTTP request, it reads from a variable.

**Solution B: PHP Fibers (PHP 8.1+)**

Fibers let PHP yield mid-execution without blocking:

```c
zval* result = nphp_render(rt, "App", props);

if (nphp_is_suspended(rt)) {
    // PHP hit an I/O call and yielded
    // Dispatch I/O to background thread
    nphp_dispatch_io(rt, callback);
    return nullptr; // tell Kotlin "not ready yet, show loading state"
}

return result; // tree is ready
```

This is like React Suspense — if data isn't ready, the component suspends and shows a fallback.

**Solution C: Full Synchronous (Simple Apps)**

If your render path is purely computational (reading from in-memory state, no I/O during render), just run everything synchronously. A 200-node tree render in PHP takes <1ms. That's well within a 16ms frame budget.

The `nativephp_call` bridge functions (`Dialog.Alert`, `QrCode.Scan`, etc.) dispatch to native asynchronously anyway — the callback just triggers a synchronous re-render when the result arrives.

---

## 9. What We Delete

Simon, here's the satisfying part. If we build PHP-JSI, all of this goes away:

### Files Deleted

| File | Lines | What It Does Now |
|---|---|---|
| `nativephp_ui.h` | 302 | Protocol definitions, shared region struct, binary reader/writer |
| `nativephp_ui.c` | 984 | Tree/patch serialization, mmap lifecycle, condvar sync, shutdown |
| `NativeUITreeReader.kt` | 162 | V2 binary tree deserialization |
| `NativeUIPatchReader.kt` | 230 | NPP1 binary patch parsing, tree patching |
| **Total** | **~1,678** | |

### Code Heavily Simplified

| File | Current Lines | Estimated After | Reduction |
|---|---|---|---|
| `bridge_jni.cpp` (UI portion) | ~250 | ~60 | 75% |
| `NativeUIBridge.kt` | 287 | ~80 | 72% |
| `NativeUINode.kt` | 198 | ~100 (models stay) | 50% |

### Concepts Eliminated

- The 2.8MB mmap shared memory region
- The NPV2 binary tree wire format
- The NPP1 binary patch wire format
- The NPEV binary event wire format
- The 44-entry interned key table (both C and Kotlin copies)
- The 10-entry value type tag system
- `pthread_mutex_t` / `pthread_cond_t` with `PTHREAD_PROCESS_SHARED`
- The `npui-tree-watcher` daemon thread
- The 200ms shutdown sleep hack
- The `RTLD_NOLOAD` linker namespace workaround for `NativeUI_RegisterRegion`
- Java byte array allocation + `SetByteArrayRegion` copy per render
- All `atomic_load` / `atomic_store` / `atomic_fetch_add` synchronization
- The `tree_version` / `tree_version_ack` / `patch_version` / `patch_version_ack` versioning system

Simon, that's a lot of stuff we never have to debug again.

---

## 10. What We Build

### 10.1 New Files

| File | Est. Lines | Purpose |
|---|---|---|
| `nphp_runtime.h` | ~60 | PHP-JSI API declarations |
| `nphp_runtime.c` | ~200 | Embed SAPI init, `zend_call_function` wrappers, zval accessors |
| `nphp_bridge_jni.cpp` | ~80 | Thin JNI layer: zval pointer passthrough, event dispatch |

**Total new code: ~340 lines** replacing ~1,678 deleted + ~300 simplified = **net reduction of ~1,600+ lines.**

### 10.2 Modified Files

| File | Changes |
|---|---|
| `NativeUIBridge.kt` | Remove watcher thread, add synchronous dispatch methods |
| `NativeUIRenderer.kt` | Unchanged — Compose rendering stays the same |
| `NativeUINode.kt` | Keep data models, remove binary-specific constants |
| `nativephp.c` | Integrate with new runtime init instead of separate mmap init |
| `PHPBridge.kt` | Call `nphp_init()` instead of mmap setup |

### 10.3 New Capabilities We Get For Free

1. **Synchronous rendering** — No more eventual consistency between PHP state and displayed UI
2. **Direct debugging** — One call stack from tap to render, step through in a debugger
3. **No startup race** — No polling loop waiting for the mmap region to appear
4. **Clean shutdown** — No 200ms sleep, no race conditions, just `nphp_shutdown()`
5. **Simpler hot reload** — Call `nphp_render()` again, get new tree. Done.
6. **Per-component rendering** — Can call PHP to render just one subtree, not the whole page

---

## 11. Migration Path

Simon, we don't have to do this all at once. Here's a phased approach:

### Phase 1: DirectByteBuffer (1-2 days)

The absolute lowest-hanging fruit. Replace the byte array copy with a direct buffer:

```cpp
// bridge_jni.cpp: change nativeGetTreeBuffer()
// FROM:
jbyteArray result = env->NewByteArray(size);
env->SetByteArrayRegion(result, 0, size, (jbyte*)tree_buf);

// TO:
jobject result = env->NewDirectByteBuffer(tree_buf, size);
```

Add double-buffering to the tree region (PHP writes to A, Kotlin reads from B, swap on commit). This gives us zero-copy reads within the existing architecture.

**Effort:** Small. **Impact:** Eliminates the biggest per-render allocation.

### Phase 2: Synchronous Render Bridge (1-2 weeks)

Build `nphp_runtime.c` with the embed SAPI. Wire up `nphp_render()` to call Blade's render function synchronously. Add zval accessor JNI methods.

Run the new path in parallel with the existing mmap path. Feature-flag it. Compare render times.

**Effort:** Medium. **Impact:** Validates the core JSI hypothesis — is synchronous PHP rendering fast enough?

### Phase 3: Event Dispatch (1 week)

Replace the event mmap path with `nphp_dispatch()`. Button presses, text changes, etc. go through a synchronous JNI → C → PHP → zval* → JNI → Compose path.

At this point, the mmap region is only used as a fallback.

**Effort:** Medium. **Impact:** Completes the round-trip — rendering AND events are synchronous.

### Phase 4: Delete the Old Path (1-2 days)

Remove the mmap region, binary protocol, watcher thread, and all the synchronization code. Delete `nativephp_ui.h`, `nativephp_ui.c`, `NativeUITreeReader.kt`, `NativeUIPatchReader.kt`.

Celebrate by buying Simon a beer.

**Effort:** Small (just deleting). **Impact:** ~1,600 fewer lines to maintain. Forever.

---

## Appendix A: File Reference

All files discussed in this document, for Simon's convenience:

### PHP Extension (C)
- `~/AndroidStudioProjects/build-scripts/php-build/nativephp/nativephp_ui.h` — Protocol + region struct
- `~/AndroidStudioProjects/build-scripts/php-build/nativephp/nativephp_ui.c` — Serialization + lifecycle
- `~/AndroidStudioProjects/build-scripts/php-build/nativephp/nativephp.c` — God method + module entry
- `~/AndroidStudioProjects/build-scripts/php-build/nativephp/php_nativephp.h` — Module header
- `~/AndroidStudioProjects/build-scripts/php-build/nativephp/nativephp.stub.php` — PHP function declarations
- `~/AndroidStudioProjects/build-scripts/php-build/nativephp/nativephp_arginfo.h` — Generated arg info
- `~/AndroidStudioProjects/build-scripts/php-build/nativephp/config.m4` — Build config

### Android JNI Bridge (C++)
- `resources/androidstudio/app/src/main/cpp/bridge_jni.cpp`

### Android Kotlin (Rendering)
- `resources/androidstudio/.../ui/nativerender/NativeUIBridge.kt` — JNI bridge + watcher thread
- `resources/androidstudio/.../ui/nativerender/NativeUITreeReader.kt` — V2 binary parser
- `resources/androidstudio/.../ui/nativerender/NativeUIPatchReader.kt` — NPP1 parser + tree patching
- `resources/androidstudio/.../ui/nativerender/NativeUIRenderer.kt` — Compose renderer
- `resources/androidstudio/.../ui/nativerender/NativeUINode.kt` — Data models + constants
- `resources/androidstudio/.../ui/nativerender/NativeRendererRegistry.kt` — Renderer lookup

---

## Appendix B: React Native JSI References

For Simon's further reading:

- React Native Architecture Overview: `reactnative.dev/architecture/landing-page`
- Render Pipeline: `reactnative.dev/architecture/render-pipeline`
- Threading Model: `reactnative.dev/architecture/threading-model`
- JSI Source (Hermes): `github.com/facebook/hermes/blob/main/API/jsi/jsi/jsi.h`
- Nitro Modules (next-gen JSI bindings): `nitro.margelo.com`
- Memory Ownership Models (Callstack blog): `callstack.com/blog/memory-ownership-models-when-javascript-meets-native-code`

---

## Appendix C: Glossary for Simon

| Term | Meaning |
|---|---|
| **mmap** | Memory-mapped region. OS gives you a chunk of RAM both threads can see. |
| **condvar** | Condition variable. A thread sleeps on it until another thread signals it. |
| **atomic** | A variable that can be read/written by multiple threads without locks. |
| **zval** | Zend Value. PHP's internal representation of every variable. It's a C union. |
| **JNI** | Java Native Interface. The bridge between C/C++ and Java/Kotlin. |
| **JSI** | JavaScript Interface. React Native's C++ bridge to the JS engine. |
| **HostObject** | A C++ object that appears as a JS/PHP object. Property access dispatches to native code. |
| **SAPI** | Server API. The interface between PHP and its host environment (Apache, CLI, embed, etc). |
| **embed SAPI** | PHP's interface for embedding as a library in C/C++ applications. |
| **Fabric** | React Native's native rendering system (replaced the old "Paper" renderer). |
| **Shadow Tree** | React Native's intermediate UI tree that lives in C++ between JS and platform views. |
| **Hermes** | Meta's JavaScript engine for React Native. Written in C++. |
| **Yoga** | Meta's cross-platform flexbox layout engine. Written in C++. |
| **RTLD_NOLOAD** | dlopen flag: "give me a handle to an already-loaded .so without loading it again." |
| **linker namespace** | Android 7+ isolation that prevents dlsym from finding symbols across .so boundaries. |
| **NPV2** | Our binary tree format. Magic bytes: `0x4E505632`. |
| **NPP1** | Our binary patch format. Magic bytes: `0x4E505031`. |
| **NPEV** | Our binary event format. Magic bytes: `0x4E504556`. |

---

*Simon, if you've read this far: the beers are on me. But seriously, read it again. The binary protocol section especially. You're going to need to understand those magic bytes when things go wrong at 2am.*