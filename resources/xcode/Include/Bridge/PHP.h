#ifndef PHPBridge_h
#define PHPBridge_h

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

#endif
