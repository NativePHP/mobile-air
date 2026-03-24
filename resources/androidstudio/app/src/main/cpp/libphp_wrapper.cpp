#include "android_compat.h"
#include <android/log.h>
#include <cstring>
#include <dlfcn.h>
#include <link.h>

#define LOG_TAG "PHP-Wrapper"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static int print_phdr(struct dl_phdr_info *info, size_t size, void *data) {
    if (info->dlpi_name && strstr(info->dlpi_name, "php_wrapper")) {
        __android_log_print(ANDROID_LOG_INFO, "LINKER", "LOADED: %s", info->dlpi_name);
    }
    return 0;
}

__attribute__((constructor))
void list_loaded_libraries() {
    dl_iterate_phdr(print_phdr, NULL);
}

// Re-open ourselves with RTLD_GLOBAL so PHP extensions loaded at runtime
// can resolve symbols from libphp.a that are linked into this .so
__attribute__((constructor))
static void expose_symbols_to_php() {
    void* handle = dlopen("libphp_wrapper.so", RTLD_NOW | RTLD_GLOBAL);
    if (!handle) {
        LOGE("dlopen(libphp_wrapper.so) failed: %s", dlerror());
    } else {
        LOGI("Re-opened libphp_wrapper.so with RTLD_GLOBAL");
    }
}
