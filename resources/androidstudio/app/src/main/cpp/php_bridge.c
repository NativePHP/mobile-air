#include <jni.h>
#include <android/log.h>
#include <signal.h>
#include <pthread.h>
#include <errno.h>
#include <time.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include "php_embed.h"
#include "PHP.h"
#include <zend_exceptions.h>

// Define Android logging macros first
#define LOG_TAG "PHP-Native"
#define LOGI(...) ((void)__android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__))
#define LOGE(...) ((void)__android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__))

JavaVM *g_jvm = NULL;
jobject g_bridge_instance = NULL;

// Forward declarations
extern jint InitializeBridgeJNI(JNIEnv* env);
static void safe_php_embed_shutdown(void);
static void worker_embed_shutdown(void);
static void ephemeral_embed_shutdown(void);
int android_header_handler(sapi_header_struct *sapi_header, sapi_header_op_enum op, sapi_headers_struct *sapi_headers);

// Global state
static int php_initialized = 0;    // tracks whether php_embed_init is active
static pthread_mutex_t g_php_request_mutex = PTHREAD_MUTEX_INITIALIZER;
static jobject g_callback_obj = NULL;
static jmethodID g_callback_method = NULL;

// ── Persistent boot gate ─────────────────────────────────────────────────
// Without this gate, two threads can concurrently call php_embed_init():
// one from native_persistent_boot (holding g_php_request_mutex), another from
// native_ephemeral_boot taking the cold path (holding g_ephemeral_mutex, a
// different lock) because it read php_initialized==0 before persistent set it.
// Concurrent php_embed_init calls corrupt TSRM/SAPI globals.
//
// The gate lets ephemeral block while persistent is mid-boot, then re-check
// php_initialized and take hot or cold path correctly.
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

// Wait for persistent boot to leave IN_PROGRESS (settle into a terminal state).
// Returns 0 once settled; returns -1 on timeout.
// Callers re-check php_initialized after this to pick hot vs cold path —
// this helper only prevents the concurrent php_embed_init race.
static int wait_for_persistent_boot_settled(int timeout_seconds) {
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
    pthread_mutex_unlock(&g_persistent_boot_mutex);
    return 0;
}

#define BUFFER_CHUNK_SIZE (256 * 1024)  // 256KB increments
#define MAX_BUFFER_SIZE (16 * 1024 * 1024)  // 16MB max buffer

// Thread-local output buffer
typedef struct {
    char *output;
    size_t length;
    size_t capacity;
    // Set when a chunk had to be dropped: the capture is incomplete and must
    // never be handed on as a response. OUTPUT_OVERFLOW_LIMIT means it went
    // past MAX_BUFFER_SIZE, OUTPUT_OVERFLOW_NOMEM that realloc failed.
    int overflowed;
    size_t dropped;
} php_output_buffer_t;

#define OUTPUT_OVERFLOW_LIMIT 1
#define OUTPUT_OVERFLOW_NOMEM 2

static pthread_key_t g_output_buffer_key;
static pthread_once_t g_output_key_once = PTHREAD_ONCE_INIT;

static void destroy_output_buffer(void *ptr) {
    if (ptr) {
        php_output_buffer_t *buf = (php_output_buffer_t *)ptr;
        if (buf->output) free(buf->output);
        free(buf);
    }
}

static void create_output_buffer_key(void) {
    pthread_key_create(&g_output_buffer_key, destroy_output_buffer);
}

static php_output_buffer_t *get_thread_output_buffer(void) {
    pthread_once(&g_output_key_once, create_output_buffer_key);
    php_output_buffer_t *buf = (php_output_buffer_t *)pthread_getspecific(g_output_buffer_key);
    if (!buf) {
        buf = (php_output_buffer_t *)calloc(1, sizeof(php_output_buffer_t));
        if (buf) {
            pthread_setspecific(g_output_buffer_key, buf);
        }
    }
    return buf;
}

static void (*jni_output_callback_ptr)(const char *) = NULL;

// Worker state
static int worker_initialized = 0;
static pthread_mutex_t g_worker_mutex = PTHREAD_MUTEX_INITIALIZER;

// Ephemeral state — generic background TSRM context for plugin use
static int ephemeral_initialized = 0;
static int ephemeral_cold_booted = 0;
static pthread_mutex_t g_ephemeral_mutex = PTHREAD_MUTEX_INITIALIZER;

/**
 * Configure the embed SAPI module with host-registered functions.
 * Must be called before each php_embed_init().
 */
static void setup_embed_module(void) {
    php_embed_module.ub_write = capture_php_output;
    php_embed_module.phpinfo_as_text = 1;
    php_embed_module.php_ini_ignore = 0;
    // post_max_size and upload_max_filesize match the 16MB capture cap. The
    // request body parser honours them, and PHP's defaults (8M, 2M) would
    // turn away uploads the bridge itself can carry.
    php_embed_module.ini_entries = "output_buffering=4096\n"
                                   "implicit_flush=0\n"
                                   "display_errors=1\n"
                                   "error_reporting=E_ALL\n"
                                   "post_max_size=16M\n"
                                   "upload_max_filesize=16M\n";
    php_embed_module.header_handler = android_header_handler;

    // Extension functions are now statically linked via --enable-nativephp
    // and self-register on every php_embed_init() — no manual registration needed.
}

// Safe shutdown: block all signals to prevent mutex access after TSRM destruction
static void safe_php_embed_shutdown(void) {
    sigset_t mask, oldmask;
    sigfillset(&mask);
    pthread_sigmask(SIG_BLOCK, &mask, &oldmask);
    php_embed_shutdown();
    pthread_sigmask(SIG_SETMASK, &oldmask, NULL);
}

void clear_collected_output() {
    php_output_buffer_t *buf = get_thread_output_buffer();
    if (!buf) return;

    if (buf->output) {
        free(buf->output);
        buf->output = NULL;
    }

    buf->capacity = BUFFER_CHUNK_SIZE;
    buf->length = 0;
    buf->overflowed = 0;
    buf->dropped = 0;
    buf->output = (char *) malloc(buf->capacity);
    if (buf->output) {
        buf->output[0] = '\0';
    }
}

static char *get_collected_output(void) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    return buf ? buf->output : NULL;
}

/**
 * Append len bytes of PHP output to this thread's capture buffer. Binary-safe:
 * NULs are kept, and the buffer stays NUL-terminated after `length` only so
 * text consumers (artisan, boot logs) keep working.
 *
 * A chunk that would take the capture past MAX_BUFFER_SIZE is dropped and the
 * buffer is marked overflowed, so the dispatch lanes answer with a 500 that
 * names the limit instead of a body with bytes missing from the middle.
 */
void append_output(const char *str, size_t length) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    if (!buf || length == 0) return;

    if (!buf->output) {
        clear_collected_output();
        if (!buf->output) return;  // Failed to allocate
    }

    if (buf->overflowed) {
        buf->dropped += length;
        return;
    }

    if (buf->length + length + 1 > buf->capacity) {
        size_t needed_capacity = buf->capacity;
        while (needed_capacity < buf->length + length + 1) {
            needed_capacity += BUFFER_CHUNK_SIZE;
        }

        if (needed_capacity > MAX_BUFFER_SIZE) {
            LOGE("Output exceeded the %d MB capture limit; the response will be a 500", MAX_BUFFER_SIZE / (1024 * 1024));
            buf->overflowed = OUTPUT_OVERFLOW_LIMIT;
            buf->dropped += length;
            return;
        }

        char *new_buffer = (char *) realloc(buf->output, needed_capacity);
        if (!new_buffer) {
            LOGE("Failed to reallocate output buffer to %zu bytes", needed_capacity);
            buf->overflowed = OUTPUT_OVERFLOW_NOMEM;
            buf->dropped += length;
            return;
        }
        buf->output = new_buffer;
        buf->capacity = needed_capacity;
    }

    memcpy(buf->output + buf->length, str, length);
    buf->length += length;
    buf->output[buf->length] = '\0';
}

/** Text-only wrapper kept for PHP.c and older callers. */
void pipe_php_output(const char *str) {
    if (str) append_output(str, strlen(str));
}

size_t get_collected_output_len(void) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    return buf ? buf->length : 0;
}

void cleanup_output_buffer() {
    php_output_buffer_t *buf = get_thread_output_buffer();
    if (buf && buf->output) {
        buf->output[0] = '\0';
        buf->length = 0;
    }
}

size_t capture_php_output(const char *str, size_t str_length) {
    append_output(str, str_length);
    return str_length;
}

void override_embed_module_output(void (*callback)(const char *)) {
    jni_output_callback_ptr = callback;
    php_embed_module.ub_write = capture_php_output;
}

void jni_output_callback(const char *output) {
    JNIEnv *env;
    if ((*g_jvm)->GetEnv(g_jvm, (void **) &env, JNI_VERSION_1_6) != JNI_OK) {
        LOGE("Failed to get JNI environment");
        return;
    }

    if (g_callback_obj && g_callback_method) {
        jstring joutput = (*env)->NewStringUTF(env, output);
        (*env)->CallVoidMethod(env, g_callback_obj, g_callback_method, joutput);
        (*env)->DeleteLocalRef(env, joutput);
    }
}

int android_header_handler(sapi_header_struct *sapi_header, sapi_header_op_enum op, sapi_headers_struct *sapi_headers) {
    LOGI("SAPI header: %s", sapi_header->header);
    return 0;
}

// ============================================================================
// Binary-safe request and response plumbing for the dispatch lanes
// ============================================================================
// Bodies cross JNI as byte[] and never as Java strings, in both directions.
// PHP gets the body in php://input with its exact length, and the response
// goes back as the capture buffer's bytes and length. See
// docs/bridge-dispatcher-contract.md for what PHP expects.

// The one line of PHP each lane evals. Arguments, in order: platform, lane,
// then base64 of method, uri, script path, cookie, content type, headers.
#define BRIDGE_DISPATCH_EVAL \
    "if (class_exists('Native\\\\Mobile\\\\Http\\\\Bridge\\\\BridgeDispatcher')) {" \
    " \\Native\\Mobile\\Http\\Bridge\\BridgeDispatcher::handle('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');" \
    " } else {" \
    " echo \"HTTP/1.1 500 Internal Server Error\\r\\nContent-Type: text/plain\\r\\nContent-Length: 66\\r\\n\\r\\n" \
    "BridgeDispatcher missing: run composer install and native:install.\";" \
    " }"

// Standard base64 (RFC 4648, with padding, no line breaks) of len bytes.
// in may be NULL when len is 0. Returns a malloc'd NUL-terminated string,
// or NULL if out of memory.
static char *bridge_base64(const char *in, size_t len) {
    static const char table[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    char *out = malloc(4 * ((len + 2) / 3) + 1);
    if (!out) return NULL;
    const unsigned char *src = (const unsigned char *)in;
    size_t i = 0, j = 0;
    for (; i + 2 < len; i += 3) {
        uint32_t n = ((uint32_t)src[i] << 16) | ((uint32_t)src[i + 1] << 8) | src[i + 2];
        out[j++] = table[(n >> 18) & 63];
        out[j++] = table[(n >> 12) & 63];
        out[j++] = table[(n >> 6) & 63];
        out[j++] = table[n & 63];
    }
    if (i < len) {
        uint32_t n = (uint32_t)src[i] << 16;
        if (i + 1 < len) n |= (uint32_t)src[i + 1] << 8;
        out[j++] = table[(n >> 18) & 63];
        out[j++] = table[(n >> 12) & 63];
        out[j++] = (i + 1 < len) ? table[(n >> 6) & 63] : '=';
        out[j++] = '=';
    }
    out[j] = '\0';
    return out;
}

// Build the eval string for one request. Any string argument may be NULL,
// which is sent as ''. Returns a malloc'd string for zend_eval_string(), or
// NULL if out of memory. The caller frees it.
static char *bridge_dispatch_code(const char *platform, const char *lane,
                                  const char *method, const char *uri,
                                  const char *script_path, const char *cookie,
                                  const char *content_type,
                                  const char *headers, size_t headers_len) {
    const char *values[6] = { method, uri, script_path, cookie, content_type, headers };
    size_t lengths[6];
    char *b64[6] = { NULL };
    char *code = NULL;

    for (int k = 0; k < 6; k++) {
        lengths[k] = values[k] ? (k == 5 ? headers_len : strlen(values[k])) : 0;
    }

    int ok = 1;
    for (int k = 0; k < 6; k++) {
        b64[k] = bridge_base64(values[k], lengths[k]);
        if (!b64[k]) ok = 0;
    }

    if (ok && asprintf(&code, BRIDGE_DISPATCH_EVAL, platform, lane,
                       b64[0], b64[1], b64[2], b64[3], b64[4], b64[5]) < 0) {
        code = NULL;
    }

    for (int k = 0; k < 6; k++) free(b64[k]);
    return code;
}

// Reset the SAPI response state for a new request on this thread's context.
static void bridge_reset_sapi(const char *method, const char *uri) {
    SG(headers_sent) = 0;
    SG(post_read) = 0;
    SG(read_post_bytes) = 0;
    SG(request_info).request_method = method;
    SG(request_info).request_uri = (char *)uri;
    SG(request_info).proto_num = 1001; // HTTP/1.1

    memset(&SG(sapi_headers), 0, sizeof(sapi_headers_struct));
    SG(sapi_headers).http_response_code = 200;
    zend_llist_init(&SG(sapi_headers).headers, sizeof(sapi_header_struct), NULL, 0);
}

// Put exactly len body bytes, NULs included, into php://input.
//
// SG(request_info).content_type stays NULL on purpose. PHP's own body parser
// (request_parse_body(), which Symfony 8 calls for PUT, DELETE and PATCH)
// reads it: with a multipart type it calls the embed SAPI's read_post, which
// is NULL, and with the urlencoded type it swaps php://input for an empty
// stream. The body's content type reaches PHP as a dispatch argument instead.
static void bridge_set_request_body(const char *body, size_t len) {
    if (SG(request_info).request_body) {
        php_stream_close(SG(request_info).request_body);
        SG(request_info).request_body = NULL;
    }
    SG(request_info).content_type = NULL;
    SG(request_info).content_length = 0;

    if (!body || len == 0) return;

    php_stream *stream = php_stream_memory_create(TEMP_STREAM_DEFAULT);
    if (!stream) {
        LOGE("bridge: could not create the php://input stream (%zu bytes)", len);
        return;
    }
    php_stream_write(stream, body, len);
    php_stream_seek(stream, 0, SEEK_SET);
    SG(request_info).request_body = stream;
    SG(request_info).content_length = (zend_long) len;
}

// A Java byte[] holding len bytes, or NULL with an OutOfMemoryError pending.
static jbyteArray bridge_new_byte_array(JNIEnv *env, const char *bytes, size_t len) {
    jbyteArray array = (*env)->NewByteArray(env, (jsize) len);
    if (!array) return NULL;
    if (len > 0) {
        (*env)->SetByteArrayRegion(env, array, 0, (jsize) len, (const jbyte *) bytes);
    }
    return array;
}

static jbyteArray bridge_text_response(JNIEnv *env, const char *text) {
    return bridge_new_byte_array(env, text, strlen(text));
}

// This thread's capture as a Java byte[]: exactly the bytes PHP wrote. An
// overflowed capture is replaced by a 500 that says which limit was hit.
static jbyteArray bridge_take_response(JNIEnv *env) {
    php_output_buffer_t *buf = get_thread_output_buffer();

    if (buf && buf->overflowed) {
        char *body = NULL;
        char *response = NULL;
        if (buf->overflowed == OUTPUT_OVERFLOW_LIMIT) {
            asprintf(&body,
                     "Response too large for the PHP bridge: it is over the %d MB capture limit "
                     "(MAX_BUFFER_SIZE, %d bytes, in php_bridge.c). %zu bytes were captured and at least "
                     "%zu more were dropped, so nothing was sent rather than a body with bytes missing.\n",
                     MAX_BUFFER_SIZE / (1024 * 1024), MAX_BUFFER_SIZE, buf->length, buf->dropped);
        } else {
            asprintf(&body,
                     "The PHP bridge ran out of memory capturing the response (%zu bytes captured, "
                     "%zu dropped), so nothing was sent rather than a body with bytes missing.\n",
                     buf->length, buf->dropped);
        }
        jbyteArray result = NULL;
        if (body && asprintf(&response,
                             "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain; charset=utf-8\r\n"
                             "Content-Length: %zu\r\n\r\n%s", strlen(body), body) >= 0) {
            result = bridge_text_response(env, response);
        } else {
            result = bridge_text_response(env, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nResponse too large for the PHP bridge.");
        }
        free(body);
        free(response);
        return result;
    }

    if (!buf || !buf->output) {
        return bridge_new_byte_array(env, "", 0);
    }
    return bridge_new_byte_array(env, buf->output, buf->length);
}

// Copy a Java byte[] into a malloc'd buffer. NULL or empty gives NULL, 0.
static char *bridge_copy_bytes(JNIEnv *env, jbyteArray array, size_t *out_len) {
    *out_len = 0;
    if (!array) return NULL;
    jsize n = (*env)->GetArrayLength(env, array);
    if (n <= 0) return NULL;
    char *copy = malloc((size_t) n + 1);
    if (!copy) {
        LOGE("bridge: out of memory copying a %d byte body", (int) n);
        return NULL;
    }
    (*env)->GetByteArrayRegion(env, array, 0, n, (jbyte *) copy);
    copy[n] = '\0';
    *out_len = (size_t) n;
    return copy;
}

static const char *bridge_utf(JNIEnv *env, jstring s) {
    return s ? (*env)->GetStringUTFChars(env, s, NULL) : NULL;
}

static void bridge_release_utf(JNIEnv *env, jstring s, const char *chars) {
    if (s && chars) (*env)->ReleaseStringUTFChars(env, s, chars);
}

// For the older String-returning entry points: decode the response bytes as
// UTF-8 in Java (lenient, invalid bytes become U+FFFD). Never NewStringUTF,
// which aborts the app under CheckJNI on the first byte that isn't Modified
// UTF-8.
static jstring bridge_bytes_to_jstring(JNIEnv *env, jbyteArray bytes) {
    if (!bytes) return NULL;
    jclass stringClass = (*env)->FindClass(env, "java/lang/String");
    jmethodID ctor = (*env)->GetMethodID(env, stringClass, "<init>", "([BLjava/lang/String;)V");
    jstring charset = (*env)->NewStringUTF(env, "UTF-8");
    jstring result = (jstring) (*env)->NewObject(env, stringClass, ctor, bytes, charset);
    (*env)->DeleteLocalRef(env, charset);
    (*env)->DeleteLocalRef(env, stringClass);
    (*env)->DeleteLocalRef(env, bytes);
    return result;
}

/**
 * Handle a single PHP request in classic mode.
 * Full php_embed_init()/php_embed_shutdown() per request — required for ZTS
 * because each thread needs its own interpreter context with function tables.
 *
 * The body is body_len bytes (NULs allowed). Returns a malloc'd copy of the
 * captured response and its length in *out_len. A capture that overflowed is
 * replaced by a 500 naming the limit, as on the persistent lanes.
 */
char* run_php_request_len(const char* scriptPath, const char* method, const char* uri,
                          const char* body, size_t body_len, const char* contentType,
                          size_t *out_len) {
    LOGI("run_php_request: waiting for mutex (uri=%s)", uri);
    pthread_mutex_lock(&g_php_request_mutex);
    LOGI("run_php_request: mutex acquired (uri=%s)", uri);

    clear_collected_output();

    // Set Laravel-relevant env vars
    setenv("REQUEST_URI", uri, 1);
    setenv("REQUEST_METHOD", method, 1);
    setenv("SCRIPT_FILENAME", scriptPath, 1);
    setenv("PHP_SELF", "/native.php", 1);
    setenv("HTTP_HOST", "127.0.0.1", 1);
    setenv("APP_URL", "http://127.0.0.1", 1);
    setenv("ASSET_URL", "http://127.0.0.1/_assets/", 1);
    setenv("NATIVEPHP_RUNNING", "true", 1);

    // The body's own content type, never a stale one from an earlier request.
    if (contentType && contentType[0]) {
        setenv("CONTENT_TYPE", contentType, 1);
    } else {
        unsetenv("CONTENT_TYPE");
    }

    // Set QUERY_STRING
    const char* query_string = "";
    const char* query_start = strchr(uri, '?');
    if (query_start && strlen(query_start + 1) > 0) {
        query_string = query_start + 1;
        setenv("QUERY_STRING", query_string, 1);
    } else {
        unsetenv("QUERY_STRING");
    }

    // Full init per request — registers host functions
    setup_embed_module();
    if (php_embed_init(0, NULL) != SUCCESS) {
        LOGE("run_php_request: php_embed_init() FAILED");
        pthread_mutex_unlock(&g_php_request_mutex);
        static const char init_failed[] = "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPHP init failed.";
        *out_len = sizeof(init_failed) - 1;
        return strdup(init_failed);
    }
    sapi_module.header_handler = android_header_handler;
    // nativephp extension self-registers via static linking
    php_initialized = 1;

    // Per-request setup and execution
    zend_first_try {
                zend_activate_modules();

                if (strlen(query_string) > 0) {
                    zend_string *query = zend_string_init(query_string, strlen(query_string), 0);
                    sapi_module.treat_data(PARSE_GET, query->val, NULL);
                    zend_string_free(query);
                }

                // Set up POST data and request info
                initialize_php_with_request_bytes(body, body_len, contentType, method, uri);

                // Execute the PHP script
                zend_file_handle fileHandle;
                zend_stream_init_filename(&fileHandle, scriptPath);
                php_execute_script(&fileHandle);

                if (strlen(query_string) > 0) {
                    zend_string *query2 = zend_string_init(query_string, strlen(query_string), 0);
                    sapi_module.treat_data(PARSE_GET, query2->val, NULL);
                    zend_string_free(query2);
                }

            } zend_end_try();

    // Copy output before shutdown, by length
    php_output_buffer_t *buf = get_thread_output_buffer();
    char *response = NULL;
    size_t response_len = 0;
    if (buf && buf->overflowed) {
        char *text = NULL;
        asprintf(&text,
                 "Response too large for the PHP bridge: it is over the %d MB capture limit "
                 "(MAX_BUFFER_SIZE in php_bridge.c), so nothing was sent rather than a body with bytes missing.\n",
                 MAX_BUFFER_SIZE / (1024 * 1024));
        if (text && asprintf(&response,
                             "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain; charset=utf-8\r\n"
                             "Content-Length: %zu\r\n\r\n%s", strlen(text), text) >= 0) {
            response_len = strlen(response);
        } else {
            response = NULL;
        }
        free(text);
    } else if (buf && buf->output) {
        response = malloc(buf->length + 1);
        if (response) {
            memcpy(response, buf->output, buf->length);
            response[buf->length] = '\0';
            response_len = buf->length;
        }
    }
    if (!response) {
        response = strdup("");
        response_len = 0;
    }

    safe_php_embed_shutdown();
    php_initialized = 0;

    LOGI("run_php_request: releasing mutex (uri=%s)", uri);
    pthread_mutex_unlock(&g_php_request_mutex);

    *out_len = response_len;
    return response;
}

/** Text body wrapper kept for older callers. The result is NUL-terminated. */
char* run_php_request(const char* scriptPath, const char* method, const char* uri, const char* postData) {
    size_t len = 0;
    const char *contentType = getenv("HTTP_CONTENT_TYPE");
    return run_php_request_len(scriptPath, method, uri, postData, postData ? strlen(postData) : 0,
                               contentType, &len);
}

// Legacy wrapper for compatibility
char* run_php_script_once(const char* scriptPath, const char* method, const char* uri, const char* postData) {
    return run_php_request(scriptPath, method, uri, postData);
}

// ============================================================================
// Persistent PHP Runtime
// ============================================================================
// Keeps the PHP interpreter alive across requests. Boot once, dispatch many.
// The mutex serializes all access — only one PHP execution at a time.

static int persistent_initialized = 0;

/**
 * Boot the persistent PHP interpreter once.
 * Initializes php_embed, registers native functions, and executes the
 * persistent bootstrap script (which boots Laravel and stores the kernel).
 */
JNIEXPORT jint JNICALL native_persistent_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {
    pthread_mutex_lock(&g_php_request_mutex);

    if (persistent_initialized) {
        LOGI("persistent_boot: already initialized, skipping");
        pthread_mutex_unlock(&g_php_request_mutex);
        return 0;
    }

    const char *bootstrapPath = (*env)->GetStringUTFChars(env, jBootstrapPath, NULL);
    LOGI("persistent_boot: initializing with bootstrap=%s", bootstrapPath);

    clear_collected_output();

    // Set env vars BEFORE php_embed_init so they're available when Laravel boots

    setenv("NATIVEPHP_RUNNING", "true", 1);
    setenv("APP_URL", "http://127.0.0.1", 1);
    setenv("ASSET_URL", "http://127.0.0.1/_assets/", 1);

    // Open the boot gate so any concurrent ephemeral_embed_init call blocks
    // instead of racing into a second php_embed_init().
    set_persistent_boot_state(PERSISTENT_BOOT_IN_PROGRESS);

    setup_embed_module();
    if (php_embed_init(0, NULL) != SUCCESS) {
        LOGE("persistent_boot: php_embed_init() FAILED");
        set_persistent_boot_state(PERSISTENT_BOOT_FAILED);
        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
        pthread_mutex_unlock(&g_php_request_mutex);
        return -1;
    }
    sapi_module.header_handler = android_header_handler;
    // nativephp extension self-registers via static linking
    php_initialized = 1;

    // Execute the persistent bootstrap script (boots Laravel, stores kernel globally)
    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    // Check if bootstrap produced errors
    char *boot_output = get_collected_output();
    if (boot_output && strstr(boot_output, "FATAL") != NULL) {
        LOGE("persistent_boot: bootstrap produced errors: %.200s", boot_output);
    }

    // Verify the bootstrap actually left the runtime in place before blessing
    // the interpreter as booted. php_execute_script gives no usable signal
    // here: a bailed script (bootstrap file missing mid-extraction, fatal
    // during Laravel boot) unwinds through zend_end_try and used to fall
    // through to persistent_initialized = 1 — a ~10ms "boot" with no classes
    // loaded, after which every dispatch 500s with `Class
    // "Native\Mobile\Runtime" not found` until the process dies.
    int boot_verified = 0;
    zend_first_try {
        zval verify_result;
        if (zend_eval_string(
                "(int) (class_exists('Native\\\\Mobile\\\\Runtime', false)"
                " && \\Native\\Mobile\\Runtime::isBooted())",
                &verify_result, "persistent_boot_verify") == SUCCESS) {
            boot_verified = (Z_TYPE(verify_result) == IS_LONG && Z_LVAL(verify_result) == 1);
            zval_ptr_dtor(&verify_result);
        }
    } zend_end_try();

    if (!boot_verified) {
        LOGE("persistent_boot: bootstrap did NOT boot the runtime (Runtime class/boot state missing) — tearing down");
        safe_php_embed_shutdown();
        php_initialized = 0;
        set_persistent_boot_state(PERSISTENT_BOOT_FAILED);
        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
        pthread_mutex_unlock(&g_php_request_mutex);
        return -2;
    }

    persistent_initialized = 1;
    set_persistent_boot_state(PERSISTENT_BOOT_SUCCEEDED);
    LOGI("persistent_boot: PHP interpreter is now persistent and Laravel is booted");

    (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
    pthread_mutex_unlock(&g_php_request_mutex);
    return 0;
}

/**
 * Dispatch a request through the persistent interpreter.
 *
 * The body goes into php://input as exactly body_len bytes. PHP evals one
 * line, BridgeDispatcher::handle(), with the method, URI, script path,
 * cookie, content type and header block passed as base64 arguments rather
 * than spliced into the eval'd code. The response comes back as the capture
 * buffer's bytes and length.
 */
static jbyteArray persistent_dispatch_core(JNIEnv *env,
        const char *method, const char *uri, const char *path,
        const char *body, size_t body_len,
        const char *content_type, const char *cookie,
        const char *headers, size_t headers_len) {

    pthread_mutex_lock(&g_php_request_mutex);

    if (!persistent_initialized) {
        LOGE("persistent_dispatch: runtime not initialized!");
        pthread_mutex_unlock(&g_php_request_mutex);
        return bridge_text_response(env, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPersistent runtime not initialized.");
    }

    LOGI("persistent_dispatch: %s %s (body %zu bytes)", method, uri, body_len);

    clear_collected_output();

    // Process env for this request. BridgeDispatcher copies the non-header
    // env into $_SERVER on this lane; request values themselves come from the
    // dispatch arguments, which win.
    setenv("REQUEST_URI", uri, 1);
    setenv("REQUEST_METHOD", method, 1);
    setenv("SCRIPT_FILENAME", path, 1);
    setenv("PHP_SELF", "/native.php", 1);
    setenv("HTTP_HOST", "127.0.0.1", 1);
    setenv("APP_URL", "http://127.0.0.1", 1);
    setenv("ASSET_URL", "http://127.0.0.1/_assets/", 1);
    setenv("NATIVEPHP_RUNNING", "true", 1);

    const char* query_start = strchr(uri, '?');
    if (query_start && strlen(query_start + 1) > 0) {
        setenv("QUERY_STRING", query_start + 1, 1);
    } else {
        unsetenv("QUERY_STRING");
    }

    bridge_reset_sapi(method, uri);
    bridge_set_request_body(body, body_len);

    jbyteArray result;
    char *code = bridge_dispatch_code("android", "persistent",
                                      method, uri, path,
                                      cookie, content_type,
                                      headers, headers_len);
    if (code) {
        zend_first_try {
            zend_eval_string(code, NULL, "persistent_dispatch");
        } zend_end_try();
        free(code);
        result = bridge_take_response(env);
    } else {
        result = bridge_text_response(env, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nFailed to build persistent dispatch.");
    }

    pthread_mutex_unlock(&g_php_request_mutex);
    return result;
}

/**
 * nativePersistentDispatchBytes: the binary-safe persistent lane.
 * body and headers are byte[] (headers: UTF-8 "Name: value" lines joined by
 * CRLF); either may be null. Returns the raw HTTP response as byte[].
 */
JNIEXPORT jbyteArray JNICALL native_persistent_dispatch_bytes(
        JNIEnv *env, jobject thiz,
        jstring jMethod, jstring jUri, jbyteArray jBody, jstring jContentType,
        jstring jCookie, jbyteArray jHeaders, jstring jScriptPath) {

    const char *method = bridge_utf(env, jMethod);
    const char *uri = bridge_utf(env, jUri);
    const char *content_type = bridge_utf(env, jContentType);
    const char *cookie = bridge_utf(env, jCookie);
    const char *path = bridge_utf(env, jScriptPath);

    size_t body_len = 0, headers_len = 0;
    char *body = bridge_copy_bytes(env, jBody, &body_len);
    char *headers = bridge_copy_bytes(env, jHeaders, &headers_len);

    jbyteArray result = persistent_dispatch_core(env,
            method ? method : "GET", uri ? uri : "/", path ? path : "",
            body, body_len, content_type, cookie, headers, headers_len);

    free(body);
    free(headers);
    bridge_release_utf(env, jMethod, method);
    bridge_release_utf(env, jUri, uri);
    bridge_release_utf(env, jContentType, content_type);
    bridge_release_utf(env, jCookie, cookie);
    bridge_release_utf(env, jScriptPath, path);

    return result;
}

/**
 * nativePersistentDispatch: the older String entry point, kept for callers
 * that still use it. The body is taken as text, the cookie and content type
 * from the env Kotlin set, and no header block is passed. The response is
 * decoded leniently in Java, so it can't abort the app under CheckJNI.
 */
JNIEXPORT jstring JNICALL native_persistent_dispatch(
        JNIEnv *env, jobject thiz,
        jstring jMethod, jstring jUri, jstring jPostData, jstring jScriptPath) {

    const char *method = bridge_utf(env, jMethod);
    const char *uri = bridge_utf(env, jUri);
    const char *post = bridge_utf(env, jPostData);
    const char *path = bridge_utf(env, jScriptPath);

    const char *content_type = getenv("CONTENT_TYPE");
    if (!content_type) content_type = getenv("HTTP_CONTENT_TYPE");

    jbyteArray bytes = persistent_dispatch_core(env,
            method ? method : "GET", uri ? uri : "/", path ? path : "",
            post, post ? strlen(post) : 0,
            content_type, getenv("HTTP_COOKIE"), NULL, 0);

    bridge_release_utf(env, jMethod, method);
    bridge_release_utf(env, jUri, uri);
    bridge_release_utf(env, jPostData, post);
    bridge_release_utf(env, jScriptPath, path);

    return bridge_bytes_to_jstring(env, bytes);
}

/**
 * Run an artisan command through the persistent interpreter.
 * No boot/shutdown — just eval the command through the existing kernel.
 */
JNIEXPORT jstring JNICALL native_persistent_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {
    pthread_mutex_lock(&g_php_request_mutex);

    if (!persistent_initialized) {
        LOGE("persistent_artisan: runtime not initialized!");
        pthread_mutex_unlock(&g_php_request_mutex);
        return (*env)->NewStringUTF(env, "Persistent runtime not initialized.");
    }

    const char *command = (*env)->GetStringUTFChars(env, jCommand, NULL);
    LOGI("persistent_artisan: %s", command);

    clear_collected_output();

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

    setenv("APP_RUNNING_IN_CONSOLE", "false", 1);

    (*env)->ReleaseStringUTFChars(env, jCommand, command);

    char *artisan_output = get_collected_output();
    jstring result = (*env)->NewStringUTF(env, artisan_output ? artisan_output : "");
    pthread_mutex_unlock(&g_php_request_mutex);
    return result;
}

/**
 * Shut down the persistent PHP interpreter.
 * Called on app destroy or before hot-reload reboot.
 */
JNIEXPORT void JNICALL native_persistent_shutdown(JNIEnv *env, jobject thiz) {
    pthread_mutex_lock(&g_php_request_mutex);

    if (!persistent_initialized) {
        LOGI("persistent_shutdown: not initialized, nothing to do");
        pthread_mutex_unlock(&g_php_request_mutex);
        return;
    }

    LOGI("persistent_shutdown: shutting down persistent interpreter");

    // Call Runtime::shutdown() to let PHP clean up
    zend_first_try {
        zend_eval_string("\\Native\\Mobile\\Runtime::shutdown();", NULL, "persistent_shutdown");
    } zend_end_try();

    safe_php_embed_shutdown();
    php_initialized = 0;
    persistent_initialized = 0;
    // Reset the gate so a future ephemeral_embed_init cold path is safe and
    // a subsequent persistent_boot can transition IN_PROGRESS cleanly.
    set_persistent_boot_state(PERSISTENT_BOOT_NEVER_STARTED);

    LOGI("persistent_shutdown: done");
    pthread_mutex_unlock(&g_php_request_mutex);
}

JNIEXPORT void JNICALL native_initialize(JNIEnv *env, jobject thiz) {
    if (g_bridge_instance) {
        (*env)->DeleteGlobalRef(env, g_bridge_instance);
    }
    g_bridge_instance = (*env)->NewGlobalRef(env, thiz);
}


JNIEXPORT jint JNICALL native_set_env(JNIEnv *env, jobject thiz,
                                                            jstring name, jstring value,
                                                            jint overwrite) {

    const char *nameStr = (*env)->GetStringUTFChars(env, name, NULL);
    const char *valueStr = (*env)->GetStringUTFChars(env, value, NULL);

    int result = setenv(nameStr, valueStr, overwrite);

    (*env)->ReleaseStringUTFChars(env, name, nameStr);
    (*env)->ReleaseStringUTFChars(env, value, valueStr);

    return result;
}

JNIEXPORT void JNICALL native_set_request_info(JNIEnv *env, jobject thiz,
                                                     jstring method, jstring uri,
                                                     jstring post_data) {

    const char *methodStr = (*env)->GetStringUTFChars(env, method, NULL);
    const char *uriStr = (*env)->GetStringUTFChars(env, uri, NULL);
    const char *postStr = post_data ? (*env)->GetStringUTFChars(env, post_data, NULL) : "";

    initialize_php_with_request(postStr, methodStr, uriStr);

    (*env)->ReleaseStringUTFChars(env, method, methodStr);
    (*env)->ReleaseStringUTFChars(env, uri, uriStr);
    if (post_data) {
        (*env)->ReleaseStringUTFChars(env, post_data, postStr);
    }
}

JNIEXPORT jstring JNICALL native_run_artisan_command(JNIEnv *env, jobject thiz, jstring jcommand) {
    const char *command = (*env)->GetStringUTFChars(env, jcommand, NULL);
    LOGI("runArtisanCommand: %s", command);

    // Lock ephemeral mutex to prevent background workers from starting
    // ephemeral runtime while artisan commands are running (and vice versa).
    // runArtisanCommand does php_embed_init/shutdown which destroys global
    // state that ephemeral hot path would be using.
    pthread_mutex_lock(&g_ephemeral_mutex);

    clear_collected_output();

    // Get Laravel path
    jclass cls = (*env)->GetObjectClass(env, thiz);
    jmethodID method = (*env)->GetMethodID(env, cls, "getLaravelPublicPath", "()Ljava/lang/String;");
    jstring jLaravelPath = (jstring)(*env)->CallObjectMethod(env, thiz, method);
    const char *cLaravelPath = (*env)->GetStringUTFChars(env, jLaravelPath, NULL);

    // PHP startup must respect any already-initialized TSRM context. When the
    // persistent runtime (or the queue worker) is alive — e.g. a WorkManager
    // scheduler job firing while the app process is still up — php_embed_init()
    // has already run tsrm_startup() process-wide. Calling it again re-runs
    // php_tsrm_startup_ex → zend_ini_refresh_caches → OnUpdateBool against
    // half-initialized globals and SIGSEGVs. g_ephemeral_mutex does not help:
    // native_persistent_boot takes g_php_request_mutex instead. Mirror
    // ephemeral_embed_init(): hot path attaches to the existing TSRM, cold
    // path does a full embed init.
    if (wait_for_persistent_boot_settled(10) != 0) {
        LOGE("runArtisanCommand: timed out waiting for persistent boot to settle");
        pthread_mutex_unlock(&g_ephemeral_mutex);
        (*env)->ReleaseStringUTFChars(env, jcommand, command);
        (*env)->ReleaseStringUTFChars(env, jLaravelPath, cLaravelPath);
        (*env)->DeleteLocalRef(env, jLaravelPath);
        return (*env)->NewStringUTF(env, "");
    }

    int artisan_hot_path = php_initialized;
    if (artisan_hot_path) {
        // Hot path: a PHP runtime is already live on another thread. Allocate a
        // thread-local TSRM context instead of re-running global startup.
        LOGI("runArtisanCommand: hot path — attaching to existing TSRM");
        ts_resource(0);
        setup_embed_module();
        if (php_embed_module.startup(&php_embed_module) == FAILURE) {
            LOGE("runArtisanCommand: hot-path module startup failed");
            pthread_mutex_unlock(&g_ephemeral_mutex);
            (*env)->ReleaseStringUTFChars(env, jcommand, command);
            (*env)->ReleaseStringUTFChars(env, jLaravelPath, cLaravelPath);
            (*env)->DeleteLocalRef(env, jLaravelPath);
            return (*env)->NewStringUTF(env, "");
        }
        if (php_request_startup() == FAILURE) {
            LOGE("runArtisanCommand: hot-path request startup failed");
            php_request_shutdown(NULL);
            ts_free_thread();
            pthread_mutex_unlock(&g_ephemeral_mutex);
            (*env)->ReleaseStringUTFChars(env, jcommand, command);
            (*env)->ReleaseStringUTFChars(env, jLaravelPath, cLaravelPath);
            (*env)->DeleteLocalRef(env, jLaravelPath);
            return (*env)->NewStringUTF(env, "");
        }
        sapi_module.header_handler = android_header_handler;
    } else {
        // Cold path: no live runtime (e.g. install-time setup, or a WorkManager
        // cold start after the app was killed) — full embed init is safe.
        setup_embed_module();
        php_embed_module.ini_entries = "display_errors=1\nimplicit_flush=1\noutput_buffering=0\n";
        if (php_embed_init(0, NULL) != SUCCESS) {
            LOGE("Failed to initialize PHP for artisan");
            pthread_mutex_unlock(&g_ephemeral_mutex);
            (*env)->ReleaseStringUTFChars(env, jcommand, command);
            (*env)->ReleaseStringUTFChars(env, jLaravelPath, cLaravelPath);
            (*env)->DeleteLocalRef(env, jLaravelPath);
            return (*env)->NewStringUTF(env, "");
        }
        sapi_module.header_handler = android_header_handler;
        // nativephp extension self-registers via static linking
        php_initialized = 1;
    }

    char artisanPath[1024];
    snprintf(artisanPath, sizeof(artisanPath), "%s/../artisan.php", cLaravelPath);
    char basePath[1024];
    snprintf(basePath, sizeof(basePath), "%s/..", cLaravelPath);
    chdir(basePath);

    // Tokenize command
    char *argv[128];
    int argc = 0;
    argv[argc++] = "php";

    char *commandCopy = strdup(command);
    char *token = strtok(commandCopy, " ");
    while (token && argc < 127) {
        argv[argc++] = token;
        token = strtok(NULL, " ");
    }
    argv[argc] = NULL;

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);
    setenv("PHP_SELF", "artisan.php", 1);

    // Set $argv/$argc via PHP
    {
        char php_argv_code[4096];
        snprintf(php_argv_code, sizeof(php_argv_code),
            "$_SERVER['argv'] = ['php'");
        size_t offset = strlen(php_argv_code);
        for (int i = 1; i < argc && offset < sizeof(php_argv_code) - 100; i++) {
            offset += snprintf(php_argv_code + offset, sizeof(php_argv_code) - offset,
                ", '%s'", argv[i]);
        }
        snprintf(php_argv_code + offset, sizeof(php_argv_code) - offset,
            "]; $_SERVER['argc'] = %d; "
            "$GLOBALS['argv'] = $_SERVER['argv']; "
            "$GLOBALS['argc'] = $_SERVER['argc']; "
            "if (!defined('STDOUT')) define('STDOUT', fopen('php://output', 'w')); "
            "if (!defined('STDERR')) define('STDERR', fopen('php://output', 'w'));",
            argc);
        zend_eval_string(php_argv_code, NULL, "setup_artisan");
    }

    zend_file_handle file_handle;
    zend_stream_init_filename(&file_handle, artisanPath);
    php_execute_script(&file_handle);

    if (artisan_hot_path) {
        // Tear down only this thread's context — leave the live runtime's
        // global TSRM (and php_initialized) intact.
        php_request_shutdown(NULL);
        ts_free_thread();
    } else {
        safe_php_embed_shutdown();
        php_initialized = 0;
    }

    pthread_mutex_unlock(&g_ephemeral_mutex);

    (*env)->ReleaseStringUTFChars(env, jcommand, command);
    (*env)->ReleaseStringUTFChars(env, jLaravelPath, cLaravelPath);
    (*env)->DeleteLocalRef(env, jLaravelPath);
    free(commandCopy);

    char *cmd_output = get_collected_output();
    return (*env)->NewStringUTF(env, cmd_output ? cmd_output : "");
}

JNIEXPORT jstring JNICALL native_get_laravel_root_path(JNIEnv *env, jobject thiz) {
    // Get context from the PHPBridge instance
    jclass bridgeClass = (*env)->GetObjectClass(env, thiz);
    jfieldID contextFieldId = (*env)->GetFieldID(env, bridgeClass, "context", "Landroid/content/Context;");
    jobject context = (*env)->GetObjectField(env, thiz, contextFieldId);

    // Call getDir method on the context
    jclass contextClass = (*env)->GetObjectClass(env, context);
    jmethodID getDirMethod = (*env)->GetMethodID(env, contextClass, "getDir", "(Ljava/lang/String;I)Ljava/io/File;");
    jstring dirName = (*env)->NewStringUTF(env, "storage");
    jint mode = 0; // MODE_PRIVATE
    jobject storageDir = (*env)->CallObjectMethod(env, context, getDirMethod, dirName, mode);

    // Get the absolute path from the file object
    jclass fileClass = (*env)->GetObjectClass(env, storageDir);
    jmethodID getAbsolutePathMethod = (*env)->GetMethodID(env, fileClass, "getAbsolutePath", "()Ljava/lang/String;");
    jstring storagePath = (jstring) (*env)->CallObjectMethod(env, storageDir, getAbsolutePathMethod);

    // Convert to C string for concatenation
    const char *cStoragePath = (*env)->GetStringUTFChars(env, storagePath, NULL);

    char fullPath[1024];
    sprintf(fullPath, "%s/laravel", cStoragePath);

    // Release resources
    (*env)->ReleaseStringUTFChars(env, storagePath, cStoragePath);
    (*env)->DeleteLocalRef(env, dirName);
    (*env)->DeleteLocalRef(env, storageDir);
    (*env)->DeleteLocalRef(env, storagePath);

    return (*env)->NewStringUTF(env, fullPath);
}

/**
 * nativeHandleRequestBytes: classic mode (full init/shutdown per request),
 * binary-safe. body may be null. Returns the raw response as byte[].
 */
JNIEXPORT jbyteArray JNICALL native_handle_request_bytes(
        JNIEnv *env, jobject thiz,
        jstring jMethod, jstring jUri, jbyteArray jBody, jstring jContentType, jstring jScriptPath) {

    const char *method = bridge_utf(env, jMethod);
    const char *uri = bridge_utf(env, jUri);
    const char *content_type = bridge_utf(env, jContentType);
    const char *path = bridge_utf(env, jScriptPath);

    size_t body_len = 0, out_len = 0;
    char *body = bridge_copy_bytes(env, jBody, &body_len);

    char *output = run_php_request_len(path ? path : "", method ? method : "GET", uri ? uri : "/",
                                       body, body_len, content_type, &out_len);

    jbyteArray result = bridge_new_byte_array(env, output ? output : "", output ? out_len : 0);

    free(output);
    free(body);
    bridge_release_utf(env, jMethod, method);
    bridge_release_utf(env, jUri, uri);
    bridge_release_utf(env, jContentType, content_type);
    bridge_release_utf(env, jScriptPath, path);

    return result;
}

/**
 * nativeHandleRequest: the older String entry point for classic mode. The
 * response is decoded leniently in Java rather than with NewStringUTF.
 */
JNIEXPORT jstring JNICALL native_handle_request(
        JNIEnv *env, jobject thiz,
        jstring jMethod, jstring jUri, jstring jPostData, jstring jScriptPath) {

    const char *method = bridge_utf(env, jMethod);
    const char *uri = bridge_utf(env, jUri);
    const char *post = bridge_utf(env, jPostData);
    const char *path = bridge_utf(env, jScriptPath);

    size_t out_len = 0;
    char *output = run_php_request_len(path ? path : "", method ? method : "GET", uri ? uri : "/",
                                       post, post ? strlen(post) : 0,
                                       getenv("HTTP_CONTENT_TYPE"), &out_len);

    jbyteArray bytes = bridge_new_byte_array(env, output ? output : "", output ? out_len : 0);

    free(output);
    bridge_release_utf(env, jMethod, method);
    bridge_release_utf(env, jUri, uri);
    bridge_release_utf(env, jPostData, post);
    bridge_release_utf(env, jScriptPath, path);

    return bridge_bytes_to_jstring(env, bytes);
}

// JNI entry points for runtime lifecycle
JNIEXPORT void JNICALL native_runtime_init(JNIEnv *env, jobject thiz) {
    if (g_bridge_instance) {
        (*env)->DeleteGlobalRef(env, g_bridge_instance);
    }
    g_bridge_instance = (*env)->NewGlobalRef(env, thiz);
    LOGI("PHP bridge initialized");
}

JNIEXPORT void JNICALL native_runtime_shutdown(JNIEnv *env, jobject thiz) {
    if (g_bridge_instance) {
        (*env)->DeleteGlobalRef(env, g_bridge_instance);
        g_bridge_instance = NULL;
    }
    LOGI("PHP bridge shut down");
}

JNIEXPORT jstring JNICALL native_get_laravel_public_path(JNIEnv *env, jobject thiz) {
    // Get context from the PHPBridge instance
    jclass bridgeClass = (*env)->GetObjectClass(env, thiz);
    jfieldID contextFieldId = (*env)->GetFieldID(env, bridgeClass, "context", "Landroid/content/Context;");
    jobject context = (*env)->GetObjectField(env, thiz, contextFieldId);

    // Call getDir method on the context
    jclass contextClass = (*env)->GetObjectClass(env, context);
    jmethodID getDirMethod = (*env)->GetMethodID(env, contextClass, "getDir", "(Ljava/lang/String;I)Ljava/io/File;");
    jstring dirName = (*env)->NewStringUTF(env, "storage");
    jint mode = 0; // MODE_PRIVATE
    jobject storageDir = (*env)->CallObjectMethod(env, context, getDirMethod, dirName, mode);

    // Get the absolute path from the file object
    jclass fileClass = (*env)->GetObjectClass(env, storageDir);
    jmethodID getAbsolutePathMethod = (*env)->GetMethodID(env, fileClass, "getAbsolutePath", "()Ljava/lang/String;");
    jstring storagePath = (jstring) (*env)->CallObjectMethod(env, storageDir, getAbsolutePathMethod);

    // Convert to C string for concatenation
    const char *cStoragePath = (*env)->GetStringUTFChars(env, storagePath, NULL);
    setenv("APP_RUNNING_IN_CONSOLE", "false", 1);

    char fullPath[1024];
    sprintf(fullPath, "%s/laravel/public", cStoragePath);

    // Release resources
    (*env)->ReleaseStringUTFChars(env, storagePath, cStoragePath);
    (*env)->DeleteLocalRef(env, dirName);
    (*env)->DeleteLocalRef(env, storageDir);
    (*env)->DeleteLocalRef(env, storagePath);

    return (*env)->NewStringUTF(env, fullPath);
}

JNIEXPORT void JNICALL native_shutdown(JNIEnv *env, jobject thiz) {
    if (g_callback_obj) {
        (*env)->DeleteGlobalRef(env, g_callback_obj);
        g_callback_obj = NULL;
    }
    g_callback_method = NULL;

    if (g_bridge_instance) {
        (*env)->DeleteGlobalRef(env, g_bridge_instance);
        g_bridge_instance = NULL;
    }

    // Thread-local output buffer is cleaned up by pthread_key destructor
}

JNIEXPORT jstring JNICALL native_execute_script(JNIEnv *env, jobject thiz, jstring filename) {
    const char *phpFilePath = (*env)->GetStringUTFChars(env, filename, NULL);

    zend_file_handle file_handle;
    zend_stream_init_filename(&file_handle, phpFilePath);

    php_execute_script(&file_handle);

    (*env)->ReleaseStringUTFChars(env, filename, phpFilePath);

    // Return collected output
    char *script_output = get_collected_output();
    return (*env)->NewStringUTF(env, script_output ? script_output : "");
}

// ============================================================================
// Background Queue Worker — separate TSRM context
// ============================================================================
// Runs on its own thread with its own PHP interpreter context.
// Uses ts_resource(0) to allocate thread-local TSRM storage, then
// php_request_startup() to initialize the executor for this thread.
// The main thread's tsrm_startup() has already been called by php_embed_init().

/**
 * Initialize PHP interpreter context for the worker thread.
 * Skips tsrm_startup() (already done by main thread).
 * Allocates a new TSRM context for this thread and starts a request.
 */
static int worker_embed_init(void) {
    LOGI("worker_embed_init: allocating TSRM context for worker thread");

    // Allocate thread-local TSRM storage for this thread
    ts_resource(0);

    // Configure SAPI for worker (uses thread-local ub_write via capture_php_output)
    setup_embed_module();

    // php_module_startup() is guarded by module_initialized — it won't re-init
    // but it will call sapi_activate() for this thread's context
    if (php_embed_module.startup(&php_embed_module) == FAILURE) {
        LOGE("worker_embed_init: module startup failed");
        return FAILURE;
    }

    // Initialize request for this thread (executor, compiler globals)
    if (php_request_startup() == FAILURE) {
        LOGE("worker_embed_init: request startup failed");
        return FAILURE;
    }


    LOGI("worker_embed_init: worker PHP context ready");
    return SUCCESS;
}

/**
 * Shut down worker's PHP context.
 * Only does request shutdown + thread cleanup. Does NOT call php_module_shutdown.
 */
static void worker_embed_shutdown(void) {
    LOGI("worker_embed_shutdown: cleaning up worker thread");
    php_request_shutdown(NULL);
    ts_free_thread();
    LOGI("worker_embed_shutdown: done");
}

/**
 * Boot the worker PHP interpreter and execute the persistent bootstrap script.
 * Called once from the worker thread when it starts.
 */
JNIEXPORT jint JNICALL native_worker_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {
    // Worker's ts_resource(0) assumes tsrm_startup() already ran (inside
    // persistent's php_embed_init). Wait for persistent to settle so we
    // don't race past a half-initialized TSRM.
    if (wait_for_persistent_boot_settled(10) != 0) {
        LOGE("worker_boot: timed out waiting for persistent boot to settle");
        return -1;
    }

    pthread_mutex_lock(&g_worker_mutex);

    if (worker_initialized) {
        LOGI("worker_boot: already initialized, skipping");
        pthread_mutex_unlock(&g_worker_mutex);
        return 0;
    }

    const char *bootstrapPath = (*env)->GetStringUTFChars(env, jBootstrapPath, NULL);
    LOGI("worker_boot: initializing with bootstrap=%s", bootstrapPath);

    clear_collected_output();

    // Set PHP_SELF before boot so $_SERVER['PHP_SELF'] is available during bootstrap
    setenv("PHP_SELF", "artisan.php", 1);
    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);

    if (worker_embed_init() != SUCCESS) {
        LOGE("worker_boot: worker_embed_init() FAILED");
        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
        pthread_mutex_unlock(&g_worker_mutex);
        return -1;
    }

    // Execute the persistent bootstrap script to boot Laravel on worker thread
    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    char *worker_boot_output = get_collected_output();
    if (worker_boot_output && strstr(worker_boot_output, "FATAL") != NULL) {
        LOGE("worker_boot: bootstrap produced errors: %.200s", worker_boot_output);
    }

    worker_initialized = 1;
    LOGI("worker_boot: worker PHP interpreter ready");

    (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
    pthread_mutex_unlock(&g_worker_mutex);
    return 0;
}

/**
 * Run an artisan command on the worker thread.
 * Uses the worker's own TSRM context — does not touch the main thread's mutex.
 */
JNIEXPORT jstring JNICALL native_worker_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {
    pthread_mutex_lock(&g_worker_mutex);

    if (!worker_initialized) {
        LOGE("worker_artisan: worker not initialized!");
        pthread_mutex_unlock(&g_worker_mutex);
        return (*env)->NewStringUTF(env, "Worker runtime not initialized.");
    }

    const char *command = (*env)->GetStringUTFChars(env, jCommand, NULL);
    LOGI("worker_artisan: %s", command);

    clear_collected_output();

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

    (*env)->ReleaseStringUTFChars(env, jCommand, command);

    char *worker_output = get_collected_output();
    jstring result = (*env)->NewStringUTF(env, worker_output ? worker_output : "");
    pthread_mutex_unlock(&g_worker_mutex);
    return result;
}

/**
 * Shut down the worker PHP interpreter.
 * Called when the queue worker thread is stopping.
 */
JNIEXPORT void JNICALL native_worker_shutdown(JNIEnv *env, jobject thiz) {
    pthread_mutex_lock(&g_worker_mutex);

    if (!worker_initialized) {
        LOGI("worker_shutdown: not initialized, nothing to do");
        pthread_mutex_unlock(&g_worker_mutex);
        return;
    }

    LOGI("worker_shutdown: shutting down worker interpreter");

    // Call Runtime::shutdown() to let PHP clean up
    zend_first_try {
        zend_eval_string("\\Native\\Mobile\\Runtime::shutdown();", NULL, "worker_shutdown");
    } zend_end_try();

    worker_embed_shutdown();
    worker_initialized = 0;

    LOGI("worker_shutdown: done");
    pthread_mutex_unlock(&g_worker_mutex);
}

// ============================================================================
// Ephemeral PHP Runtime — separate TSRM context for plugin background work
// ============================================================================
// Generic background PHP context that any plugin can use (e.g. background tasks,
// scheduled jobs). Supports both hot path (app alive) and cold path (WorkManager
// cold start after app killed).

static int ephemeral_embed_init(void) {
    // If persistent is mid-boot (started but not yet finished php_embed_init),
    // wait. Otherwise php_initialized would read 0 and we'd take the cold path
    // — calling php_embed_init() concurrently with the persistent thread, which
    // corrupts TSRM/SAPI globals.
    if (wait_for_persistent_boot_settled(10) != 0) {
        LOGE("ephemeral_embed_init: timed out waiting for persistent boot to settle");
        return FAILURE;
    }

    if (php_initialized) {
        // Hot path: persistent runtime is alive, allocate a TSRM thread context
        LOGI("ephemeral_embed_init: hot path — using existing TSRM");

        ts_resource(0);
        setup_embed_module();

        if (php_embed_module.startup(&php_embed_module) == FAILURE) {
            LOGE("ephemeral_embed_init: module startup failed");
            return FAILURE;
        }

        if (php_request_startup() == FAILURE) {
            LOGE("ephemeral_embed_init: request startup failed");
            return FAILURE;
        }

        ephemeral_cold_booted = 0;

        LOGI("ephemeral_embed_init: hot path ready");
        return SUCCESS;
    }

    // Cold path: WorkManager started the process after app was killed.
    // No persistent runtime exists — do a full php_embed_init().
    LOGI("ephemeral_embed_init: cold path — full PHP bootstrap");

    setenv("NATIVEPHP_RUNNING", "true", 1);
    setenv("APP_URL", "http://127.0.0.1", 1);
    setenv("ASSET_URL", "http://127.0.0.1/_assets/", 1);
    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);
    setenv("PHP_SELF", "/ephemeral", 1);
    setenv("HTTP_HOST", "127.0.0.1", 1);

    setup_embed_module();
    if (php_embed_init(0, NULL) != SUCCESS) {
        LOGE("ephemeral_embed_init: cold path php_embed_init() FAILED");
        return FAILURE;
    }
    sapi_module.header_handler = android_header_handler;
    ephemeral_cold_booted = 1;

    LOGI("ephemeral_embed_init: cold path ready");
    return SUCCESS;
}

static void ephemeral_embed_shutdown(void) {
    if (ephemeral_cold_booted) {
        LOGI("ephemeral_embed_shutdown: cold path — full php_embed_shutdown");
        safe_php_embed_shutdown();
    } else {
        LOGI("ephemeral_embed_shutdown: hot path — thread cleanup only");
        php_request_shutdown(NULL);
        ts_free_thread();
    }
    LOGI("ephemeral_embed_shutdown: done");
}

JNIEXPORT jint JNICALL native_ephemeral_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {
    pthread_mutex_lock(&g_ephemeral_mutex);

    if (ephemeral_initialized) {
        LOGI("ephemeral_boot: already initialized, skipping");
        pthread_mutex_unlock(&g_ephemeral_mutex);
        return 0;
    }

    const char *bootstrapPath = (*env)->GetStringUTFChars(env, jBootstrapPath, NULL);
    LOGI("ephemeral_boot: initializing with bootstrap=%s", bootstrapPath);

    clear_collected_output();

    if (ephemeral_embed_init() != SUCCESS) {
        LOGE("ephemeral_boot: ephemeral_embed_init() FAILED");
        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
        pthread_mutex_unlock(&g_ephemeral_mutex);
        return -1;
    }

    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    char *ephemeral_boot_output = get_collected_output();
    if (ephemeral_boot_output && strstr(ephemeral_boot_output, "FATAL") != NULL) {
        LOGE("ephemeral_boot: bootstrap produced errors: %.200s", ephemeral_boot_output);
    }

    ephemeral_initialized = 1;
    LOGI("ephemeral_boot: ephemeral PHP interpreter ready");

    (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
    pthread_mutex_unlock(&g_ephemeral_mutex);
    return 0;
}

JNIEXPORT jstring JNICALL native_ephemeral_artisan(JNIEnv *env, jobject thiz, jstring jCommand) {
    pthread_mutex_lock(&g_ephemeral_mutex);

    if (!ephemeral_initialized) {
        LOGE("ephemeral_artisan: ephemeral runtime not initialized!");
        pthread_mutex_unlock(&g_ephemeral_mutex);
        return (*env)->NewStringUTF(env, "Ephemeral runtime not initialized.");
    }

    const char *command = (*env)->GetStringUTFChars(env, jCommand, NULL);
    LOGI("ephemeral_artisan: %s", command);

    clear_collected_output();

    setenv("APP_RUNNING_IN_CONSOLE", "true", 1);

    char eval_code[4096];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    echo \\Native\\Mobile\\Runtime::artisan('%s');\n"
        "} catch (\\Throwable $e) {\n"
        "    echo 'Ephemeral artisan error: ' . $e->getMessage();\n"
        "}\n",
        command);

    zend_first_try {
        zend_eval_string(eval_code, NULL, "ephemeral_artisan");
    } zend_end_try();

    setenv("APP_RUNNING_IN_CONSOLE", "false", 1);

    (*env)->ReleaseStringUTFChars(env, jCommand, command);

    char *ephemeral_output = get_collected_output();
    jstring result = (*env)->NewStringUTF(env, ephemeral_output ? ephemeral_output : "");
    pthread_mutex_unlock(&g_ephemeral_mutex);
    return result;
}

JNIEXPORT void JNICALL native_ephemeral_shutdown(JNIEnv *env, jobject thiz) {
    pthread_mutex_lock(&g_ephemeral_mutex);

    if (!ephemeral_initialized) {
        LOGI("ephemeral_shutdown: not initialized, nothing to do");
        pthread_mutex_unlock(&g_ephemeral_mutex);
        return;
    }

    LOGI("ephemeral_shutdown: shutting down ephemeral interpreter");

    zend_first_try {
        zend_eval_string("\\Native\\Mobile\\Runtime::shutdown();", NULL, "ephemeral_shutdown");
    } zend_end_try();

    ephemeral_embed_shutdown();
    ephemeral_initialized = 0;

    LOGI("ephemeral_shutdown: done");
    pthread_mutex_unlock(&g_ephemeral_mutex);
}

// ============================================================================
// Async Task Lane — pool of TSRM contexts for immediate background PHP work
// ============================================================================
// Each pool thread (managed by Kotlin's AsyncTaskExecutor) boots its OWN PHP
// interpreter context once, then runs `native:async:run --id=<id>` for tasks
// handed to it. Unlike the single queue worker this lane is CONCURRENT (N
// threads); unlike ephemeral it is reused across tasks, not torn down each time.
// It never touches a database or the standard queue — task ids arrive from Kotlin,
// and the task payload/result travel via the PHP-side temp-file transport plus
// the AsyncTask.Complete bridge function (which wakes the UI runloop).
//
// "Booted?" is tracked per-thread via a pthread key, so one set of entrypoints
// serves every pool thread with no shared bookkeeping. Boots are serialized
// (g_async_boot_mutex) to avoid concurrent module-startup races (as the webview
// lane does); runs proceed concurrently, each on its own TSRM context.

static pthread_key_t g_async_booted_key;
static pthread_once_t g_async_key_once = PTHREAD_ONCE_INIT;
static pthread_mutex_t g_async_boot_mutex = PTHREAD_MUTEX_INITIALIZER;

static void make_async_booted_key(void) {
    pthread_key_create(&g_async_booted_key, NULL);
}

static int async_thread_booted(void) {
    pthread_once(&g_async_key_once, make_async_booted_key);
    return pthread_getspecific(g_async_booted_key) != NULL;
}

static void set_async_thread_booted(int booted) {
    pthread_once(&g_async_key_once, make_async_booted_key);
    pthread_setspecific(g_async_booted_key, booted ? (void *) 1 : NULL);
}

// Allocate + initialize a PHP context for THIS async pool thread. Same shape as
// worker_embed_init — TSRM is already started by the persistent runtime.
static int async_embed_init(void) {
    ts_resource(0);
    setup_embed_module();
    if (php_embed_module.startup(&php_embed_module) == FAILURE) {
        LOGE("async_embed_init: module startup failed");
        return FAILURE;
    }
    if (php_request_startup() == FAILURE) {
        LOGE("async_embed_init: request startup failed");
        return FAILURE;
    }
    return SUCCESS;
}

static void async_embed_shutdown(void) {
    php_request_shutdown(NULL);
    ts_free_thread();
}

JNIEXPORT jint JNICALL native_async_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {
    if (async_thread_booted()) {
        return 0; // this pool thread already owns a context
    }
    if (wait_for_persistent_boot_settled(10) != 0) {
        LOGE("async_boot: timed out waiting for persistent boot to settle");
        return -1;
    }

    const char *bootstrapPath = (*env)->GetStringUTFChars(env, jBootstrapPath, NULL);
    LOGI("async_boot: booting context on async pool thread");

    // Serialize boots across pool threads — module startup isn't reentrant.
    pthread_mutex_lock(&g_async_boot_mutex);
    clear_collected_output();

    if (async_embed_init() != SUCCESS) {
        LOGE("async_boot: async_embed_init() FAILED");
        pthread_mutex_unlock(&g_async_boot_mutex);
        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
        return -1;
    }

    zend_first_try {
        zend_activate_modules();

        // Console-shaped environment for the bootstrap, set through the
        // superglobal rather than setenv(). This lane is CONCURRENT: several
        // pool threads boot and run at once, and setenv()/getenv() are neither
        // thread-safe nor per-thread — the value one thread sets is the value
        // every other thread (and the UI lane) sees. $_SERVER is per-thread, so
        // each context gets its own copy, and Laravel's Env repository reads it.
        zend_eval_string(
            "$_SERVER['PHP_SELF'] = 'artisan.php';\n"
            "$_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';\n",
            NULL, "async_env");

        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    set_async_thread_booted(1);
    pthread_mutex_unlock(&g_async_boot_mutex);

    (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
    LOGI("async_boot: context ready");
    return 0;
}

JNIEXPORT jstring JNICALL native_async_run(JNIEnv *env, jobject thiz, jstring jTaskId) {
    if (!async_thread_booted()) {
        LOGE("async_run: context not booted on this thread");
        return (*env)->NewStringUTF(env, "async context not booted");
    }

    // taskId is a framework-generated UUID (Str::uuid()), so it's safe to embed
    // in the eval below; no user input reaches this string.
    const char *taskId = (*env)->GetStringUTFChars(env, jTaskId, NULL);
    LOGI("async_run: task %s", taskId);

    clear_collected_output();

    // No setenv() here — see async_boot. The eval sets the per-thread $_SERVER
    // values this lane needs, which is what Laravel reads anyway.
    char eval_code[1024];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    $_SERVER['PHP_SELF'] = 'artisan.php';\n"
        "    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'true';\n"
        "    \\Native\\Mobile\\Runtime::artisan('native:async:run --id=%s');\n"
        "} catch (\\Throwable $e) {\n"
        "    error_log('async run error: ' . $e->getMessage());\n"
        "}\n",
        taskId);

    zend_first_try {
        zend_eval_string(eval_code, NULL, "async_run");
    } zend_end_try();

    (*env)->ReleaseStringUTFChars(env, jTaskId, taskId);

    char *out = get_collected_output();
    return (*env)->NewStringUTF(env, out ? out : "");
}

JNIEXPORT void JNICALL native_async_thread_shutdown(JNIEnv *env, jobject thiz) {
    if (!async_thread_booted()) {
        return;
    }
    zend_first_try {
        zend_eval_string("\\Native\\Mobile\\Runtime::shutdown();", NULL, "async_shutdown");
    } zend_end_try();
    async_embed_shutdown();
    set_async_thread_booted(0);
    LOGI("async_thread_shutdown: done");
}


// ── Webview PHP Runtimes ────────────────────────────
// One dedicated PHP context per embedded php-mode <webview> element. The
// persistent lane (phpExecutor → native_persistent_dispatch) is parked
// inside a native screen's event-loop dispatch for the screen's whole
// lifetime, so it can never answer the embedded webview's requests.
//
// Each Kotlin WebviewPHPRuntime owns a single-thread executor and calls
// these functions only from that thread — so the TSRM context is
// implicitly per-webview (thread == context) and no handle bookkeeping is
// needed. Boots are serialized to avoid concurrent module-startup races.
// Request state is inlined into the eval and carried by per-thread SAPI
// globals — never process env — so contexts run concurrently with the
// persistent lane's env churn without racing it.

static pthread_mutex_t g_webview_boot_mutex = PTHREAD_MUTEX_INITIALIZER;

JNIEXPORT jint JNICALL native_webview_php_boot(JNIEnv *env, jobject thiz, jstring jBootstrapPath) {
    pthread_mutex_lock(&g_webview_boot_mutex);

    const char *bootstrapPath = (*env)->GetStringUTFChars(env, jBootstrapPath, NULL);
    LOGI("webview_php_boot: booting dedicated context");

    clear_collected_output();

    if (ephemeral_embed_init() != SUCCESS) {
        LOGE("webview_php_boot: embed init FAILED");
        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
        pthread_mutex_unlock(&g_webview_boot_mutex);
        return -1;
    }

    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    char *boot_output = get_collected_output();
    if (boot_output && strstr(boot_output, "FATAL") != NULL) {
        LOGE("webview_php_boot: bootstrap errors: %.200s", boot_output);
    }

    (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
    pthread_mutex_unlock(&g_webview_boot_mutex);
    LOGI("webview_php_boot: ready");
    return 0;
}

/**
 * Serve one request on this thread's dedicated webview context. Same shape
 * as persistent_dispatch_core: body into php://input by length, one
 * BridgeDispatcher::handle() line with base64 arguments, bytes back.
 * Runs on the webview's own executor thread, so no global mutex.
 */
static jbyteArray webview_request_core(JNIEnv *env,
        const char *method, const char *uri, const char *script_path,
        const char *cookie, const char *body, size_t body_len,
        const char *content_type, const char *headers, size_t headers_len) {

    clear_collected_output();

    // Per-thread SAPI request state (TSRM-local — safe alongside other lanes)
    bridge_reset_sapi(method, uri);
    bridge_set_request_body(body, body_len);

    char *code = bridge_dispatch_code("android", "webview",
                                      method, uri, script_path,
                                      cookie, content_type,
                                      headers, headers_len);
    if (!code) {
        return bridge_text_response(env, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nFailed to build webview dispatch.");
    }

    zend_first_try {
        zend_eval_string(code, NULL, "webview_dispatch");
    } zend_end_try();
    free(code);

    return bridge_take_response(env);
}

/**
 * nativeWebviewPhpRequestBytes: the binary-safe embedded-webview lane.
 * body and headers are byte[] and may be null. Returns byte[].
 */
JNIEXPORT jbyteArray JNICALL native_webview_php_request_bytes(JNIEnv *env, jobject thiz,
                                                              jstring jMethod, jstring jUri,
                                                              jstring jCookie, jbyteArray jBody,
                                                              jstring jContentType, jbyteArray jHeaders,
                                                              jstring jScriptPath) {
    const char *method = bridge_utf(env, jMethod);
    const char *uri = bridge_utf(env, jUri);
    const char *cookie = bridge_utf(env, jCookie);
    const char *content_type = bridge_utf(env, jContentType);
    const char *script_path = bridge_utf(env, jScriptPath);

    size_t body_len = 0, headers_len = 0;
    char *body = bridge_copy_bytes(env, jBody, &body_len);
    char *headers = bridge_copy_bytes(env, jHeaders, &headers_len);

    jbyteArray result = webview_request_core(env,
            method ? method : "GET", uri ? uri : "/", script_path ? script_path : "",
            cookie, body, body_len, content_type, headers, headers_len);

    free(body);
    free(headers);
    bridge_release_utf(env, jMethod, method);
    bridge_release_utf(env, jUri, uri);
    bridge_release_utf(env, jCookie, cookie);
    bridge_release_utf(env, jContentType, content_type);
    bridge_release_utf(env, jScriptPath, script_path);

    return result;
}

/**
 * nativeWebviewPhpRequest: the older String entry point, kept for callers
 * that still use it. The body is taken as text and no header block is
 * passed; the response is decoded leniently in Java.
 */
JNIEXPORT jstring JNICALL native_webview_php_request(JNIEnv *env, jobject thiz,
                                                     jstring jMethod, jstring jUri,
                                                     jstring jCookie, jstring jBody,
                                                     jstring jContentType, jstring jScriptPath) {
    const char *method = bridge_utf(env, jMethod);
    const char *uri = bridge_utf(env, jUri);
    const char *cookie = bridge_utf(env, jCookie);
    const char *post = bridge_utf(env, jBody);
    const char *content_type = bridge_utf(env, jContentType);
    const char *script_path = bridge_utf(env, jScriptPath);

    jbyteArray bytes = webview_request_core(env,
            method ? method : "GET", uri ? uri : "/", script_path ? script_path : "",
            cookie, post, post ? strlen(post) : 0, content_type, NULL, 0);

    bridge_release_utf(env, jMethod, method);
    bridge_release_utf(env, jUri, uri);
    bridge_release_utf(env, jCookie, cookie);
    bridge_release_utf(env, jBody, post);
    bridge_release_utf(env, jContentType, content_type);
    bridge_release_utf(env, jScriptPath, script_path);

    return bridge_bytes_to_jstring(env, bytes);
}

JNIEXPORT void JNICALL native_webview_php_shutdown(JNIEnv *env, jobject thiz) {
    LOGI("webview_php_shutdown: tearing down dedicated context");

    zend_first_try {
        zend_eval_string(
            "\\Native\\Mobile\\Runtime::shutdown();",
            NULL, "webview_shutdown");
    } zend_end_try();

    // Webview contexts only exist while the persistent runtime is alive, so
    // this is always the hot-path teardown: release this thread's request
    // and TSRM context, leave the process-wide runtime untouched.
    php_request_shutdown(NULL);
    ts_free_thread();

    LOGI("webview_php_shutdown: done");
}

static JNINativeMethod gMethods[] = {
        // PHPBridge
        {"nativeExecuteScript", "(Ljava/lang/String;)Ljava/lang/String;", (void *) native_execute_script},
        {"initialize", "()V", (void *) native_initialize},
        {"shutdown", "()V", (void *) native_shutdown},
        {"nativeRuntimeInit", "()V", (void *) native_runtime_init},
        {"nativeRuntimeShutdown", "()V", (void *) native_runtime_shutdown},
        {"setRequestInfo", "(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V", (void *) native_set_request_info},
        {"runArtisanCommand", "(Ljava/lang/String;)Ljava/lang/String;", (void *) native_run_artisan_command},
        {"getLaravelPublicPath", "()Ljava/lang/String;", (void *) native_get_laravel_public_path},
        {"getLaravelRootPath", "()Ljava/lang/String;", (void *) native_get_laravel_root_path},

        // LaravelEnvironment
        {"nativeSetEnv", "(Ljava/lang/String;Ljava/lang/String;I)I", (void *) native_set_env},
        {"nativeHandleRequest","(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;",(void *) native_handle_request},
        // Legacy name for compat
        {"nativeHandleRequestOnce","(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;",(void *) native_handle_request},
        // Binary-safe classic mode: byte[] body in, byte[] response out
        {"nativeHandleRequestBytes","(Ljava/lang/String;Ljava/lang/String;[BLjava/lang/String;Ljava/lang/String;)[B",(void *) native_handle_request_bytes},

        // Persistent runtime methods
        {"nativePersistentBoot","(Ljava/lang/String;)I",(void *) native_persistent_boot},
        {"nativePersistentDispatch","(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;",(void *) native_persistent_dispatch},
        // method, uri, body, contentType, cookie, headers, scriptPath
        {"nativePersistentDispatchBytes","(Ljava/lang/String;Ljava/lang/String;[BLjava/lang/String;Ljava/lang/String;[BLjava/lang/String;)[B",(void *) native_persistent_dispatch_bytes},
        {"nativePersistentArtisan","(Ljava/lang/String;)Ljava/lang/String;",(void *) native_persistent_artisan},
        {"nativePersistentShutdown","()V",(void *) native_persistent_shutdown},

        // Worker (background queue) methods
        {"nativeWorkerBoot","(Ljava/lang/String;)I",(void *) native_worker_boot},
        {"nativeWorkerArtisan","(Ljava/lang/String;)Ljava/lang/String;",(void *) native_worker_artisan},
        {"nativeWorkerShutdown","()V",(void *) native_worker_shutdown},

        // Ephemeral runtime (background tasks via WorkManager) methods
        {"nativeEphemeralBoot","(Ljava/lang/String;)I",(void *) native_ephemeral_boot},
        {"nativeEphemeralArtisan","(Ljava/lang/String;)Ljava/lang/String;",(void *) native_ephemeral_artisan},
        {"nativeEphemeralShutdown","()V",(void *) native_ephemeral_shutdown},

        // Async task lane (immediate concurrent background PHP work)
        {"nativeAsyncBoot","(Ljava/lang/String;)I",(void *) native_async_boot},
        {"nativeAsyncRun","(Ljava/lang/String;)Ljava/lang/String;",(void *) native_async_run},
        {"nativeAsyncThreadShutdown","()V",(void *) native_async_thread_shutdown},

        // Webview runtime (dedicated context per embedded php-mode webview)
        {"nativeWebviewPhpBoot","(Ljava/lang/String;)I",(void *) native_webview_php_boot},
        {"nativeWebviewPhpRequest","(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;",(void *) native_webview_php_request},
        // method, uri, cookie, body, contentType, headers, scriptPath
        {"nativeWebviewPhpRequestBytes","(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;[BLjava/lang/String;[BLjava/lang/String;)[B",(void *) native_webview_php_request_bytes},
        {"nativeWebviewPhpShutdown","()V",(void *) native_webview_php_shutdown},
};

JNIEXPORT jint JNICALL JNI_OnLoad(JavaVM *vm, void *reserved) {
    g_jvm = vm;

    JNIEnv *env;
    if ((*vm)->GetEnv(vm, (void **) &env, JNI_VERSION_1_6) != JNI_OK) {
        return JNI_ERR;
    }

    // Register native methods for PHPBridge
    jclass phpBridgeClass = (*env)->FindClass(env, "com/nativephp/mobile/bridge/PHPBridge");
    if (phpBridgeClass == NULL) {
        return JNI_ERR;
    }

    if ((*env)->RegisterNatives(env, phpBridgeClass, gMethods, sizeof(gMethods) / sizeof(gMethods[0])) != 0) {
        return JNI_ERR;
    }

    // Register native methods for LaravelEnvironment
    jclass laravelEnvClass = (*env)->FindClass(env, "com/nativephp/mobile/bridge/LaravelEnvironment");
    if (laravelEnvClass == NULL) {
        return JNI_ERR;
    }

    static JNINativeMethod envMethods[] = {
            {"nativeSetEnv", "(Ljava/lang/String;Ljava/lang/String;I)I", (void *) native_set_env}
    };

    if ((*env)->RegisterNatives(env, laravelEnvClass, envMethods, sizeof(envMethods) / sizeof(envMethods[0])) != 0) {
        return JNI_ERR;
    }

    // Initialize the bridge JNI module
    if (InitializeBridgeJNI(env) != JNI_OK) {
        LOGE("Failed to initialize BridgeJNI");
        return JNI_ERR;
    }

    return JNI_VERSION_1_6;
}
