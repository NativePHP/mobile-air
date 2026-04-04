#include <jni.h>
#include <android/log.h>
#include <signal.h>
#include <pthread.h>
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

#define BUFFER_CHUNK_SIZE (256 * 1024)  // 256KB increments
#define MAX_BUFFER_SIZE (16 * 1024 * 1024)  // 16MB max buffer

// Thread-local output buffer
typedef struct {
    char *output;
    size_t length;
    size_t capacity;
} php_output_buffer_t;

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
    php_embed_module.ini_entries = "output_buffering=4096\n"
                                   "implicit_flush=0\n"
                                   "display_errors=1\n"
                                   "error_reporting=E_ALL\n";
    php_embed_module.header_handler = android_header_handler;
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
    buf->output = (char *) malloc(buf->capacity);
    if (buf->output) {
        buf->output[0] = '\0';
    }
}

static char *get_collected_output(void) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    return buf ? buf->output : NULL;
}

void pipe_php_output(const char *str) {
    php_output_buffer_t *buf = get_thread_output_buffer();
    if (!buf) return;

    // Safety check
    if (!buf->output) {
        clear_collected_output();
        return;  // Failed to allocate
    }

    size_t length = strlen(str);

    // Check if we need more space
    if (buf->length + length + 1 > buf->capacity) {
        // Calculate new size in chunks
        size_t needed_capacity = buf->capacity;
        while (needed_capacity < buf->length + length + 1) {
            needed_capacity += BUFFER_CHUNK_SIZE;
        }

        // Enforce maximum size limit
        if (needed_capacity > MAX_BUFFER_SIZE) {
            LOGE("Output buffer exceeded maximum size of %d MB", MAX_BUFFER_SIZE / (1024 * 1024));
            return;
        }

        // Reallocate with the new size
        char *new_buffer = (char *) realloc(buf->output, needed_capacity);
        if (new_buffer) {
            buf->output = new_buffer;
            buf->capacity = needed_capacity;
        } else {
            LOGE("Failed to reallocate output buffer to %zu bytes", needed_capacity);
            return;
        }
    }

    // Append the string
    strcpy(buf->output + buf->length, str);
    buf->length += length;
}

void cleanup_output_buffer() {
    php_output_buffer_t *buf = get_thread_output_buffer();
    if (buf && buf->output) {
        buf->output[0] = '\0';
        buf->length = 0;
    }
}

size_t capture_php_output(const char *str, size_t str_length) {
    if (str_length == 0) {
        LOGI("Empty output received");
        return 0;
    }

    char *buffer = malloc(str_length + 1);
    if (buffer) {
        memcpy(buffer, str, str_length);
        buffer[str_length] = '\0';

        pipe_php_output(buffer);
        free(buffer);
    }

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

/**
 * Handle a single PHP request.
 * Full php_embed_init()/php_embed_shutdown() per request — required for ZTS
 * because each thread needs its own interpreter context with function tables.
 */
char* run_php_request(const char* scriptPath, const char* method, const char* uri, const char* postData) {
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
        return strdup("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPHP init failed.");
    }
    sapi_module.header_handler = android_header_handler;
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
                initialize_php_with_request(postData ?: "", method, uri);

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

    // Copy output before shutdown
    char *collected = get_collected_output();
    char *response = collected ? strdup(collected) : strdup("");

    safe_php_embed_shutdown();
    php_initialized = 0;

    LOGI("run_php_request: releasing mutex (uri=%s)", uri);
    pthread_mutex_unlock(&g_php_request_mutex);

    return response;
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

    setup_embed_module();
    if (php_embed_init(0, NULL) != SUCCESS) {
        LOGE("persistent_boot: php_embed_init() FAILED");
        (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
        pthread_mutex_unlock(&g_php_request_mutex);
        return -1;
    }
    sapi_module.header_handler = android_header_handler;
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

    persistent_initialized = 1;
    LOGI("persistent_boot: PHP interpreter is now persistent and Laravel is booted");

    (*env)->ReleaseStringUTFChars(env, jBootstrapPath, bootstrapPath);
    pthread_mutex_unlock(&g_php_request_mutex);
    return 0;
}

/**
 * Dispatch a request through the persistent interpreter.
 * Sets env vars, calls Runtime::dispatch() via zend_eval_string, captures output.
 */
JNIEXPORT jstring JNICALL native_persistent_dispatch(
        JNIEnv *env, jobject thiz,
        jstring jMethod, jstring jUri, jstring jPostData, jstring jScriptPath) {

    pthread_mutex_lock(&g_php_request_mutex);

    if (!persistent_initialized) {
        LOGE("persistent_dispatch: runtime not initialized!");
        pthread_mutex_unlock(&g_php_request_mutex);
        return (*env)->NewStringUTF(env, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPersistent runtime not initialized.");
    }

    const char *method = (*env)->GetStringUTFChars(env, jMethod, NULL);
    const char *uri = (*env)->GetStringUTFChars(env, jUri, NULL);
    const char *post = jPostData ? (*env)->GetStringUTFChars(env, jPostData, NULL) : "";
    const char *path = (*env)->GetStringUTFChars(env, jScriptPath, NULL);

    LOGI("persistent_dispatch: %s %s", method, uri);

    clear_collected_output();

    // Set env vars for this request
    setenv("REQUEST_URI", uri, 1);
    setenv("REQUEST_METHOD", method, 1);
    setenv("SCRIPT_FILENAME", path, 1);
    setenv("PHP_SELF", "/native.php", 1);
    setenv("HTTP_HOST", "127.0.0.1", 1);
    setenv("APP_URL", "http://127.0.0.1", 1);
    setenv("ASSET_URL", "http://127.0.0.1/_assets/", 1);
    setenv("NATIVEPHP_RUNNING", "true", 1);

    // Set QUERY_STRING
    const char* query_string = "";
    const char* query_start = strchr(uri, '?');
    if (query_start && strlen(query_start + 1) > 0) {
        query_string = query_start + 1;
        setenv("QUERY_STRING", query_string, 1);
    } else {
        unsetenv("QUERY_STRING");
    }

    // Reset SAPI state from previous dispatch.
    SG(headers_sent) = 0;
    SG(post_read) = 0;
    SG(read_post_bytes) = 0;
    SG(request_info).request_method = method;
    SG(request_info).request_uri = (char *)uri;
    SG(request_info).proto_num = 1001; // HTTP/1.1

    // Reset SAPI headers for fresh response
    memset(&SG(sapi_headers), 0, sizeof(sapi_headers_struct));
    SG(sapi_headers).http_response_code = 200;
    zend_llist_init(&SG(sapi_headers).headers, sizeof(sapi_header_struct), NULL, 0);

    if (post && strlen(post) > 0) {
        // Create a memory stream with the POST data for php://input
        php_stream *post_stream = php_stream_memory_create(TEMP_STREAM_DEFAULT);
        if (post_stream) {
            php_stream_write(post_stream, post, strlen(post));
            php_stream_seek(post_stream, 0, SEEK_SET);

            if (SG(request_info).request_body) {
                php_stream_close(SG(request_info).request_body);
            }
            SG(request_info).request_body = post_stream;
            SG(request_info).content_length = strlen(post);

            const char *content_type = getenv("CONTENT_TYPE");
            if (!content_type) content_type = getenv("HTTP_CONTENT_TYPE");
            if (content_type && strstr(content_type, "json")) {
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

    // Build the dispatch call — Runtime::dispatch() handles everything
    char eval_code[8192];
    snprintf(eval_code, sizeof(eval_code),
        "try {\n"
        "    // Clean PHP output buffers from previous dispatch\n"
        "    while (ob_get_level() > 0) { ob_end_clean(); }\n"
        "\n"
        "    // Sync $_SERVER from current env (setenv in C doesn't update PHP $_SERVER)\n"
        "    $_SERVER['REQUEST_METHOD'] = '%s';\n"
        "    $_SERVER['REQUEST_URI'] = '%s';\n"
        "    $_SERVER['SCRIPT_FILENAME'] = '%s';\n"
        "    $_SERVER['PHP_SELF'] = '/native.php';\n"
        "    $_SERVER['HTTP_HOST'] = '127.0.0.1';\n"
        "    $_SERVER['SERVER_NAME'] = '127.0.0.1';\n"
        "    $_SERVER['SERVER_PORT'] = '80';\n"
        "    $_SERVER['APP_URL'] = 'http://127.0.0.1';\n"
        "    $_SERVER['NATIVEPHP_RUNNING'] = 'true';\n"
        "\n"
        "    // Sync ALL env vars into $_SERVER (C setenv() doesn't update PHP $_SERVER)\n"
        "    foreach (getenv() as $__k => $__v) {\n"
        "        $_SERVER[$__k] = $__v;\n"
        "    }\n"
        "\n"
        "    // Ensure CONTENT_TYPE is set without HTTP_ prefix (CGI convention)\n"
        "    if (isset($_SERVER['HTTP_CONTENT_TYPE'])) {\n"
        "        $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'];\n"
        "    }\n"
        "    if (isset($_SERVER['HTTP_CONTENT_LENGTH'])) {\n"
        "        $_SERVER['CONTENT_LENGTH'] = $_SERVER['HTTP_CONTENT_LENGTH'];\n"
        "    }\n"
        "\n"
        "    // Set QUERY_STRING from the URI\n"
        "    $__qpos = strpos($_SERVER['REQUEST_URI'], '?');\n"
        "    if ($__qpos !== false) {\n"
        "        $_SERVER['QUERY_STRING'] = substr($_SERVER['REQUEST_URI'], $__qpos + 1);\n"
        "    } else {\n"
        "        $_SERVER['QUERY_STRING'] = '';\n"
        "    }\n"
        "\n"
        "    // Reset superglobals for this request\n"
        "    $_GET = [];\n"
        "    $_POST = [];\n"
        "    $_COOKIE = [];\n"
        "    $_FILES = [];\n"
        "    $_REQUEST = [];\n"
        "\n"
        "    // Parse cookies\n"
        "    if (isset($_SERVER['HTTP_COOKIE']) && $_SERVER['HTTP_COOKIE'] !== '') {\n"
        "        foreach (explode('; ', $_SERVER['HTTP_COOKIE']) as $__pair) {\n"
        "            $__parts = explode('=', $__pair, 2);\n"
        "            if (count($__parts) === 2) {\n"
        "                $_COOKIE[$__parts[0]] = urldecode($__parts[1]);\n"
        "            }\n"
        "        }\n"
        "    }\n"
        "\n"
        "    // Parse query string\n"
        "    if ($_SERVER['QUERY_STRING'] !== '') {\n"
        "        parse_str($_SERVER['QUERY_STRING'], $_GET);\n"
        "    }\n"
        "\n"
        "    // Parse POST body into $_POST for form-urlencoded requests\n"
        "    // php://input is set up by the C layer but $_POST was cleared above\n"
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
        method, uri, path);

    zend_first_try {
        zend_eval_string(eval_code, NULL, "persistent_dispatch");
    } zend_end_try();

    char *dispatch_output = get_collected_output();
    char *response = dispatch_output ? strdup(dispatch_output) : strdup("");

    (*env)->ReleaseStringUTFChars(env, jMethod, method);
    (*env)->ReleaseStringUTFChars(env, jUri, uri);
    if (jPostData) (*env)->ReleaseStringUTFChars(env, jPostData, post);
    (*env)->ReleaseStringUTFChars(env, jScriptPath, path);

    jstring result = (*env)->NewStringUTF(env, response);
    free(response);

    pthread_mutex_unlock(&g_php_request_mutex);
    return result;
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

    // Full init per artisan command
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
    php_initialized = 1;

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

    safe_php_embed_shutdown();
    php_initialized = 0;

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

JNIEXPORT jstring JNICALL native_handle_request(
        JNIEnv *env, jobject thiz,
        jstring jMethod, jstring jUri, jstring jPostData, jstring jScriptPath) {

    const char *method = (*env)->GetStringUTFChars(env, jMethod, NULL);
    const char *uri = (*env)->GetStringUTFChars(env, jUri, NULL);
    const char *post = jPostData ? (*env)->GetStringUTFChars(env, jPostData, NULL) : "";
    const char *path = (*env)->GetStringUTFChars(env, jScriptPath, NULL);

    char *output = run_php_request(path, method, uri, post);

    jstring result = (*env)->NewStringUTF(env, output ? output : "");

    // Clean up
    free(output);
    (*env)->ReleaseStringUTFChars(env, jMethod, method);
    (*env)->ReleaseStringUTFChars(env, jUri, uri);
    (*env)->ReleaseStringUTFChars(env, jScriptPath, path);
    if (jPostData) (*env)->ReleaseStringUTFChars(env, jPostData, post);

    return result;
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

        // Persistent runtime methods
        {"nativePersistentBoot","(Ljava/lang/String;)I",(void *) native_persistent_boot},
        {"nativePersistentDispatch","(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;",(void *) native_persistent_dispatch},
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
