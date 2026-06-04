#ifndef PHPBridge_h
#define PHPBridge_h

#include <stdint.h>

typedef void (*phpOutputCallback)(const char *);

void override_embed_module_output(phpOutputCallback callback);

void initialize_php_with_request(const char *post_data,
                                 const char *method,
                                 const char *uri);

// Persistent PHP Runtime
int  persistent_php_boot(const char *bootstrapPath);
const char *persistent_php_boot_error(void);
const char *persistent_php_dispatch(const char *method,
                                    const char *uri,
                                    const char *postData,
                                    const char *scriptPath,
                                    const char *cookieHeader,
                                    const char *contentType);
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

#endif
