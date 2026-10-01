#include "PHP.h"
#include <android/log.h>
#include <string.h>
#include <stdlib.h>

#define LOG_TAG "PHP-Native"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)
static php_stream *g_stdout_stream = NULL;
extern void append_output(const char* str, size_t length);

void initialize_php_with_request(const char *post_data, const char *method, const char *uri) {
    initialize_php_with_request_bytes(post_data, post_data ? strlen(post_data) : 0,
                                      getenv("CONTENT_TYPE"), method, uri);
}

void initialize_php_with_request_bytes(const char *body, size_t body_len, const char *content_type,
                                       const char *method, const char *uri) {
    LOGI("🛠️ Starting PHP request startup");
    LOGI("🐛 initialize_php_with_request called with method=%s uri=%s body=%zu bytes", method, uri, body_len);

    // Step 1: Bootstrap PHP internals (superglobals, session, etc)
    if (php_request_startup() == FAILURE) {
        LOGE("php_request_startup() failed");
        return;
    }
    LOGI("✅ php_request_startup() completed");

    // Step 2: Populate $_SERVER
    zval server_array;
    array_init(&server_array);

    add_assoc_string(&server_array, "REQUEST_METHOD", (char*)method);
    add_assoc_string(&server_array, "REQUEST_URI", (char*)uri);
    add_assoc_string(&server_array, "SCRIPT_NAME", "/native.php");
    add_assoc_string(&server_array, "SCRIPT_FILENAME", "/native.php");
    add_assoc_string(&server_array, "SERVER_PROTOCOL", "HTTP/1.1");
    add_assoc_string(&server_array, "SERVER_NAME", "127.0.0.1");
    add_assoc_string(&server_array, "SERVER_PORT", "80");
    add_assoc_string(&server_array, "REMOTE_ADDR", "127.0.0.1");
    add_assoc_string(&server_array, "PHP_SELF", "/native.php");
    add_assoc_string(&server_array, "REQUEST_SCHEME", "http");
    add_assoc_string(&server_array, "HTTP_HOST", "127.0.0.1");
    add_assoc_string(&server_array, "HTTPS", "off");
    add_assoc_string(&server_array, "HTTP_USER_AGENT", "PHPNative/1.0");
    add_assoc_long(&server_array, "REQUEST_TIME", time(NULL));
    if (content_type && content_type[0]) {
        add_assoc_string(&server_array, "CONTENT_TYPE", (char*)content_type);
    }
    if (body_len > 0) {
        add_assoc_long(&server_array, "CONTENT_LENGTH", (zend_long) body_len);
    }

    zend_hash_str_update(&EG(symbol_table), "_SERVER", sizeof("_SERVER") - 1, &server_array);
    LOGI("✅ $_SERVER populated");

    // Step 3: Populate $_COOKIE from HTTP_COOKIE env var
    const char* cookie_header = getenv("HTTP_COOKIE");
    if (cookie_header) {
        LOGI("🍪 HTTP_COOKIE from env: %s", cookie_header);

        zval cookie_array;
        array_init(&cookie_array);

        char* cookies = strdup(cookie_header);
        char* token = strtok(cookies, ";");
        while (token) {
            while (*token == ' ') token++;
            char* equal = strchr(token, '=');
            if (equal) {
                *equal = '\0';
                const char* key = token;
                const char* val = equal + 1;
                LOGI("🍪 Parsed cookie → %s = %s", key, val);
                add_assoc_string(&cookie_array, key, val);
            } else {
                LOGI("⚠️ Skipping malformed cookie token: %s", token);
            }
            token = strtok(NULL, ";");
        }
        free(cookies);

        zend_hash_str_update(&EG(symbol_table), "_COOKIE", sizeof("_COOKIE") - 1, &cookie_array);
        LOGI("✅ $_COOKIE populated from HTTP_COOKIE");
    } else {
        LOGI("🚫 No HTTP_COOKIE found in environment");
    }

    // Step 4: Setup PHP output buffer (stdout stream)
    LOGI("🌀 Setting up memory output stream");
    g_stdout_stream = php_stream_memory_create(TEMP_STREAM_DEFAULT);
    if (!g_stdout_stream) {
        LOGE("❌ Failed to create STDOUT memory stream");
        return;
    }

    zend_string *stdout_name = zend_string_init("STDOUT", sizeof("STDOUT") - 1, 0);
    zval stdout_handle;
    php_stream_to_zval(g_stdout_stream, &stdout_handle);
    zend_hash_add(&EG(symbol_table), stdout_name, &stdout_handle);
    zend_string_release(stdout_name);
    LOGI("✅ STDOUT memory stream ready");

    php_output_activate();

    // Step 5: Put the exact body bytes into php://input. The content type is
    // left NULL in SAPI so PHP's own body parser never runs on this SAPI (it
    // has no read_post); $_SERVER['CONTENT_TYPE'] carries the real one.
    SG(request_info).content_type = NULL;
    if (body && body_len > 0) {
        LOGI("📮 Request body: %zu bytes, type %s", body_len, content_type ? content_type : "(none)");

        php_stream *mem_stream = php_stream_memory_create(TEMP_STREAM_DEFAULT);
        php_stream_write(mem_stream, body, body_len);
        php_stream_seek(mem_stream, 0, SEEK_SET);

        SG(request_info).request_body = mem_stream;
        SG(request_info).content_length = (zend_long) body_len;
    }


    // Finalize request startup state
    SG(request_info).request_uri = strdup(uri);
    SG(request_info).request_method = method;
    PG(during_request_startup) = 0;
    EG(exit_status) = 0;
}


// Add this new function to read output from the stdout stream
// Function to capture stdout content after PHP execution
void capture_php_stdout_output() {
    if (!g_stdout_stream) {
        LOGI("No stdout stream to capture");
        return;
    }

    // Flush the stream to make sure all data is in memory
    php_stream_flush(g_stdout_stream);

    // Get the length of data in the stream
    php_stream_seek(g_stdout_stream, 0, SEEK_END);
    size_t size = php_stream_tell(g_stdout_stream);

    if (size > 0) {
        // Allocate buffer for the data
        char *buffer = (char*)malloc(size + 1);
        if (buffer) {
            // Rewind to beginning
            php_stream_rewind(g_stdout_stream);

            // Read all data
            size_t bytes_read = php_stream_read(g_stdout_stream, buffer, size);
            buffer[bytes_read] = '\0';

            LOGI("Captured %zu bytes from stdout stream", bytes_read);

            // Send to our output collector, by length
            append_output(buffer, bytes_read);

            free(buffer);
        }
    } else {
        LOGI("Stdout stream is empty");
    }
}

static sapi_module_struct php_module = {
        "android",                     // name
        "Android Embedded PHP",        // pretty name

        NULL,                         // startup
        php_module_shutdown_wrapper,   // shutdown

        NULL,                         // activate
        NULL,                         // deactivate

        capture_php_output,           // unbuffered write
        NULL,                         // flush
        NULL,                         // get uid
        NULL,                         // getenv

        NULL,                         // sapi error handler
        NULL,                         // header handler
        NULL,                         // send headers handler
        NULL,                         // send header handler

        NULL,                         // read POST data
        NULL,                         // read Cookies

        NULL,                         // register server variables
        NULL,                         // log message
        NULL,                         // get request time
        NULL,                         // terminate process

        NULL,                         // php_ini_path_override
        NULL,                         // default_post_reader
        NULL,                         // treat_data
        NULL,                         // executable_location

        0,                           // php_ini_ignore
        0,                           // php_ini_ignore_cwd
        NULL,                         // get_fd
        NULL,                         // force_http_10
        NULL,                         // get_target_uid
        NULL,                         // get_target_gid
        NULL,                         // input_filter
        NULL,                         // ini_defaults
        0,                           // phpinfo_as_text
        NULL,                         // ini_entries
        NULL                          // additional_functions
};
