/*
 * NativePHP Native UI — Shared Memory Region
 *
 * This header is included by both:
 *   - nativephp.c       (PHP extension, in libphp.so)
 *   - bridge_jni.cpp    (JNI bridge, in libphp_wrapper.so)
 *
 * Both libraries are loaded in the same process, so they
 * share this memory region through a global pointer.
 *
 * Data flow:
 *   PHP writes UI buffer  → Native reads and renders views
 *   Native writes events  → PHP reads and dispatches to callbacks
 *
 * Synchronization:
 *   - pthread mutex + condition variable for event signaling
 *   - Atomic version counters for lock-free UI reads
 */

#ifndef NATIVEPHP_UI_SHARED_H
#define NATIVEPHP_UI_SHARED_H

#include <stdint.h>
#include <stdatomic.h>
#include <pthread.h>

/* Buffer sizes */
#define NPUI_TREE_BUFFER_SIZE    (2 * 1024 * 1024)   /* 2 MB for UI tree */
#define NPUI_EVENT_BUFFER_SIZE   (256 * 1024)          /* 256 KB for events */
#define NPUI_PATCH_BUFFER_SIZE   (512 * 1024)          /* 512 KB for patches */

/* Magic value to verify region is initialized */
#define NPUI_MAGIC 0x4E505549  /* "NPUI" */

/*
 * The shared memory region.
 *
 * Layout in memory:
 *   [npui_shared_region_t header]
 *   [tree_buffer: NPUI_TREE_BUFFER_SIZE bytes]
 *   [event_buffer: NPUI_EVENT_BUFFER_SIZE bytes]
 *   [patch_buffer: NPUI_PATCH_BUFFER_SIZE bytes]
 *
 * Total: sizeof(header) + ~2.75 MB
 */
typedef struct npui_shared_region {
    uint32_t magic;

    /* ── UI Tree (PHP → Native) ─────────────────────── */

    /* Offset from start of region to tree buffer */
    uint32_t tree_offset;
    /* Size of the current FlatBuffer in tree_buffer */
    _Atomic uint32_t tree_size;
    /* Incremented by PHP after writing a new tree */
    _Atomic uint32_t tree_version;
    /* Last version native has processed */
    _Atomic uint32_t tree_version_ack;

    /* ── Patches (PHP → Native) ─────────────────────── */

    uint32_t patch_offset;
    _Atomic uint32_t patch_size;
    _Atomic uint32_t patch_version;
    _Atomic uint32_t patch_version_ack;

    /* ── Events (Native → PHP) ──────────────────────── */

    uint32_t event_offset;
    _Atomic uint32_t event_size;
    _Atomic uint32_t event_count;

    /* ── Synchronization ────────────────────────────── */

    /* PHP waits on this when blocked in nativephp_ui_wait_event() */
    pthread_mutex_t event_mutex;
    pthread_cond_t  event_cond;

    /* Native waits on this when blocked waiting for a new tree */
    pthread_mutex_t tree_mutex;
    pthread_cond_t  tree_cond;

    /* ── State flags ────────────────────────────────── */

    /* Set to 1 when PHP calls nativephp_ui_shutdown() */
    _Atomic uint32_t shutdown;

    /* Set to 1 when the UI run loop is active */
    _Atomic uint32_t running;

} npui_shared_region_t;

/*
 * Total size needed for mmap allocation.
 */
#define NPUI_REGION_TOTAL_SIZE ( \
    sizeof(npui_shared_region_t) + \
    NPUI_TREE_BUFFER_SIZE + \
    NPUI_EVENT_BUFFER_SIZE + \
    NPUI_PATCH_BUFFER_SIZE \
)

/*
 * Helper macros to get buffer pointers from the region.
 */
#define NPUI_TREE_BUFFER(region) \
    ((uint8_t*)(region) + (region)->tree_offset)

#define NPUI_EVENT_BUFFER(region) \
    ((uint8_t*)(region) + (region)->event_offset)

#define NPUI_PATCH_BUFFER(region) \
    ((uint8_t*)(region) + (region)->patch_offset)

/*
 * Initialize a shared region. Call once from the PHP extension's MINIT.
 * The region pointer must point to NPUI_REGION_TOTAL_SIZE bytes of
 * memory (mmap'd or malloc'd).
 */
static inline void npui_region_init(npui_shared_region_t *region) {
    region->magic = NPUI_MAGIC;

    /* Calculate buffer offsets (right after the header struct) */
    region->tree_offset  = sizeof(npui_shared_region_t);
    region->patch_offset = region->tree_offset + NPUI_TREE_BUFFER_SIZE;
    region->event_offset = region->patch_offset + NPUI_PATCH_BUFFER_SIZE;

    /* Zero out sizes and versions */
    atomic_store(&region->tree_size, 0);
    atomic_store(&region->tree_version, 0);
    atomic_store(&region->tree_version_ack, 0);
    atomic_store(&region->patch_size, 0);
    atomic_store(&region->patch_version, 0);
    atomic_store(&region->patch_version_ack, 0);
    atomic_store(&region->event_size, 0);
    atomic_store(&region->event_count, 0);
    atomic_store(&region->shutdown, 0);
    atomic_store(&region->running, 0);

    /* Initialize synchronization primitives */
    pthread_mutexattr_t mattr;
    pthread_mutexattr_init(&mattr);
    pthread_mutexattr_setpshared(&mattr, PTHREAD_PROCESS_SHARED);
    pthread_mutex_init(&region->event_mutex, &mattr);
    pthread_mutex_init(&region->tree_mutex, &mattr);
    pthread_mutexattr_destroy(&mattr);

    pthread_condattr_t cattr;
    pthread_condattr_init(&cattr);
    pthread_condattr_setpshared(&cattr, PTHREAD_PROCESS_SHARED);
    pthread_cond_init(&region->event_cond, &cattr);
    pthread_cond_init(&region->tree_cond, &cattr);
    pthread_condattr_destroy(&cattr);
}

/*
 * Check if a region pointer is valid.
 */
static inline int npui_region_valid(npui_shared_region_t *region) {
    return region != NULL && region->magic == NPUI_MAGIC;
}

#endif /* NATIVEPHP_UI_SHARED_H */