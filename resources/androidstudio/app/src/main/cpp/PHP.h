#ifndef PHP_BRIDGE_H
#define PHP_BRIDGE_H

#include "php_embed.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef void (*phpOutputCallback)(const char* output);
void override_embed_module_output(phpOutputCallback callback);
void initialize_php_with_request(const char* post_data, const char* method, const char* uri);
// Binary-safe variant: body is body_len bytes (NULs allowed), content_type is
// the body's own type or NULL. SG(request_info).content_type is left NULL.
void initialize_php_with_request_bytes(const char* body, size_t body_len, const char* content_type,
                                       const char* method, const char* uri);
size_t capture_php_output(const char *str, size_t str_length);

#ifdef __cplusplus
}
#endif

#endif // PHP_BRIDGE_H