#ifndef PHPBridge_h
#define PHPBridge_h

#include <stddef.h>
#include <stdint.h>

typedef void (*phpOutputCallback)(const char *);

void override_embed_module_output(phpOutputCallback callback);

void initialize_php_with_request(const char *post_data,
                                 const char *method,
                                 const char *uri);

// Persistent PHP Runtime
int  persistent_php_boot(const char *bootstrapPath);
const char *persistent_php_boot_error(void);
// Text-only dispatch, kept for older callers: the body stops at its first
// NUL and no request headers reach PHP. Use persistent_php_dispatch_bytes.
const char *persistent_php_dispatch(const char *method,
                                    const char *uri,
                                    const char *postData,
                                    const char *scriptPath,
                                    const char *cookieHeader,
                                    const char *contentType);

// Binary-safe dispatch. `body` is `body_len` bytes and may hold NULs; it may
// be NULL only when body_len is 0. `content_type` is the Content-Type that
// belongs to the body (multipart boundary included). `headers` is a block of
// "Name: value" lines joined by CRLF, `headers_len` bytes, or NULL and 0.
// Returns a malloc'd raw HTTP response (status line, headers, CRLF CRLF,
// body) of *out_len bytes, followed by one uncounted NUL. The caller frees it
// with free(). Returns NULL only when out of memory.
char *persistent_php_dispatch_bytes(const char *method,
                                    const char *uri,
                                    const char *body,
                                    size_t body_len,
                                    const char *content_type,
                                    const char *cookie_header,
                                    const char *headers,
                                    size_t headers_len,
                                    const char *script_path,
                                    size_t *out_len);
const char *persistent_php_artisan(const char *command);
void persistent_php_shutdown(void);
int  persistent_php_is_booted(void);
void persistent_php_save_context(void);
void persistent_php_restore_context(void);

// Queue Worker Runtime (separate TSRM context)
int  worker_php_boot(const char *bootstrapPath);
const char *worker_php_artisan(const char *command);
void worker_php_shutdown(void);
int  worker_php_is_booted(void);

// Ephemeral PHP Runtime (generic TSRM context — boot/run/shutdown per invocation)
// Used by plugins that need independent background PHP execution.
int  ephemeral_php_boot(const char *bootstrapPath);
const char *ephemeral_php_artisan(const char *command);
void ephemeral_php_shutdown(void);
int  ephemeral_php_is_booted(void);

// Async Task Lane — a pool of TSRM contexts for immediate background work
// (AsyncTask::dispatch()). Each slot is one worker thread with its own booted
// context; Swift's AsyncTaskExecutor pins each slot handle to a serial queue.
int  async_php_boot(const char *bootstrapPath);           // → slot handle ≥ 0, or negative error
const char *async_php_run(int handle, const char *taskId); // run native:async:run --id=<taskId> on this slot
void async_php_stop(int handle);

// Webview PHP Runtimes — one dedicated thread + TSRM context per embedded
// php-mode webview. The persistent lane is parked inside a native screen's
// event-loop dispatch, so it can never answer php:// requests from an
// embedded webview; these slots serve them concurrently instead.
int  webview_php_start(const char *bootstrapPath);   // → handle ≥ 0, or negative error
// Text-only request, kept for older callers: the body stops at its first NUL
// and only Cookie and Content-Type reach PHP. Use webview_php_request_bytes.
const char *webview_php_request(int handle, const char *method, const char *uri,
                                const char *cookieHeader, const char *postData,
                                const char *contentType, const char *scriptPath);
// Binary-safe request, same arguments and result as
// persistent_php_dispatch_bytes, on this webview's own context.
char *webview_php_request_bytes(int handle, const char *method, const char *uri,
                                const char *body, size_t body_len,
                                const char *content_type,
                                const char *cookie_header,
                                const char *headers, size_t headers_len,
                                const char *script_path,
                                size_t *out_len);
void webview_php_stop(int handle);

// Phase 0 — Element runtime instrumentation. Exported from the PHP nativephp
// extension (nphp_element.c, linked into libphp.a). Swift calls these
// directly via C interop; format_version mismatch must fail loud at region
// register time. See REFACTOR-native-ui-performance.md §5.4.
uint32_t nphp_get_format_version(void);
uint32_t nphp_get_runtime_flags(void);
void     nphp_set_runtime_flags(uint32_t flags);

// Phase 3 — active-buffer accessors. The acquire-load on `active_buf`
// inside these functions pairs with the producer's release-store in
// `nphp_element_publish` so the bridge always reads the buffer half
// that the most recent publish completed (§5.1). Swift calls these
// from `NativeElementBridge.postTreeUpdateFromRegion` in place of
// reading `flat_buffer` / `flat_buffer_size` directly via offsets.
uint8_t *nphp_get_active_flat_buffer(uint32_t *size_out);
uint8_t *nphp_get_active_prop_buffer(uint32_t *size_out);

// Native → PHP event producer. Swift hands the event body bytes here; the
// extension owns the event mutex, the event queue, and the header framing
// (single source of truth for the wire format). Replaces Swift poking the
// region's inline event buffer by offset, and lifts the 4KB payload cap.
//
// Returns 1 if the event was queued, 0 if it was dropped (no region, or the
// queue is over its backlog cap because PHP has stopped draining). Was void
// before format v4; ignoring the result is still fine, but a caller that
// needs to know its event will be delivered can now check.
int nphp_element_post_event(int type, int callback_id, int node_id,
                            const uint8_t *data, uint32_t data_len);

#endif
