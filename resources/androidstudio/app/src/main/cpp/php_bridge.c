#include <jni.h>
#include <android/log.h>
#include <signal.h>
#include <pthread.h>
#include "php_embed.h"
#include "PHP.h"
#include "native_functions.h"
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
int android_header_handler(sapi_header_struct *sapi_header, sapi_header_op_enum op, sapi_headers_struct *sapi_headers);

// Global state
static int php_initialized = 0;    // tracks whether php_embed_init is active
static pthread_mutex_t g_php_request_mutex = PTHREAD_MUTEX_INITIALIZER;
static jobject g_callback_obj = NULL;
static jmethodID g_callback_method = NULL;
static char *g_collected_output = NULL;
static size_t g_collected_length = 0;
static size_t g_collected_capacity = 0;

#define BUFFER_CHUNK_SIZE (256 * 1024)  // 256KB increments
#define MAX_BUFFER_SIZE (16 * 1024 * 1024)  // 16MB max buffer

static void (*jni_output_callback_ptr)(const char *) = NULL;

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

    // Register host-provided PHP functions (nativephp_call, nativephp_element_*, etc.)
    php_embed_module.additional_functions = nativephp_functions;
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
    if (g_collected_output) {
        free(g_collected_output);
        g_collected_output = NULL;
    }

    g_collected_capacity = BUFFER_CHUNK_SIZE;
    g_collected_length = 0;
    g_collected_output = (char *) malloc(g_collected_capacity);
    if (g_collected_output) {
        g_collected_output[0] = '\0';
    }
}


void pipe_php_output(const char *str) {

//    LOGI("PIPE: Output received: %s", str);

    // Safety check
    if (!g_collected_output) {
        clear_collected_output();
        return;  // Failed to allocate
    }

    size_t length = strlen(str);

    // Check if we need more space
    if (g_collected_length + length + 1 > g_collected_capacity) {
        // Calculate new size in chunks
        size_t needed_capacity = g_collected_capacity;
        while (needed_capacity < g_collected_length + length + 1) {
            needed_capacity += BUFFER_CHUNK_SIZE;
        }

        // Enforce maximum size limit
        if (needed_capacity > MAX_BUFFER_SIZE) {
            LOGE("Output buffer exceeded maximum size of %d MB", MAX_BUFFER_SIZE / (1024 * 1024));
            // Just return and drop output beyond this point
            return;
        }

        // Reallocate with the new size
        char *new_buffer = (char *) realloc(g_collected_output, needed_capacity);
        if (new_buffer) {
            g_collected_output = new_buffer;
            g_collected_capacity = needed_capacity;
        } else {
            LOGE("Failed to reallocate output buffer to %zu bytes", needed_capacity);
            return;  // Failed to reallocate
        }
    }

    // Append the string
    strcpy(g_collected_output + g_collected_length, str);
    g_collected_length += length;
}

void cleanup_output_buffer() {
    if (g_collected_output) {
        g_collected_output[0] = '\0';
        g_collected_length = 0;
    }
}

size_t capture_php_output(const char *str, size_t str_length) {
    // Log the raw output coming from PHP
//    LOGI("PHP output captured: length=%zu", str_length);
    if (str_length > 0) {
        // Log a preview of the output (first 100 chars or so)
        char preview[10001] = {0};
        strncpy(preview, str, str_length > 10000 ? 10000 : str_length);
        preview[10000] = '\0'; // Ensure null termination
    } else {
        LOGI("Empty output received");
    }

    // Rest of your original code...
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
//    LOGI("PHP Output Debug - Callback called with: %s", output);

    JNIEnv *env;
    if ((*g_jvm)->GetEnv(g_jvm, (void **) &env, JNI_VERSION_1_6) != JNI_OK) {
        LOGE("Failed to get JNI environment");
        return;
    }

    if (g_callback_obj && g_callback_method) {
        LOGI("WE MADE IT HERE");
        jstring joutput = (*env)->NewStringUTF(env, output);
        (*env)->CallVoidMethod(env, g_callback_obj, g_callback_method, joutput);
        (*env)->DeleteLocalRef(env, joutput);
    }

}

int android_header_handler(sapi_header_struct *sapi_header, sapi_header_op_enum op, sapi_headers_struct *sapi_headers) {
    LOGI("📤 SAPI header: %s", sapi_header->header);
    // You can collect headers here if you want
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
    // Explicitly register host functions into the active interpreter context
    zend_register_functions(NULL, nativephp_functions, NULL, MODULE_PERSISTENT);
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
    char *response = g_collected_output ? strdup(g_collected_output) : strdup("");

    safe_php_embed_shutdown();
    php_initialized = 0;

    LOGI("run_php_request: releasing mutex (uri=%s)", uri);
    pthread_mutex_unlock(&g_php_request_mutex);

    return response;
}

// Legacy wrapper for compatibility during transition
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
    // (registerFilesystems checks NATIVEPHP_RUNNING during packageBooted)
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
    zend_register_functions(NULL, nativephp_functions, NULL, MODULE_PERSISTENT);
    php_initialized = 1;

    // Execute the persistent bootstrap script (boots Laravel, stores kernel globally)
    zend_first_try {
        zend_activate_modules();
        zend_file_handle fileHandle;
        zend_stream_init_filename(&fileHandle, bootstrapPath);
        php_execute_script(&fileHandle);
    } zend_end_try();

    // Check if bootstrap produced errors
    if (g_collected_output && strstr(g_collected_output, "FATAL") != NULL) {
        LOGE("persistent_boot: bootstrap produced errors: %.200s", g_collected_output);
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
    // We can't call php_request_startup() (it reinitializes too much),
    // so we manually reset the flags that matter:
    SG(headers_sent) = 0;           // Allow headers to be sent again
    SG(post_read) = 0;              // Allow php://input to be read again
    SG(read_post_bytes) = 0;        // Reset POST byte counter
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
            // Rewind so PHP can read from the beginning
            php_stream_seek(post_stream, 0, SEEK_SET);

            // Clean up any previous request body
            if (SG(request_info).request_body) {
                php_stream_close(SG(request_info).request_body);
            }
            SG(request_info).request_body = post_stream;
            SG(request_info).content_length = strlen(post);

            // Check both CONTENT_TYPE and HTTP_CONTENT_TYPE (Kotlin prefixes all headers with HTTP_)
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
    // We must update $_SERVER directly since setenv() in C doesn't affect PHP's $_SERVER
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
        "    // getenv() without args returns all current process env vars including those set by C\n"
        "    foreach (getenv() as $__k => $__v) {\n"
        "        $_SERVER[$__k] = $__v;\n"
        "    }\n"
        "\n"
        "    // Ensure CONTENT_TYPE is set without HTTP_ prefix (CGI convention)\n"
        "    // Symfony/Laravel reads $_SERVER['CONTENT_TYPE'], not HTTP_CONTENT_TYPE\n"
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

    char *response = g_collected_output ? strdup(g_collected_output) : strdup("");

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

    jstring result = (*env)->NewStringUTF(env, g_collected_output ? g_collected_output : "");
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
    LOGI("🛠️ runArtisanCommand: %s", command);

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
        (*env)->ReleaseStringUTFChars(env, jcommand, command);
        (*env)->ReleaseStringUTFChars(env, jLaravelPath, cLaravelPath);
        (*env)->DeleteLocalRef(env, jLaravelPath);
        return (*env)->NewStringUTF(env, "");
    }
    sapi_module.header_handler = android_header_handler;
    // Explicitly register host functions into the active interpreter context
    zend_register_functions(NULL, nativephp_functions, NULL, MODULE_PERSISTENT);
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
    setenv("APP_ENV", "local", 1);

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

    (*env)->ReleaseStringUTFChars(env, jcommand, command);
    (*env)->ReleaseStringUTFChars(env, jLaravelPath, cLaravelPath);
    (*env)->DeleteLocalRef(env, jLaravelPath);
    free(commandCopy);

    return (*env)->NewStringUTF(env, g_collected_output ? g_collected_output : "");
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

    // Concatenate with "/laravel/public"
    char fullPath[1024];
    sprintf(fullPath, "%s/laravel", cStoragePath);

    // Release resources
    (*env)->ReleaseStringUTFChars(env, storagePath, cStoragePath);
    (*env)->DeleteLocalRef(env, dirName);
    (*env)->DeleteLocalRef(env, storageDir);
    (*env)->DeleteLocalRef(env, storagePath);

    // Return the final path
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

// JNI entry points for runtime lifecycle (kept for Kotlin compat)
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

    // Concatenate with "/laravel/public"
    char fullPath[1024];
    sprintf(fullPath, "%s/laravel/public", cStoragePath);

    // Release resources
    (*env)->ReleaseStringUTFChars(env, storagePath, cStoragePath);
    (*env)->DeleteLocalRef(env, dirName);
    (*env)->DeleteLocalRef(env, storageDir);
    (*env)->DeleteLocalRef(env, storagePath);

    // Return the final path
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

    // Free the collected output buffer
    if (g_collected_output) {
        free(g_collected_output);
        g_collected_output = NULL;
        g_collected_length = 0;
        g_collected_capacity = 0;
    }
}

JNIEXPORT jstring JNICALL native_execute_script(JNIEnv *env, jobject thiz, jstring filename) {
    const char *phpFilePath = (*env)->GetStringUTFChars(env, filename, NULL);

    zend_file_handle file_handle;
    zend_stream_init_filename(&file_handle, phpFilePath);

    php_execute_script(&file_handle);

    (*env)->ReleaseStringUTFChars(env, filename, phpFilePath);

    // Return collected output
    return (*env)->NewStringUTF(env, g_collected_output ? g_collected_output : "");
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
        {"nativePersistentShutdown","()V",(void *) native_persistent_shutdown}
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
