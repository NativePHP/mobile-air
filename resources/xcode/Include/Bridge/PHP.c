#include "php_embed.h"
#include "PHP.h"
#include <pthread.h>
#include <pthread/qos.h>
#include <signal.h>
#include <string.h>
#include <stdlib.h>
#include <stdio.h>
#include <errno.h>
#include <time.h>
#include <zend_exceptions.h>

static phpOutputCallback swiftOutputCallback = NULL;

// ── Thread-local output capture ─────────────────────
// Each PHP thread (persistent, worker) gets its own output buffer via
// pthread_key_t, preventing cross-thread corruption.

#define BUFFER_CHUNK_SIZE (256 * 1024)
#define MAX_BUFFER_SIZE   (16 * 1024 * 1024)

typedef struct {
    char  *output;
    size_t length;
    size_t capacity;
} php_output_buffer_t;

static pthread_key_t  g_output_key;
static pthread_once_t g_output_key_once = PTHREAD_ONCE_INIT;

static void destroy_output_buffer(void *ptr) {
    if (!ptr) return;
    php_output_buffer_t *buf = (php_output_buffer_t *)ptr;
    free(buf->output);
    free(buf);
}

static void create_output_key(void) {
    pthread_key_create(&g_output_key, destroy_output_buffer);
}

static php_output_buffer_t *get_thread_output_buffer(void) {
    pthread_once(&g_output_key_once, create_output_key);
    php_output_buffer_t *buf = (php_output_buffer_t *)pthread_getspecific(g_output_key);
    if (!buf) {
        buf = (php_output_buffer_t *)calloc(1, sizeof(php_output_buffer_t));
        if (buf) {
            buf->capacity = BUFFER_CHUNK_SIZE;
            buf->output = (char *)malloc(buf->capacity);
            if (buf->output) buf->output[0] = '\0';
            pthread_setspecific(g_output_key, buf);
        }
    }
    return buf;
}

static void clear_output_buffer(void) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    if (!buf) return;
    free(buf->output);
    buf->capacity = BUFFER_CHUNK_SIZE;
    buf->length   = 0;
    buf->output   = (char *)malloc(buf->capacity);
    if (buf->output) buf->output[0] = '\0';
}

static char *get_collected_output(void) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    return buf ? buf->output : NULL;
}

static void append_output(const char *str, size_t len) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    if (!buf || !buf->output) {
        clear_output_buffer();
        buf = get_thread_output_buffer();
        if (!buf || !buf->output) return;
    }

    if (buf->length + len + 1 > buf->capacity) {
        size_t needed = buf->capacity;
        while (needed < buf->length + len + 1) {
            needed += BUFFER_CHUNK_SIZE;
        }
        if (needed > MAX_BUFFER_SIZE) return;

        char *new_buf = (char *)realloc(buf->output, needed);
        if (!new_buf) return;
        buf->output   = new_buf;
        buf->capacity = needed;
    }

    memcpy(buf->output + buf->length, str, len);
    buf->length += len;
    buf->output[buf->length] = '\0';
}

size_t capture_php_output(const char *str, size_t str_length) {
    if (str_length == 0) return 0;

    // Forward to Swift callback (legacy per-request mode)
    if (swiftOutputCallback) {
        char *buffer = malloc(str_length + 1);
        if (buffer) {
            memcpy(buffer, str, str_length);
            buffer[str_length] = '\0';
            swiftOutputCallback(buffer);
            free(buffer);
        }
    }

    // Accumulate in thread-local buffer
    append_output(str, str_length);

    return str_length;
}

void override_embed_module_output(phpOutputCallback callback) {
    swiftOutputCallback = callback;
    php_embed_module.ub_write = capture_php_output;
}

void initialize_php_with_request(const char *post_data,
                                 const char *method,
                                 const char *uri) {
    if (strcmp(method, "POST") == 0) {
        size_t post_data_length = strlen(post_data);

        php_stream *mem_stream = php_stream_memory_create(TEMP_STREAM_DEFAULT);
        php_stream_write(mem_stream, post_data, post_data_length);

        SG(request_info).request_body   = mem_stream;
        SG(request_info).request_method = "POST";
        SG(request_info).content_type   = "application/x-www-form-urlencoded";
        SG(request_info).content_length = post_data_length;
    }
}

// ── Header handler ──────────────────────────────────

static int ios_header_handler(sapi_header_struct *sapi_header,
                              sapi_header_op_enum op,
                              sapi_headers_struct *sapi_headers) {
    if (op == SAPI_HEADER_DELETE_ALL || op == SAPI_HEADER_DELETE) {
        return 0;
    }
    // Accumulate headers into output buffer so Swift can parse them
    if (sapi_header && sapi_header->header) {
        // Headers are emitted by the PHP dispatch code itself — no action needed here
    }
    return 0;
}

// ── Dedicated PHP Worker Thread ─────────────────────
// All PHP work runs on a single dedicated pthread, mirroring Android's
// phpExecutor. This guarantees TSRM thread-local storage is always valid
// since php_embed_init() and all subsequent PHP calls share the same thread.
//
// Uses dispatch_semaphore_t for synchronization (simpler and more reliable
// than pthread condvars on Apple platforms).

#include <dispatch/dispatch.h>

// Dispatch parameters
typedef struct {
    const char *method;
    const char *uri;
    const char *postData;
    const char *scriptPath;
    const char *cookieHeader;
    const char *contentType;
} dispatch_params_t;

// Work item types
typedef enum {
    PHP_WORK_DISPATCH,
    PHP_WORK_ARTISAN,
    PHP_WORK_SHUTDOWN
} php_work_type_t;

// Synchronization: caller posts to work_sem, worker posts to done_sem
static dispatch_semaphore_t php_work_sem = NULL;
static dispatch_semaphore_t php_done_sem = NULL;

static php_work_type_t      php_work_type;
static const char          *php_work_str_arg    = NULL;
static dispatch_params_t    php_work_dispatch_params;
static int                  php_work_int_result  = 0;
static char                *php_work_str_result  = NULL;

static int persistent_initialized = 0;
static int worker_thread_alive = 0;
static char *persistent_boot_error = NULL;

// ── Persistent boot gate ─────────────────────────────────────────────────
// Serializes startup: worker/ephemeral runtimes wait until php_embed_init
// has finished in php_worker_main before they call ts_resource and
// php_module_startup. Without this gate, a cold-launch race (e.g. iOS BGTask
// handler firing while the main thread is mid-boot) can enter SAPI init
// before sapi_startup() has completed, crashing in sapi_initialize_empty_request
// (NULL write to sapi_globals).
typedef enum {
    PERSISTENT_BOOT_NEVER_STARTED = 0,
    PERSISTENT_BOOT_IN_PROGRESS,
    PERSISTENT_BOOT_SUCCEEDED,
    PERSISTENT_BOOT_FAILED,
} persistent_boot_state_t;

static persistent_boot_state_t g_persistent_boot_state = PERSISTENT_BOOT_NEVER_STARTED;
static pthread_mutex_t g_persistent_boot_mutex = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t  g_persistent_boot_cond  = PTHREAD_COND_INITIALIZER;

static void set_persistent_boot_state(persistent_boot_state_t state) {
    pthread_mutex_lock(&g_persistent_boot_mutex);
    g_persistent_boot_state = state;
    pthread_cond_broadcast(&g_persistent_boot_cond);
    pthread_mutex_unlock(&g_persistent_boot_mutex);
}

// Wait for persistent boot to leave IN_PROGRESS.
// Returns 0 on SUCCEEDED, -1 on timeout, -2 on FAILED or NEVER_STARTED.
// iOS ephemeral/worker runtimes require a successful persistent boot to
// piggyback on; NEVER_STARTED is a caller-ordering error here.
static int wait_for_persistent_boot(int timeout_seconds) {
    struct timespec deadline;
    clock_gettime(CLOCK_REALTIME, &deadline);
    deadline.tv_sec += timeout_seconds;

    pthread_mutex_lock(&g_persistent_boot_mutex);
    while (g_persistent_boot_state == PERSISTENT_BOOT_IN_PROGRESS) {
        int rc = pthread_cond_timedwait(&g_persistent_boot_cond, &g_persistent_boot_mutex, &deadline);
        if (rc == ETIMEDOUT) {
            pthread_mutex_unlock(&g_persistent_boot_mutex);
            return -1;
        }
    }
    int result = (g_persistent_boot_state == PERSISTENT_BOOT_SUCCEEDED) ? 0 : -2;
    pthread_mutex_unlock(&g_persistent_boot_mutex);
    return result;
}

// Forward declarations
static void do_dispatch(const dispatch_params_t *params);
static void do_artisan(const char *command);
static void do_shutdown(void);

static void setup_persistent_sapi(void) {
    php_embed_module.ub_write       = capture_php_output;
    php_embed_module.phpinfo_as_text = 1;
    php_embed_module.php_ini_ignore  = 0;
    php_embed_module.ini_entries     = "output_buffering=4096\n"
                                       "implicit_flush=0\n"
                                       "display_errors=1\n"
                                       "error_reporting=E_ALL\n";
    php_embed_module.header_handler  = ios_header_handler;
}

// ── Worker thread ───────────────────────────────────
// Boots PHP, then loops processing work items.

static void *php_worker_main(void *arg) {
    const char *bootstrapPath = (const char *)arg;

    fprintf(stderr, "PHP-WORKER: thread started tid=%p, booting PHP...\n", (void *)pthread_self());
    fflush(stderr);

    // ── Boot PHP on this thread ──
    clear_output_buffer();

    setenv("NATIVEPHP_RUNNING", "true", 1);
    setenv("NATIVEPHP_PLATFORM", "ios", 1);
    setenv("APP_URL", "php://127.0.0.1", 1);
    setenv("ASSET_URL", "php://127.0.0.1/_assets/", 1);

    setup_persistent_sapi();

    if (php_embed_init(0, NULL) != SUCCESS) {
        fprintf(stderr, "PHP-WORKER: php_embed_init FAILED\n");
        fflush(stderr);
        php_work_int_result = -1;
        worker_thread_alive = 0;
        set_persistent_boot_state(PERSISTENT_BOOT_FAILED);
        dispatch_semaphore_signal(php_done_sem);
        return NULL;
    }

    fprintf(stderr, "PHP-WORKER: php_embed_init SUCCESS\n");
    fflush(stderr);

    sapi_module.header_handler = ios_header_handler;

    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();


    fprintf(stderr, "PHP-WORKER: bootstrap script executed\n");
    fflush(stderr);

    // Save bootstrap output BEFORE clearing (contains error messages if boot failed)
    char *bootstrap_output = NULL;
    {
        char *raw = get_collected_output();
        if (raw && raw[0] != '\0') {
            bootstrap_output = strdup(raw);
            fprintf(stderr, "PHP-WORKER: bootstrap output: %.500s\n", bootstrap_output);
            fflush(stderr);
        }
    }

    // Verify PHP-level boot succeeded (Runtime::$booted must be true)
    clear_output_buffer();
    int boot_ok = 0;
    zend_first_try {
        zend_eval_string("echo \\Native\\Mobile\\Runtime::isBooted() ? '1' : '0';", NULL, "boot_check");
    } zend_end_try();

    char *check_output = get_collected_output();
    if (check_output && check_output[0] == '1') {
        boot_ok = 1;
    }

    if (boot_ok) {
        persistent_initialized = 1;
        php_work_int_result = 0;
        free(bootstrap_output);
        // Clear any previous boot error
        if (persistent_boot_error) { free(persistent_boot_error); persistent_boot_error = NULL; }
        fprintf(stderr, "PHP-WORKER: bootstrap complete, Runtime::isBooted() confirmed\n");
        fflush(stderr);
    } else {
        // Store bootstrap output as boot error for Swift to retrieve
        if (persistent_boot_error) { free(persistent_boot_error); }
        persistent_boot_error = bootstrap_output;  // transfer ownership
        fprintf(stderr, "PHP-WORKER: bootstrap ran but Runtime::isBooted() is false, shutting down\n");
        fflush(stderr);
        persistent_initialized = 0;
        php_work_int_result = -2;

        // Clean up PHP so a fresh boot can be attempted
        sigset_t mask, oldmask;
        sigfillset(&mask);
        pthread_sigmask(SIG_BLOCK, &mask, &oldmask);
        php_embed_shutdown();
        pthread_sigmask(SIG_SETMASK, &oldmask, NULL);

        worker_thread_alive = 0;
        set_persistent_boot_state(PERSISTENT_BOOT_FAILED);
        dispatch_semaphore_signal(php_done_sem);
        return NULL;  // Exit thread — do NOT enter work loop
    }

    // Release any threads waiting to piggyback on the persistent runtime
    set_persistent_boot_state(PERSISTENT_BOOT_SUCCEEDED);

    // Signal boot complete
    dispatch_semaphore_signal(php_done_sem);

    // ── Work loop ──
    while (1) {
        dispatch_semaphore_wait(php_work_sem, DISPATCH_TIME_FOREVER);

        switch (php_work_type) {
            case PHP_WORK_DISPATCH:
                do_dispatch(&php_work_dispatch_params);
                break;
            case PHP_WORK_ARTISAN:
                do_artisan(php_work_str_arg);
                break;
            case PHP_WORK_SHUTDOWN:
                do_shutdown();
                break;
        }

        dispatch_semaphore_signal(php_done_sem);
    }

    return NULL;
}

// ── Work handlers (all run on the PHP worker thread) ──

static void do_dispatch(const dispatch_params_t *params) {
    if (!persistent_initialized) {
        php_work_str_result = strdup("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPersistent runtime not initialized.");
        return;
    }

    clear_output_buffer();

    // Set env vars for this request
    setenv("REQUEST_URI", params->uri, 1);
    setenv("REQUEST_METHOD", params->method, 1);
    setenv("SCRIPT_FILENAME", params->scriptPath, 1);
    setenv("PHP_SELF", "/native.php", 1);
    setenv("HTTP_HOST", "127.0.0.1", 1);
    setenv("APP_URL", "php://127.0.0.1", 1);
    setenv("ASSET_URL", "php://127.0.0.1/_assets/", 1);
    setenv("NATIVEPHP_RUNNING", "true", 1);
    setenv("NATIVEPHP_PLATFORM", "ios", 1);

    // Query string
    const char *query_start = strchr(params->uri, '?');
    if (query_start && strlen(query_start + 1) > 0) {
        setenv("QUERY_STRING", query_start + 1, 1);
    } else {
        unsetenv("QUERY_STRING");
    }

    // Cookie header
    if (params->cookieHeader && strlen(params->cookieHeader) > 0) {
        setenv("HTTP_COOKIE", params->cookieHeader, 1);
    } else {
        unsetenv("HTTP_COOKIE");
    }

    // Reset SAPI state — safe because we're on the PHP thread with valid TSRM
    SG(headers_sent) = 0;
    SG(post_read) = 0;
    SG(read_post_bytes) = 0;
    SG(request_info).request_method = params->method;
    SG(request_info).request_uri = (char *)params->uri;
    SG(request_info).proto_num = 1001;

    memset(&SG(sapi_headers), 0, sizeof(sapi_headers_struct));
    SG(sapi_headers).http_response_code = 200;
    zend_llist_init(&SG(sapi_headers).headers, sizeof(sapi_header_struct), NULL, 0);

    // POST data → php://input
    if (params->postData && strlen(params->postData) > 0) {
        php_stream *post_stream = php_stream_memory_create(TEMP_STREAM_DEFAULT);
        if (post_stream) {
            php_stream_write(post_stream, params->postData, strlen(params->postData));
            php_stream_seek(post_stream, 0, SEEK_SET);

            if (SG(request_info).request_body) {
                php_stream_close(SG(request_info).request_body);
            }
            SG(request_info).request_body = post_stream;
            SG(request_info).content_length = strlen(params->postData);

            if (params->contentType && strstr(params->contentType, "json")) {
                SG(request_info).content_type = "application/json";
            } else {
                SG(request_info).content_type = "application/x-www-form-urlencoded";
            }
        }
    } else {
        if (SG(request_info).request_body) {
            php_stream_close(SG(request_info).request_body);
            SG(request_info).request_body = NULL;
        }
        SG(request_info).content_length = 0;
    }

    // Build dispatch eval code — same pattern as Android
    static char eval_code[8192];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    while (ob_get_level() > 0) { ob_end_clean(); }\n"
        "\n"
        "    foreach ($_SERVER as $__k => $__v) {\n"
        "        if (str_starts_with($__k, 'HTTP_') || $__k === 'CONTENT_TYPE' || $__k === 'CONTENT_LENGTH') {\n"
        "            unset($_SERVER[$__k]);\n"
        "        }\n"
        "    }\n"
        "\n"
        "    $_SERVER['REQUEST_METHOD'] = '%s';\n"
        "    $_SERVER['REQUEST_URI'] = '%s';\n"
        "    $_SERVER['SCRIPT_FILENAME'] = '%s';\n"
        "    $_SERVER['PHP_SELF'] = '/native.php';\n"
        "    $_SERVER['HTTP_HOST'] = '127.0.0.1';\n"
        "    $_SERVER['SERVER_NAME'] = '127.0.0.1';\n"
        "    $_SERVER['SERVER_PORT'] = '80';\n"
        "    $_SERVER['APP_URL'] = 'php://127.0.0.1';\n"
        "    $_SERVER['NATIVEPHP_RUNNING'] = 'true';\n"
        "    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';\n"
        "\n"
        "    foreach (getenv() as $__k => $__v) {\n"
        "        $_SERVER[$__k] = $__v;\n"
        "    }\n"
        "\n"
        "    if (isset($_SERVER['HTTP_CONTENT_TYPE'])) {\n"
        "        $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];\n"
        "    }\n"
        "    if (isset($_SERVER['HTTP_CONTENT_LENGTH'])) {\n"
        "        $_SERVER['CONTENT_LENGTH'] = $_SERVER['HTTP_CONTENT_LENGTH'];\n"
        "    }\n"
        "\n"
        "    $__qpos = strpos($_SERVER['REQUEST_URI'], '?');\n"
        "    if ($__qpos !== false) {\n"
        "        $_SERVER['QUERY_STRING'] = substr($_SERVER['REQUEST_URI'], $__qpos + 1);\n"
        "    } else {\n"
        "        $_SERVER['QUERY_STRING'] = '';\n"
        "    }\n"
        "\n"
        "    $_GET = [];\n"
        "    $_POST = [];\n"
        "    $_COOKIE = [];\n"
        "    $_FILES = [];\n"
        "    $_REQUEST = [];\n"
        "\n"
        "    if (isset($_SERVER['HTTP_COOKIE']) && $_SERVER['HTTP_COOKIE'] !== '') {\n"
        "        foreach (explode('; ', $_SERVER['HTTP_COOKIE']) as $__pair) {\n"
        "            $__parts = explode('=', $__pair, 2);\n"
        "            if (count($__parts) === 2) {\n"
        "                $_COOKIE[$__parts[0]] = urldecode($__parts[1]);\n"
        "            }\n"
        "        }\n"
        "    }\n"
        "\n"
        "    if ($_SERVER['QUERY_STRING'] !== '') {\n"
        "        parse_str($_SERVER['QUERY_STRING'], $_GET);\n"
        "    }\n"
        "\n"
        "    // Parse POST body into $_POST for form-urlencoded requests.\n"
        "    // php://input is set up by the C layer but $_POST was cleared above.\n"
        "    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) {\n"
        "        $__rawInput = file_get_contents('php://input');\n"
        "        if ($__rawInput !== false && $__rawInput !== '') {\n"
        "            $__ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';\n"
        "            if (stripos($__ct, 'application/x-www-form-urlencoded') !== false) {\n"
        "                parse_str($__rawInput, $_POST);\n"
        "            }\n"
        "            $_REQUEST = array_merge($_GET, $_POST, $_COOKIE);\n"
        "        }\n"
        "    }\n"
        "\n"
        "    $__response = \\Native\\Mobile\\Runtime::dispatch(\n"
        "        \\Illuminate\\Http\\Request::capture()\n"
        "    );\n"
        "    $__code = $__response->getStatusCode();\n"
        "    $__status = \\Symfony\\Component\\HttpFoundation\\Response::$statusTexts[$__code] ?? 'OK';\n"
        "    echo \"HTTP/1.1 {$__code} {$__status}\\r\\n\";\n"
        "    foreach ($__response->headers->all() as $__name => $__values) {\n"
        "        foreach ($__values as $__value) {\n"
        "            echo \"{$__name}: {$__value}\\r\\n\";\n"
        "        }\n"
        "    }\n"
        "    echo \"\\r\\n\";\n"
        "    $__response->sendContent();\n"
        "} catch (\\Throwable $e) {\n"
        "    echo \"HTTP/1.1 500 Internal Server Error\\r\\n\";\n"
        "    echo \"Content-Type: text/plain\\r\\n\\r\\n\";\n"
        "    echo 'Persistent dispatch error: ' . $e->getMessage() . \"\\n\";\n"
        "    echo $e->getTraceAsString();\n"
        "}\n",
        params->method, params->uri, params->scriptPath);

    zend_first_try {
        zend_eval_string(eval_code, NULL, "persistent_dispatch");
    } zend_end_try();

    char *out = get_collected_output();
    php_work_str_result = out ? strdup(out) : strdup("");
}

static void do_artisan(const char *command) {
    if (!persistent_initialized) {
        php_work_str_result = strdup("Persistent runtime not initialized.");
        return;
    }

    clear_output_buffer();

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);

    char eval_code[4096];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    echo \\Native\\Mobile\\Runtime::artisan('%s');\n"
        "} catch (\\Throwable $e) {\n"
        "    echo 'Artisan error: ' . $e->getMessage();\n"
        "}\n",
        command);

    zend_first_try {
        zend_eval_string(eval_code, NULL, "persistent_artisan");
    } zend_end_try();

    char *out = get_collected_output();
    php_work_str_result = out ? strdup(out) : strdup("");

    unsetenv("APP_RUNNING_IN_CONSOLE");
}

static void do_shutdown(void) {
    if (!persistent_initialized) return;

    clear_output_buffer();

    zend_first_try {
        zend_eval_string(
            "\\Native\\Mobile\\Runtime::shutdown();",
            NULL, "persistent_shutdown");
    } zend_end_try();

    sigset_t mask, oldmask;
    sigfillset(&mask);
    pthread_sigmask(SIG_BLOCK, &mask, &oldmask);
    php_embed_shutdown();
    pthread_sigmask(SIG_SETMASK, &oldmask, NULL);

    persistent_initialized = 0;
    worker_thread_alive = 0;
    // After shutdown the per-thread TSRM/SAPI state is gone; reset the gate
    // so a later ephemeral/worker caller can't wrongly take the hot path.
    set_persistent_boot_state(PERSISTENT_BOOT_NEVER_STARTED);
}

// ── Public API (called from Swift, dispatches to PHP thread) ──

// Helper: submit work and wait for completion
static void submit_and_wait(php_work_type_t type) {
    dispatch_semaphore_signal(php_work_sem);
    dispatch_semaphore_wait(php_done_sem, DISPATCH_TIME_FOREVER);
}

int persistent_php_boot(const char *bootstrapPath) {
    if (persistent_initialized) {
        fprintf(stderr, "persistent_php_boot: already initialized, skipping\n");
        fflush(stderr);
        return 0;
    }

    if (worker_thread_alive) {
        fprintf(stderr, "persistent_php_boot: worker thread still alive, cannot re-boot\n");
        fflush(stderr);
        return -3;
    }

    fprintf(stderr, "persistent_php_boot: creating worker thread\n");
    fflush(stderr);

    php_work_sem = dispatch_semaphore_create(0);
    php_done_sem = dispatch_semaphore_create(0);

    // Flip the gate BEFORE pthread_create so any ephemeral/worker caller that
    // arrives before php_worker_main runs will wait, not race-past.
    set_persistent_boot_state(PERSISTENT_BOOT_IN_PROGRESS);

    // Create the worker thread — it boots PHP immediately, then enters work loop
    pthread_t thread;
    pthread_attr_t attr;
    pthread_attr_init(&attr);
    pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
    pthread_attr_setstacksize(&attr, 8 * 1024 * 1024);
    pthread_attr_set_qos_class_np(&attr, QOS_CLASS_USER_INITIATED, 0);

    int rc = pthread_create(&thread, &attr, php_worker_main, (void *)bootstrapPath);
    pthread_attr_destroy(&attr);

    if (rc != 0) {
        fprintf(stderr, "persistent_php_boot: pthread_create FAILED: %d\n", rc);
        fflush(stderr);
        return -1;
    }

    worker_thread_alive = 1;

    fprintf(stderr, "persistent_php_boot: waiting for boot to complete...\n");
    fflush(stderr);

    // Block until worker finishes booting
    dispatch_semaphore_wait(php_done_sem, DISPATCH_TIME_FOREVER);

    fprintf(stderr, "persistent_php_boot: done, result=%d\n", php_work_int_result);
    fflush(stderr);

    return php_work_int_result;
}

const char *persistent_php_dispatch(const char *method,
                                    const char *uri,
                                    const char *postData,
                                    const char *scriptPath,
                                    const char *cookieHeader,
                                    const char *contentType) {
    if (!persistent_initialized) {
        return strdup("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nNot booted.");
    }

    php_work_type = PHP_WORK_DISPATCH;
    php_work_dispatch_params = (dispatch_params_t){
        .method = method,
        .uri = uri,
        .postData = postData,
        .scriptPath = scriptPath,
        .cookieHeader = cookieHeader,
        .contentType = contentType
    };
    php_work_str_result = NULL;

    submit_and_wait(PHP_WORK_DISPATCH);
    return php_work_str_result;
}

const char *persistent_php_artisan(const char *command) {
    if (!persistent_initialized) {
        return strdup("Not booted.");
    }

    php_work_type = PHP_WORK_ARTISAN;
    php_work_str_arg = command;
    php_work_str_result = NULL;

    submit_and_wait(PHP_WORK_ARTISAN);
    return php_work_str_result;
}

void persistent_php_shutdown(void) {
    if (!persistent_initialized) return;
    php_work_type = PHP_WORK_SHUTDOWN;
    submit_and_wait(PHP_WORK_SHUTDOWN);
}

int persistent_php_is_booted(void) {
    return persistent_initialized;
}

const char *persistent_php_boot_error(void) {
    return persistent_boot_error ? persistent_boot_error : "";
}

// Legacy stubs — kept for header compatibility
void persistent_php_save_context(void) {}
void persistent_php_restore_context(void) {}

// ============================================================================
// Queue Worker Runtime — separate TSRM context on its own pthread
// ============================================================================
// Mirrors Android's PHPQueueWorker: boots a second PHP interpreter context
// on a dedicated thread. The worker has its own TSRM thread-local storage
// so it never contends with the persistent runtime's PHP thread.

static int worker_initialized = 0;
static pthread_mutex_t g_worker_mutex = PTHREAD_MUTEX_INITIALIZER;

// Worker thread synchronization (same semaphore pattern as persistent runtime)
static dispatch_semaphore_t worker_work_sem = NULL;
static dispatch_semaphore_t worker_done_sem = NULL;

typedef enum {
    WORKER_WORK_ARTISAN,
    WORKER_WORK_SHUTDOWN
} worker_work_type_t;

static worker_work_type_t   worker_work_type;
static const char           *worker_work_str_arg   = NULL;
static int                   worker_work_int_result = 0;
static char                 *worker_work_str_result = NULL;

// ── Worker TSRM init/shutdown ───────────────────────
// Allocates a new TSRM context for the worker thread without calling
// php_embed_init() again (TSRM is already started by the persistent runtime).

static int worker_embed_init(void) {
    fprintf(stderr, "WORKER: allocating TSRM context\n");
    fflush(stderr);

    // Allocate thread-local TSRM storage for this thread
    ts_resource(0);

    // Configure SAPI (uses thread-local ub_write)
    setup_persistent_sapi();

    // php_module_startup() is guarded internally — won't re-init modules,
    // but will call sapi_activate() for this thread's context
    if (php_embed_module.startup(&php_embed_module) == FAILURE) {
        fprintf(stderr, "WORKER: module startup FAILED\n");
        fflush(stderr);
        return FAILURE;
    }

    // Initialize request for this thread (executor, compiler globals)
    if (php_request_startup() == FAILURE) {
        fprintf(stderr, "WORKER: request startup FAILED\n");
        fflush(stderr);
        return FAILURE;
    }

    fprintf(stderr, "WORKER: TSRM context ready\n");
    fflush(stderr);
    return SUCCESS;
}

static void worker_embed_shutdown(void) {
    fprintf(stderr, "WORKER: cleaning up TSRM context\n");
    fflush(stderr);
    php_request_shutdown(NULL);
    ts_free_thread();
    fprintf(stderr, "WORKER: TSRM context freed\n");
    fflush(stderr);
}

// ── Worker work handlers ────────────────────────────

static void do_worker_artisan(const char *command) {
    if (!worker_initialized) {
        worker_work_str_result = strdup("Worker runtime not initialized.");
        return;
    }

    clear_output_buffer();

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);
    setenv("PHP_SELF", "artisan.php", 1);

    char eval_code[4096];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    $_SERVER['PHP_SELF'] = 'artisan.php';\n"
        "    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';\n"
        "    echo \\Native\\Mobile\\Runtime::artisan('%s');\n"
        "} catch (\\Throwable $e) {\n"
        "    echo 'Worker artisan error: ' . $e->getMessage();\n"
        "}\n",
        command);

    zend_first_try {
        zend_eval_string(eval_code, NULL, "worker_artisan");
    } zend_end_try();

    setenv("APP_RUNNING_IN_CONSOLE", "false", 1);

    char *out = get_collected_output();
    worker_work_str_result = out ? strdup(out) : strdup("");
}

static void do_worker_shutdown(void) {
    if (!worker_initialized) return;

    clear_output_buffer();

    zend_first_try {
        zend_eval_string(
            "\\Native\\Mobile\\Runtime::shutdown();",
            NULL, "worker_shutdown");
    } zend_end_try();

    worker_embed_shutdown();
    worker_initialized = 0;
}

// ── Worker thread main ──────────────────────────────

static void *worker_thread_main(void *arg) {
    const char *bootstrapPath = (const char *)arg;

    fprintf(stderr, "WORKER: thread started tid=%p\n", (void *)pthread_self());
    fflush(stderr);

    clear_output_buffer();

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);
    setenv("PHP_SELF", "artisan.php", 1);

    if (worker_embed_init() != SUCCESS) {
        fprintf(stderr, "WORKER: embed init FAILED\n");
        fflush(stderr);
        worker_work_int_result = -1;
        dispatch_semaphore_signal(worker_done_sem);
        return NULL;
    }

    // Execute bootstrap script to boot Laravel on worker thread
    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    char *boot_output = get_collected_output();
    if (boot_output && strstr(boot_output, "FATAL") != NULL) {
        fprintf(stderr, "WORKER: bootstrap errors: %.200s\n", boot_output);
        fflush(stderr);
    }

    worker_initialized = 1;
    worker_work_int_result = 0;

    fprintf(stderr, "WORKER: boot complete, entering work loop\n");
    fflush(stderr);

    // Signal boot complete
    dispatch_semaphore_signal(worker_done_sem);

    // ── Work loop ──
    while (1) {
        dispatch_semaphore_wait(worker_work_sem, DISPATCH_TIME_FOREVER);

        switch (worker_work_type) {
            case WORKER_WORK_ARTISAN:
                do_worker_artisan(worker_work_str_arg);
                break;
            case WORKER_WORK_SHUTDOWN:
                do_worker_shutdown();
                dispatch_semaphore_signal(worker_done_sem);
                return NULL;  // Exit thread after shutdown
        }

        dispatch_semaphore_signal(worker_done_sem);
    }

    return NULL;
}

// ── Worker public API (called from Swift) ───────────

int worker_php_boot(const char *bootstrapPath) {
    // Worker piggybacks on persistent's tsrm_startup/sapi_startup — wait for
    // that to finish before we call ts_resource() on a new thread.
    int gate = wait_for_persistent_boot(10);
    if (gate != 0) {
        fprintf(stderr, "worker_php_boot: persistent runtime not ready (gate=%d), aborting\n", gate);
        fflush(stderr);
        return -4;
    }

    fprintf(stderr, "worker_php_boot: creating worker thread\n");
    fflush(stderr);

    worker_work_sem = dispatch_semaphore_create(0);
    worker_done_sem = dispatch_semaphore_create(0);

    pthread_t thread;
    pthread_attr_t attr;
    pthread_attr_init(&attr);
    pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
    pthread_attr_setstacksize(&attr, 8 * 1024 * 1024);
    pthread_attr_set_qos_class_np(&attr, QOS_CLASS_UTILITY, 0);

    int rc = pthread_create(&thread, &attr, worker_thread_main, (void *)bootstrapPath);
    pthread_attr_destroy(&attr);

    if (rc != 0) {
        fprintf(stderr, "worker_php_boot: pthread_create FAILED: %d\n", rc);
        fflush(stderr);
        return -1;
    }

    // Block until worker finishes booting
    dispatch_semaphore_wait(worker_done_sem, DISPATCH_TIME_FOREVER);

    fprintf(stderr, "worker_php_boot: done, result=%d\n", worker_work_int_result);
    fflush(stderr);

    return worker_work_int_result;
}

const char *worker_php_artisan(const char *command) {
    if (!worker_initialized) {
        return strdup("Worker not booted.");
    }

    worker_work_type = WORKER_WORK_ARTISAN;
    worker_work_str_arg = command;
    worker_work_str_result = NULL;

    dispatch_semaphore_signal(worker_work_sem);
    dispatch_semaphore_wait(worker_done_sem, DISPATCH_TIME_FOREVER);

    return worker_work_str_result;
}

void worker_php_shutdown(void) {
    if (!worker_initialized) return;

    worker_work_type = WORKER_WORK_SHUTDOWN;
    dispatch_semaphore_signal(worker_work_sem);
    dispatch_semaphore_wait(worker_done_sem, DISPATCH_TIME_FOREVER);
}

int worker_php_is_booted(void) {
    return worker_initialized;
}

// ============================================================================
// Ephemeral PHP Runtime — generic TSRM context on its own pthread
// ============================================================================
// Designed for ephemeral use: each invocation boots a dedicated PHP thread,
// runs artisan commands, and shuts down. Used by plugins that need to execute
// PHP in the background independently of the persistent runtime
// (e.g. background tasks, scheduled jobs).

static int ephemeral_initialized = 0;
static pthread_mutex_t g_ephemeral_mutex = PTHREAD_MUTEX_INITIALIZER;

// Ephemeral thread synchronization
static dispatch_semaphore_t ephemeral_work_sem = NULL;
static dispatch_semaphore_t ephemeral_done_sem = NULL;

typedef enum {
    EPHEMERAL_WORK_ARTISAN,
    EPHEMERAL_WORK_SHUTDOWN
} ephemeral_work_type_t;

static ephemeral_work_type_t  ephemeral_work_type;
static const char            *ephemeral_work_str_arg   = NULL;
static int                    ephemeral_work_int_result = 0;
static char                  *ephemeral_work_str_result = NULL;

// ── Ephemeral TSRM init/shutdown ────────────────────

static int ephemeral_embed_init(void) {
    fprintf(stderr, "EPHEMERAL: allocating TSRM context\n");
    fflush(stderr);

    ts_resource(0);
    setup_persistent_sapi();

    if (php_embed_module.startup(&php_embed_module) == FAILURE) {
        fprintf(stderr, "EPHEMERAL: module startup FAILED\n");
        fflush(stderr);
        return FAILURE;
    }

    if (php_request_startup() == FAILURE) {
        fprintf(stderr, "EPHEMERAL: request startup FAILED\n");
        fflush(stderr);
        return FAILURE;
    }

    fprintf(stderr, "EPHEMERAL: TSRM context ready\n");
    fflush(stderr);
    return SUCCESS;
}

static void ephemeral_embed_shutdown(void) {
    fprintf(stderr, "EPHEMERAL: cleaning up TSRM context\n");
    fflush(stderr);
    php_request_shutdown(NULL);
    ts_free_thread();
    fprintf(stderr, "EPHEMERAL: TSRM context freed\n");
    fflush(stderr);
}

// ── Ephemeral work handlers ─────────────────────────

static void do_ephemeral_artisan(const char *command) {
    if (!ephemeral_initialized) {
        ephemeral_work_str_result = strdup("Ephemeral runtime not initialized.");
        return;
    }

    clear_output_buffer();

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);
    setenv("PHP_SELF", "artisan.php", 1);

    char eval_code[4096];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    $_SERVER['PHP_SELF'] = 'artisan.php';\n"
        "    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';\n"
        "    echo \\Native\\Mobile\\Runtime::artisan('%s');\n"
        "} catch (\\Throwable $e) {\n"
        "    echo 'Ephemeral artisan error: ' . $e->getMessage();\n"
        "}\n",
        command);

    zend_first_try {
        zend_eval_string(eval_code, NULL, "ephemeral_artisan");
    } zend_end_try();

    setenv("APP_RUNNING_IN_CONSOLE", "false", 1);

    char *out = get_collected_output();
    ephemeral_work_str_result = out ? strdup(out) : strdup("");
}

static void do_ephemeral_shutdown(void) {
    if (!ephemeral_initialized) return;

    clear_output_buffer();

    zend_first_try {
        zend_eval_string(
            "\\Native\\Mobile\\Runtime::shutdown();",
            NULL, "ephemeral_shutdown");
    } zend_end_try();

    ephemeral_embed_shutdown();
    ephemeral_initialized = 0;
}

// ── Ephemeral thread main ───────────────────────────

static void *ephemeral_thread_main(void *arg) {
    const char *bootstrapPath = (const char *)arg;

    fprintf(stderr, "EPHEMERAL: thread started tid=%p\n", (void *)pthread_self());
    fflush(stderr);

    clear_output_buffer();

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);
    setenv("PHP_SELF", "artisan.php", 1);

    if (ephemeral_embed_init() != SUCCESS) {
        fprintf(stderr, "EPHEMERAL: embed init FAILED\n");
        fflush(stderr);
        ephemeral_work_int_result = -1;
        dispatch_semaphore_signal(ephemeral_done_sem);
        return NULL;
    }

    // Execute bootstrap script to boot Laravel on ephemeral thread
    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    char *boot_output = get_collected_output();
    if (boot_output && strstr(boot_output, "FATAL") != NULL) {
        fprintf(stderr, "EPHEMERAL: bootstrap errors: %.200s\n", boot_output);
        fflush(stderr);
    }

    ephemeral_initialized = 1;
    ephemeral_work_int_result = 0;

    fprintf(stderr, "EPHEMERAL: boot complete, entering work loop\n");
    fflush(stderr);

    // Signal boot complete
    dispatch_semaphore_signal(ephemeral_done_sem);

    // ── Work loop ──
    while (1) {
        dispatch_semaphore_wait(ephemeral_work_sem, DISPATCH_TIME_FOREVER);

        switch (ephemeral_work_type) {
            case EPHEMERAL_WORK_ARTISAN:
                do_ephemeral_artisan(ephemeral_work_str_arg);
                break;
            case EPHEMERAL_WORK_SHUTDOWN:
                do_ephemeral_shutdown();
                dispatch_semaphore_signal(ephemeral_done_sem);
                return NULL;  // Exit thread after shutdown
        }

        dispatch_semaphore_signal(ephemeral_done_sem);
    }

    return NULL;
}

// ── Ephemeral public API (called from Swift plugins) ────────

int ephemeral_php_boot(const char *bootstrapPath) {
    // Ephemeral piggybacks on persistent's tsrm_startup/sapi_startup — wait
    // for that to finish before we call ts_resource() on a new thread.
    // Fixes a cold-launch BGTask crash where SAPI init ran before sapi_startup.
    int gate = wait_for_persistent_boot(10);
    if (gate != 0) {
        fprintf(stderr, "ephemeral_php_boot: persistent runtime not ready (gate=%d), aborting\n", gate);
        fflush(stderr);
        return -4;
    }

    fprintf(stderr, "ephemeral_php_boot: creating ephemeral thread\n");
    fflush(stderr);

    ephemeral_work_sem = dispatch_semaphore_create(0);
    ephemeral_done_sem = dispatch_semaphore_create(0);

    pthread_t thread;
    pthread_attr_t attr;
    pthread_attr_init(&attr);
    pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
    pthread_attr_setstacksize(&attr, 8 * 1024 * 1024);
    pthread_attr_set_qos_class_np(&attr, QOS_CLASS_USER_INITIATED, 0);

    int rc = pthread_create(&thread, &attr, ephemeral_thread_main, (void *)bootstrapPath);
    pthread_attr_destroy(&attr);

    if (rc != 0) {
        fprintf(stderr, "ephemeral_php_boot: pthread_create FAILED: %d\n", rc);
        fflush(stderr);
        return -1;
    }

    // Block until ephemeral runtime finishes booting
    dispatch_semaphore_wait(ephemeral_done_sem, DISPATCH_TIME_FOREVER);

    fprintf(stderr, "ephemeral_php_boot: done, result=%d\n", ephemeral_work_int_result);
    fflush(stderr);

    return ephemeral_work_int_result;
}

const char *ephemeral_php_artisan(const char *command) {
    if (!ephemeral_initialized) {
        return strdup("Ephemeral runtime not booted.");
    }

    ephemeral_work_type = EPHEMERAL_WORK_ARTISAN;
    ephemeral_work_str_arg = command;
    ephemeral_work_str_result = NULL;

    dispatch_semaphore_signal(ephemeral_work_sem);
    dispatch_semaphore_wait(ephemeral_done_sem, DISPATCH_TIME_FOREVER);

    return ephemeral_work_str_result;
}

void ephemeral_php_shutdown(void) {
    if (!ephemeral_initialized) return;

    ephemeral_work_type = EPHEMERAL_WORK_SHUTDOWN;
    dispatch_semaphore_signal(ephemeral_work_sem);
    dispatch_semaphore_wait(ephemeral_done_sem, DISPATCH_TIME_FOREVER);
}

int ephemeral_php_is_booted(void) {
    return ephemeral_initialized;
}

// ============================================================================
// Async Task Lane — pool of TSRM contexts for immediate background PHP work
// ============================================================================
// Backs AsyncTask::dispatch(). A fixed pool of worker threads, each with its
// own TSRM context booted once, running `native:async:run --id=<id>` for tasks
// assigned to it. Concurrent (one in-flight task per slot) and reused across
// tasks — unlike the single queue worker and the boot-per-invocation ephemeral
// lane. Never touches SQLite or the standard queue; the task payload/result
// travel via the PHP temp-file transport + the AsyncTask.Complete bridge
// function (which wakes the UI runloop). Android twin: the async lane in
// php_bridge.c + AsyncTaskExecutor.kt.
//
// Swift's AsyncTaskExecutor pins each slot to one serial queue, so a slot is
// only ever touched by its own thread — the TSRM context stays thread-local.

#define ASYNC_PHP_MAX_SLOTS 4

typedef enum {
    ASYNC_WORK_RUN = 1,
    ASYNC_WORK_SHUTDOWN = 2,
} async_work_type_t;

typedef struct {
    int in_use;        // slot allocated (guarded by async_pool_mutex)
    int initialized;   // context booted on its thread
    int boot_result;
    dispatch_semaphore_t work_sem;
    dispatch_semaphore_t done_sem;
    async_work_type_t work_type;
    const char *task_id;  // borrowed for the duration of one run
    char *result;         // strdup'd task output, ownership passes to caller
    char bootstrap_path[1024];
} async_php_slot_t;

static async_php_slot_t async_slots[ASYNC_PHP_MAX_SLOTS];
static pthread_mutex_t async_pool_mutex = PTHREAD_MUTEX_INITIALIZER;

static void do_async_run(async_php_slot_t *slot) {
    clear_output_buffer();

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);
    setenv("PHP_SELF", "artisan.php", 1);

    // task_id is a framework-generated UUID (Str::uuid()), so it's safe to
    // embed directly; no user input reaches this string.
    char eval_code[1024];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    $_SERVER['PHP_SELF'] = 'artisan.php';\n"
        "    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';\n"
        "    \\Native\\Mobile\\Runtime::artisan('native:async:run --id=%s');\n"
        "} catch (\\Throwable $e) {\n"
        "    error_log('async run error: ' . $e->getMessage());\n"
        "}\n",
        slot->task_id ? slot->task_id : "");

    zend_first_try {
        zend_eval_string(eval_code, NULL, "async_run");
    } zend_end_try();

    setenv("APP_RUNNING_IN_CONSOLE", "false", 1);

    char *out = get_collected_output();
    slot->result = out ? strdup(out) : strdup("");
}

static void *async_thread_main(void *arg) {
    async_php_slot_t *slot = (async_php_slot_t *)arg;

    fprintf(stderr, "ASYNC-PHP: thread started tid=%p\n", (void *)pthread_self());
    fflush(stderr);

    clear_output_buffer();

    // Same per-thread TSRM init the webview lane uses.
    if (ephemeral_embed_init() != SUCCESS) {
        fprintf(stderr, "ASYNC-PHP: embed init FAILED\n");
        fflush(stderr);
        slot->boot_result = -1;
        dispatch_semaphore_signal(slot->done_sem);
        return NULL;
    }

    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, slot->bootstrap_path);
        php_execute_script(&fileHandle);
    } zend_end_try();

    char *boot_output = get_collected_output();
    if (boot_output && strstr(boot_output, "FATAL") != NULL) {
        fprintf(stderr, "ASYNC-PHP: bootstrap errors: %.200s\n", boot_output);
        fflush(stderr);
    }

    slot->initialized = 1;
    slot->boot_result = 0;

    dispatch_semaphore_signal(slot->done_sem);

    while (1) {
        dispatch_semaphore_wait(slot->work_sem, DISPATCH_TIME_FOREVER);

        switch (slot->work_type) {
            case ASYNC_WORK_RUN:
                do_async_run(slot);
                break;
            case ASYNC_WORK_SHUTDOWN:
                zend_first_try {
                    zend_eval_string(
                        "\\Native\\Mobile\\Runtime::shutdown();",
                        NULL, "async_shutdown");
                } zend_end_try();
                ephemeral_embed_shutdown();
                slot->initialized = 0;
                dispatch_semaphore_signal(slot->done_sem);
                return NULL;
        }

        dispatch_semaphore_signal(slot->done_sem);
    }

    return NULL;
}

// Boot one async slot and return its handle (≥ 0), or negative on error.
// Swift calls this once per pool thread.
int async_php_boot(const char *bootstrapPath) {
    int gate = wait_for_persistent_boot(10);
    if (gate != 0) {
        fprintf(stderr, "async_php_boot: persistent runtime not ready (gate=%d)\n", gate);
        fflush(stderr);
        return -4;
    }

    pthread_mutex_lock(&async_pool_mutex);
    int handle = -1;
    for (int i = 0; i < ASYNC_PHP_MAX_SLOTS; i++) {
        if (!async_slots[i].in_use) {
            handle = i;
            async_slots[i].in_use = 1;
            break;
        }
    }
    pthread_mutex_unlock(&async_pool_mutex);

    if (handle < 0) {
        fprintf(stderr, "async_php_boot: no free slots (max %d)\n", ASYNC_PHP_MAX_SLOTS);
        fflush(stderr);
        return -2;
    }

    async_php_slot_t *slot = &async_slots[handle];
    slot->initialized = 0;
    slot->boot_result = -1;
    slot->result = NULL;
    slot->task_id = NULL;
    slot->work_sem = dispatch_semaphore_create(0);
    slot->done_sem = dispatch_semaphore_create(0);
    snprintf(slot->bootstrap_path, sizeof(slot->bootstrap_path), "%s", bootstrapPath);

    pthread_t thread;
    pthread_attr_t attr;
    pthread_attr_init(&attr);
    pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
    pthread_attr_setstacksize(&attr, 8 * 1024 * 1024);
    pthread_attr_set_qos_class_np(&attr, QOS_CLASS_UTILITY, 0);

    int rc = pthread_create(&thread, &attr, async_thread_main, slot);
    pthread_attr_destroy(&attr);

    if (rc != 0) {
        fprintf(stderr, "async_php_boot: pthread_create FAILED: %d\n", rc);
        fflush(stderr);
        pthread_mutex_lock(&async_pool_mutex);
        slot->in_use = 0;
        pthread_mutex_unlock(&async_pool_mutex);
        return -1;
    }

    dispatch_semaphore_wait(slot->done_sem, DISPATCH_TIME_FOREVER);

    if (slot->boot_result != 0) {
        pthread_mutex_lock(&async_pool_mutex);
        slot->in_use = 0;
        pthread_mutex_unlock(&async_pool_mutex);
        return -3;
    }

    fprintf(stderr, "async_php_boot: slot %d ready\n", handle);
    fflush(stderr);
    return handle;
}

// Run one task on a specific slot. Blocks until the task completes. Call only
// from that slot's dedicated Swift serial queue (keeps the context thread-local).
const char *async_php_run(int handle, const char *taskId) {
    if (handle < 0 || handle >= ASYNC_PHP_MAX_SLOTS) {
        return strdup("");
    }

    async_php_slot_t *slot = &async_slots[handle];
    if (!slot->in_use || !slot->initialized) {
        return strdup("");
    }

    slot->task_id = taskId;
    slot->result = NULL;
    slot->work_type = ASYNC_WORK_RUN;

    dispatch_semaphore_signal(slot->work_sem);
    dispatch_semaphore_wait(slot->done_sem, DISPATCH_TIME_FOREVER);

    slot->task_id = NULL;
    return slot->result ? slot->result : strdup("");
}

void async_php_stop(int handle) {
    if (handle < 0 || handle >= ASYNC_PHP_MAX_SLOTS) {
        return;
    }

    async_php_slot_t *slot = &async_slots[handle];
    if (!slot->in_use) {
        return;
    }

    if (slot->initialized) {
        slot->work_type = ASYNC_WORK_SHUTDOWN;
        dispatch_semaphore_signal(slot->work_sem);
        dispatch_semaphore_wait(slot->done_sem, DISPATCH_TIME_FOREVER);
    }

    pthread_mutex_lock(&async_pool_mutex);
    slot->in_use = 0;
    pthread_mutex_unlock(&async_pool_mutex);
}

// ── Webview PHP Runtimes ────────────────────────────
// One dedicated PHP context per embedded php-mode <webview> element. The
// persistent runtime's serial queue is parked inside a native screen's
// event-loop dispatch for the screen's whole lifetime, so it can never
// answer php:// requests from an embedded webview — each webview gets its
// own thread + TSRM context instead, started when the webview mounts and
// stopped when it leaves the view hierarchy.
//
// Request state is passed by inlining into the eval and via per-thread
// SAPI globals — never via setenv() — so slots can run concurrently with
// the persistent lane's env churn without racing it.

#define WEBVIEW_PHP_MAX_SLOTS 4

typedef enum {
    WEBVIEW_WORK_REQUEST = 1,
    WEBVIEW_WORK_SHUTDOWN = 2,
} webview_work_type_t;

typedef struct {
    const char *method;
    const char *uri;
    const char *cookieHeader;
    const char *postData;
    const char *contentType;
    const char *scriptPath;
} webview_request_params_t;

typedef struct {
    int in_use;        // slot allocated (guarded by webview_pool_mutex)
    int initialized;   // context booted on its thread
    int boot_result;
    dispatch_semaphore_t work_sem;
    dispatch_semaphore_t done_sem;
    webview_work_type_t work_type;
    webview_request_params_t params;
    char *result;      // strdup'd raw HTTP response, ownership passes to caller
    char bootstrap_path[1024];
} webview_php_slot_t;

static webview_php_slot_t webview_slots[WEBVIEW_PHP_MAX_SLOTS];
static pthread_mutex_t webview_pool_mutex = PTHREAD_MUTEX_INITIALIZER;

/* Escape a string for inclusion inside a single-quoted PHP string literal. */
static char *webview_escape_php(const char *s) {
    if (!s) {
        return strdup("");
    }
    size_t len = strlen(s);
    char *out = malloc(len * 2 + 1);
    if (!out) {
        return strdup("");
    }
    char *p = out;
    for (size_t i = 0; i < len; i++) {
        char c = s[i];
        if (c == '\\' || c == '\'') {
            *p++ = '\\';
        }
        *p++ = c;
    }
    *p = '\0';
    return out;
}

static void do_webview_dispatch(webview_php_slot_t *slot) {
    clear_output_buffer();

    const webview_request_params_t *params = &slot->params;

    // Per-thread SAPI request state (TSRM-local — safe alongside other lanes)
    SG(headers_sent) = 0;
    SG(post_read) = 0;
    SG(read_post_bytes) = 0;
    SG(request_info).request_method = params->method;
    SG(request_info).request_uri = (char *)params->uri;
    SG(request_info).proto_num = 1001;

    memset(&SG(sapi_headers), 0, sizeof(sapi_headers_struct));
    SG(sapi_headers).http_response_code = 200;
    zend_llist_init(&SG(sapi_headers).headers, sizeof(sapi_header_struct), NULL, 0);

    // POST data → php://input
    if (params->postData && strlen(params->postData) > 0) {
        php_stream *post_stream = php_stream_memory_create(TEMP_STREAM_DEFAULT);
        if (post_stream) {
            php_stream_write(post_stream, params->postData, strlen(params->postData));
            php_stream_seek(post_stream, 0, SEEK_SET);

            if (SG(request_info).request_body) {
                php_stream_close(SG(request_info).request_body);
            }
            SG(request_info).request_body = post_stream;
            SG(request_info).content_length = strlen(params->postData);

            if (params->contentType && strstr(params->contentType, "json")) {
                SG(request_info).content_type = "application/json";
            } else {
                SG(request_info).content_type = "application/x-www-form-urlencoded";
            }
        }
    } else {
        if (SG(request_info).request_body) {
            php_stream_close(SG(request_info).request_body);
            SG(request_info).request_body = NULL;
        }
        SG(request_info).content_length = 0;
    }

    char *esc_method = webview_escape_php(params->method);
    char *esc_uri = webview_escape_php(params->uri);
    char *esc_cookie = webview_escape_php(params->cookieHeader);
    char *esc_ct = webview_escape_php(params->contentType);
    char *esc_script = webview_escape_php(params->scriptPath);

    // Same dispatch shape as the persistent lane, but every request value is
    // inlined — no getenv() merge, so the persistent lane's transient
    // request env vars can never bleed into (or race) this context.
    char *eval_code = NULL;
    asprintf(&eval_code,
        "try {\n"
        "    while (ob_get_level() > 0) { ob_end_clean(); }\n"
        "\n"
        "    foreach ($_SERVER as $__k => $__v) {\n"
        "        if (str_starts_with($__k, 'HTTP_') || $__k === 'CONTENT_TYPE' || $__k === 'CONTENT_LENGTH') {\n"
        "            unset($_SERVER[$__k]);\n"
        "        }\n"
        "    }\n"
        "\n"
        "    $_SERVER['REQUEST_METHOD'] = '%s';\n"
        "    $_SERVER['REQUEST_URI'] = '%s';\n"
        "    $_SERVER['SCRIPT_FILENAME'] = '%s';\n"
        "    $_SERVER['PHP_SELF'] = '/native.php';\n"
        "    $_SERVER['HTTP_HOST'] = '127.0.0.1';\n"
        "    $_SERVER['SERVER_NAME'] = '127.0.0.1';\n"
        "    $_SERVER['SERVER_PORT'] = '80';\n"
        "    $_SERVER['APP_URL'] = 'php://127.0.0.1';\n"
        "    $_SERVER['NATIVEPHP_RUNNING'] = 'true';\n"
        "    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';\n"
        "    if ('%s' !== '') { $_SERVER['HTTP_COOKIE'] = '%s'; }\n"
        "    if ('%s' !== '') { $_SERVER['CONTENT_TYPE'] = '%s'; $_SERVER['HTTP_CONTENT_TYPE'] = '%s'; }\n"
        "\n"
        "    $__qpos = strpos($_SERVER['REQUEST_URI'], '?');\n"
        "    if ($__qpos !== false) {\n"
        "        $_SERVER['QUERY_STRING'] = substr($_SERVER['REQUEST_URI'], $__qpos + 1);\n"
        "    } else {\n"
        "        $_SERVER['QUERY_STRING'] = '';\n"
        "    }\n"
        "\n"
        "    $_GET = [];\n"
        "    $_POST = [];\n"
        "    $_COOKIE = [];\n"
        "    $_FILES = [];\n"
        "    $_REQUEST = [];\n"
        "\n"
        "    if (isset($_SERVER['HTTP_COOKIE']) && $_SERVER['HTTP_COOKIE'] !== '') {\n"
        "        foreach (explode('; ', $_SERVER['HTTP_COOKIE']) as $__pair) {\n"
        "            $__parts = explode('=', $__pair, 2);\n"
        "            if (count($__parts) === 2) {\n"
        "                $_COOKIE[$__parts[0]] = urldecode($__parts[1]);\n"
        "            }\n"
        "        }\n"
        "    }\n"
        "\n"
        "    if ($_SERVER['QUERY_STRING'] !== '') {\n"
        "        parse_str($_SERVER['QUERY_STRING'], $_GET);\n"
        "    }\n"
        "\n"
        "    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) {\n"
        "        $__rawInput = file_get_contents('php://input');\n"
        "        if ($__rawInput !== false && $__rawInput !== '') {\n"
        "            $__ct = $_SERVER['CONTENT_TYPE'] ?? '';\n"
        "            if (stripos($__ct, 'application/x-www-form-urlencoded') !== false) {\n"
        "                parse_str($__rawInput, $_POST);\n"
        "            }\n"
        "            $_REQUEST = array_merge($_GET, $_POST, $_COOKIE);\n"
        "        }\n"
        "    }\n"
        "\n"
        "    $__response = \\Native\\Mobile\\Runtime::dispatch(\n"
        "        \\Illuminate\\Http\\Request::capture()\n"
        "    );\n"
        "    $__code = $__response->getStatusCode();\n"
        "    $__status = \\Symfony\\Component\\HttpFoundation\\Response::$statusTexts[$__code] ?? 'OK';\n"
        "    echo \"HTTP/1.1 {$__code} {$__status}\\r\\n\";\n"
        "    foreach ($__response->headers->all() as $__name => $__values) {\n"
        "        foreach ($__values as $__value) {\n"
        "            echo \"{$__name}: {$__value}\\r\\n\";\n"
        "        }\n"
        "    }\n"
        "    echo \"\\r\\n\";\n"
        "    $__response->sendContent();\n"
        "} catch (\\Throwable $e) {\n"
        "    echo \"HTTP/1.1 500 Internal Server Error\\r\\n\";\n"
        "    echo \"Content-Type: text/plain\\r\\n\\r\\n\";\n"
        "    echo 'Webview dispatch error: ' . $e->getMessage() . \"\\n\";\n"
        "    echo $e->getTraceAsString();\n"
        "}\n",
        esc_method, esc_uri, esc_script,
        esc_cookie, esc_cookie,
        esc_ct, esc_ct, esc_ct);

    free(esc_method);
    free(esc_uri);
    free(esc_cookie);
    free(esc_ct);
    free(esc_script);

    if (!eval_code) {
        slot->result = strdup("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nFailed to build webview dispatch.");
        return;
    }

    zend_first_try {
        zend_eval_string(eval_code, NULL, "webview_dispatch");
    } zend_end_try();

    free(eval_code);

    char *out = get_collected_output();
    slot->result = out ? strdup(out) : strdup("");
}

static void *webview_thread_main(void *arg) {
    webview_php_slot_t *slot = (webview_php_slot_t *)arg;

    fprintf(stderr, "WEBVIEW-PHP: thread started tid=%p\n", (void *)pthread_self());
    fflush(stderr);

    clear_output_buffer();

    if (ephemeral_embed_init() != SUCCESS) {
        fprintf(stderr, "WEBVIEW-PHP: embed init FAILED\n");
        fflush(stderr);
        slot->boot_result = -1;
        dispatch_semaphore_signal(slot->done_sem);
        return NULL;
    }

    // Boot Laravel on this context so Runtime::dispatch() serves warm requests
    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, slot->bootstrap_path);
        php_execute_script(&fileHandle);
    } zend_end_try();

    char *boot_output = get_collected_output();
    if (boot_output && strstr(boot_output, "FATAL") != NULL) {
        fprintf(stderr, "WEBVIEW-PHP: bootstrap errors: %.200s\n", boot_output);
        fflush(stderr);
    }

    slot->initialized = 1;
    slot->boot_result = 0;

    fprintf(stderr, "WEBVIEW-PHP: boot complete, entering work loop\n");
    fflush(stderr);

    dispatch_semaphore_signal(slot->done_sem);

    while (1) {
        dispatch_semaphore_wait(slot->work_sem, DISPATCH_TIME_FOREVER);

        switch (slot->work_type) {
            case WEBVIEW_WORK_REQUEST:
                do_webview_dispatch(slot);
                break;
            case WEBVIEW_WORK_SHUTDOWN:
                zend_first_try {
                    zend_eval_string(
                        "\\Native\\Mobile\\Runtime::shutdown();",
                        NULL, "webview_shutdown");
                } zend_end_try();
                ephemeral_embed_shutdown();
                slot->initialized = 0;
                fprintf(stderr, "WEBVIEW-PHP: thread shut down\n");
                fflush(stderr);
                dispatch_semaphore_signal(slot->done_sem);
                return NULL;
        }

        dispatch_semaphore_signal(slot->done_sem);
    }

    return NULL;
}

int webview_php_start(const char *bootstrapPath) {
    int gate = wait_for_persistent_boot(10);
    if (gate != 0) {
        fprintf(stderr, "webview_php_start: persistent runtime not ready (gate=%d)\n", gate);
        fflush(stderr);
        return -4;
    }

    pthread_mutex_lock(&webview_pool_mutex);
    int handle = -1;
    for (int i = 0; i < WEBVIEW_PHP_MAX_SLOTS; i++) {
        if (!webview_slots[i].in_use) {
            handle = i;
            webview_slots[i].in_use = 1;
            break;
        }
    }
    pthread_mutex_unlock(&webview_pool_mutex);

    if (handle < 0) {
        fprintf(stderr, "webview_php_start: no free slots (max %d)\n", WEBVIEW_PHP_MAX_SLOTS);
        fflush(stderr);
        return -2;
    }

    webview_php_slot_t *slot = &webview_slots[handle];
    slot->initialized = 0;
    slot->boot_result = -1;
    slot->result = NULL;
    slot->work_sem = dispatch_semaphore_create(0);
    slot->done_sem = dispatch_semaphore_create(0);
    snprintf(slot->bootstrap_path, sizeof(slot->bootstrap_path), "%s", bootstrapPath);

    pthread_t thread;
    pthread_attr_t attr;
    pthread_attr_init(&attr);
    pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
    pthread_attr_setstacksize(&attr, 8 * 1024 * 1024);
    pthread_attr_set_qos_class_np(&attr, QOS_CLASS_USER_INITIATED, 0);

    int rc = pthread_create(&thread, &attr, webview_thread_main, slot);
    pthread_attr_destroy(&attr);

    if (rc != 0) {
        fprintf(stderr, "webview_php_start: pthread_create FAILED: %d\n", rc);
        fflush(stderr);
        pthread_mutex_lock(&webview_pool_mutex);
        slot->in_use = 0;
        pthread_mutex_unlock(&webview_pool_mutex);
        return -1;
    }

    // Block until the context finishes booting
    dispatch_semaphore_wait(slot->done_sem, DISPATCH_TIME_FOREVER);

    if (slot->boot_result != 0) {
        pthread_mutex_lock(&webview_pool_mutex);
        slot->in_use = 0;
        pthread_mutex_unlock(&webview_pool_mutex);
        return -3;
    }

    fprintf(stderr, "webview_php_start: slot %d ready\n", handle);
    fflush(stderr);
    return handle;
}

const char *webview_php_request(int handle,
                                const char *method,
                                const char *uri,
                                const char *cookieHeader,
                                const char *postData,
                                const char *contentType,
                                const char *scriptPath) {
    if (handle < 0 || handle >= WEBVIEW_PHP_MAX_SLOTS) {
        return strdup("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nInvalid webview runtime handle.");
    }

    webview_php_slot_t *slot = &webview_slots[handle];
    if (!slot->in_use || !slot->initialized) {
        return strdup("HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain\r\n\r\nWebview runtime not booted.");
    }

    slot->params.method = method;
    slot->params.uri = uri;
    slot->params.cookieHeader = cookieHeader;
    slot->params.postData = postData;
    slot->params.contentType = contentType;
    slot->params.scriptPath = scriptPath;
    slot->result = NULL;
    slot->work_type = WEBVIEW_WORK_REQUEST;

    dispatch_semaphore_signal(slot->work_sem);
    dispatch_semaphore_wait(slot->done_sem, DISPATCH_TIME_FOREVER);

    return slot->result ? slot->result : strdup("");
}

void webview_php_stop(int handle) {
    if (handle < 0 || handle >= WEBVIEW_PHP_MAX_SLOTS) {
        return;
    }

    webview_php_slot_t *slot = &webview_slots[handle];
    if (!slot->in_use) {
        return;
    }

    if (slot->initialized) {
        slot->work_type = WEBVIEW_WORK_SHUTDOWN;
        dispatch_semaphore_signal(slot->work_sem);
        dispatch_semaphore_wait(slot->done_sem, DISPATCH_TIME_FOREVER);
    }

    pthread_mutex_lock(&webview_pool_mutex);
    slot->in_use = 0;
    pthread_mutex_unlock(&webview_pool_mutex);

    fprintf(stderr, "webview_php_stop: slot %d released\n", handle);
    fflush(stderr);
}
