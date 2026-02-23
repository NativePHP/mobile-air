#include <jni.h>
#include <android/log.h>
#include <string>
#include <atomic>
#include <dlfcn.h>
#include <time.h>
#include <string.h>
#include <pthread.h>
#include <errno.h>

#define LOG_TAG "BridgeJNI"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

// Use the shared JavaVM from php_bridge.c
extern "C" JavaVM* g_jvm;

static jclass g_bridgeRouterClass = nullptr;
static jmethodID g_nativePHPCanMethod = nullptr;
static jmethodID g_nativePHPCallMethod = nullptr;

// Forward declarations for UI bridge JNI functions (defined below)
static jboolean ui_is_ready(JNIEnv*, jclass);
static jint ui_get_tree_version(JNIEnv*, jclass);
static jbyteArray ui_get_tree_buffer(JNIEnv*, jclass);
static jint ui_wait_tree_update(JNIEnv*, jclass, jint, jint);
static void ui_write_event(JNIEnv*, jclass, jint, jint, jint, jbyteArray);

// Initialization function to be called from php_bridge.c's JNI_OnLoad
extern "C" jint InitializeBridgeJNI(JNIEnv* env) {
    LOGI("🔌 BridgeJNI: InitializeBridgeJNI called");

    // Find the BridgeRouter class and cache method IDs
    LOGI("🔍 BridgeJNI: Looking for com/nativephp/mobile/bridge/BridgeRouterKt class...");
    jclass localClass = env->FindClass("com/nativephp/mobile/bridge/BridgeRouterKt");
    if (localClass == nullptr) {
        LOGE("❌ BridgeJNI: Failed to find BridgeRouterKt class");
        return JNI_ERR;
    }
    LOGI("✅ BridgeJNI: Found BridgeRouterKt class");

    // Create global reference
    g_bridgeRouterClass = reinterpret_cast<jclass>(env->NewGlobalRef(localClass));
    env->DeleteLocalRef(localClass);

    if (g_bridgeRouterClass == nullptr) {
        LOGE("BridgeJNI: Failed to create global reference to BridgeRouterKt");
        return JNI_ERR;
    }

    // Get method IDs
    g_nativePHPCanMethod = env->GetStaticMethodID(g_bridgeRouterClass, "nativePHPCan",
                                                    "(Ljava/lang/String;)I");
    if (g_nativePHPCanMethod == nullptr) {
        LOGE("BridgeJNI: Failed to find nativePHPCan method");
        return JNI_ERR;
    }

    g_nativePHPCallMethod = env->GetStaticMethodID(g_bridgeRouterClass, "nativePHPCall",
                                                     "(Ljava/lang/String;Ljava/lang/String;)Ljava/lang/String;");
    if (g_nativePHPCallMethod == nullptr) {
        LOGE("BridgeJNI: Failed to find nativePHPCall method");
        return JNI_ERR;
    }

    LOGI("BridgeJNI: Initialization successful");

    /* Register UI bridge native methods */
    static JNINativeMethod uiMethods[] = {
        {(char*)"nativeIsUIReady",      (char*)"()Z",      (void*)ui_is_ready},
        {(char*)"nativeGetTreeVersion", (char*)"()I",      (void*)ui_get_tree_version},
        {(char*)"nativeGetTreeBuffer",  (char*)"()[B",     (void*)ui_get_tree_buffer},
        {(char*)"nativeWaitTreeUpdate", (char*)"(II)I",    (void*)ui_wait_tree_update},
        {(char*)"nativeWriteEvent",     (char*)"(III[B)V", (void*)ui_write_event},
    };

    jclass uiClass = env->FindClass("com/nativephp/mobile/ui/nativerender/NativeUIBridge");
    if (uiClass != nullptr) {
        if (env->RegisterNatives(uiClass, uiMethods, sizeof(uiMethods) / sizeof(uiMethods[0])) == 0) {
            LOGI("BridgeJNI: UI bridge native methods registered");
        } else {
            LOGE("BridgeJNI: Failed to register UI bridge native methods");
        }
        env->DeleteLocalRef(uiClass);
    } else {
        /* Class not found is OK — UI rendering not available in this build */
        env->ExceptionClear();
        LOGI("BridgeJNI: NativeUIBridge class not found (UI rendering disabled)");
    }

    return JNI_OK;
}

// Helper to get JNIEnv for current thread
static JNIEnv* GetJNIEnv() {
    JNIEnv* env = nullptr;

    if (g_jvm == nullptr) {
        LOGE("BridgeJNI: JVM is null");
        return nullptr;
    }

    jint result = g_jvm->GetEnv(reinterpret_cast<void**>(&env), JNI_VERSION_1_6);

    if (result == JNI_EDETACHED) {
        // Thread not attached, attach it
        result = g_jvm->AttachCurrentThread(&env, nullptr);
        if (result != JNI_OK) {
            LOGE("BridgeJNI: Failed to attach current thread");
            return nullptr;
        }
    } else if (result != JNI_OK) {
        LOGE("BridgeJNI: Failed to get JNIEnv");
        return nullptr;
    }

    return env;
}

// C functions that PHP can call

/**
 * Check if a native function exists in the bridge registry
 * Called from PHP
 * @param functionName The fully qualified function name (e.g., "Location.Get")
 * @return 1 if function exists, 0 if it doesn't
 */
extern "C" int NativePHPCan(const char* functionName) {
    if (functionName == nullptr) {
        LOGE("BridgeJNI: NativePHPCan called with null function name");
        return 0;
    }

    JNIEnv* env = GetJNIEnv();
    if (env == nullptr) {
        LOGE("BridgeJNI: Failed to get JNIEnv in NativePHPCan");
        return 0;
    }

    jstring jFunctionName = env->NewStringUTF(functionName);
    if (jFunctionName == nullptr) {
        LOGE("BridgeJNI: Failed to create jstring for function name");
        return 0;
    }

    jint result = env->CallStaticIntMethod(g_bridgeRouterClass, g_nativePHPCanMethod, jFunctionName);

    env->DeleteLocalRef(jFunctionName);

    LOGI("BridgeJNI: NativePHPCan('%s') = %d", functionName, result);
    return static_cast<int>(result);
}

/**
 * Call a native function through the bridge router
 * Called from PHP
 * @param functionName The fully qualified function name (e.g., "Location.Get")
 * @param parametersJSON JSON string containing function parameters
 * @return JSON string with result or NULL if function doesn't exist
 */
extern "C" const char* NativePHPCall(const char* functionName, const char* parametersJSON) {
    LOGI("🚀 BridgeJNI: NativePHPCall called with function='%s'", functionName ? functionName : "NULL");
    if (parametersJSON) {
        LOGI("📦 BridgeJNI: Parameters JSON: %s", parametersJSON);
    } else {
        LOGI("📦 BridgeJNI: Parameters JSON: NULL");
    }

    if (functionName == nullptr) {
        LOGE("❌ BridgeJNI: NativePHPCall called with null function name");
        return nullptr;
    }

    JNIEnv* env = GetJNIEnv();
    if (env == nullptr) {
        LOGE("❌ BridgeJNI: Failed to get JNIEnv in NativePHPCall");
        return nullptr;
    }
    LOGI("✅ BridgeJNI: Got JNIEnv successfully");

    jstring jFunctionName = env->NewStringUTF(functionName);
    if (jFunctionName == nullptr) {
        LOGE("❌ BridgeJNI: Failed to create jstring for function name");
        return nullptr;
    }
    LOGI("✅ BridgeJNI: Created jstring for function name");

    jstring jParametersJSON = nullptr;
    if (parametersJSON != nullptr) {
        jParametersJSON = env->NewStringUTF(parametersJSON);
        if (jParametersJSON == nullptr) {
            LOGE("❌ BridgeJNI: Failed to create jstring for parameters");
            env->DeleteLocalRef(jFunctionName);
            return nullptr;
        }
        LOGI("✅ BridgeJNI: Created jstring for parameters");
    }

    LOGI("🔄 BridgeJNI: Calling Kotlin nativePHPCall method...");
    jobject jResult = env->CallStaticObjectMethod(g_bridgeRouterClass, g_nativePHPCallMethod,
                                                    jFunctionName, jParametersJSON);

    env->DeleteLocalRef(jFunctionName);
    if (jParametersJSON != nullptr) {
        env->DeleteLocalRef(jParametersJSON);
    }

    if (jResult == nullptr) {
        LOGI("⚠️ BridgeJNI: NativePHPCall returned null");
        return nullptr;
    }
    LOGI("✅ BridgeJNI: Got non-null result from Kotlin");

    // Convert Java String to C string
    const char* resultStr = env->GetStringUTFChars(static_cast<jstring>(jResult), nullptr);
    if (resultStr == nullptr) {
        LOGE("❌ BridgeJNI: Failed to get C string from result");
        env->DeleteLocalRef(jResult);
        return nullptr;
    }

    LOGI("📤 BridgeJNI: Result JSON: %s", resultStr);

    // We need to make a copy because we're releasing the Java string
    // Note: This memory will be managed by PHP
    char* resultCopy = strdup(resultStr);

    env->ReleaseStringUTFChars(static_cast<jstring>(jResult), resultStr);
    env->DeleteLocalRef(jResult);

    LOGI("✅ BridgeJNI: NativePHPCall('%s') completed successfully", functionName);
    return resultCopy;
}

/* ═══════════════════════════════════════════════════════════
 * Native UI Bridge — Shared Memory Access
 *
 * These JNI functions let Kotlin read the UI tree buffer
 * and write events back to the shared memory region that
 * the PHP extension (nativephp_ui.c) created via mmap.
 * ═══════════════════════════════════════════════════════════ */

/*
 * Shared region struct — must match nativephp_ui.h layout exactly.
 * Uses std::atomic<uint32_t> which has the same layout as _Atomic uint32_t
 * on ARM64 Android (both are lock-free 4-byte aligned).
 */
struct NpuiSharedRegion {
    uint32_t magic;

    uint32_t tree_offset;
    std::atomic<uint32_t> tree_size;
    std::atomic<uint32_t> tree_version;
    std::atomic<uint32_t> tree_version_ack;

    uint32_t patch_offset;
    std::atomic<uint32_t> patch_size;
    std::atomic<uint32_t> patch_version;
    std::atomic<uint32_t> patch_version_ack;

    uint32_t event_offset;
    std::atomic<uint32_t> event_size;
    std::atomic<uint32_t> event_count;

    pthread_mutex_t event_mutex;
    pthread_cond_t  event_cond;
    pthread_mutex_t tree_mutex;
    pthread_cond_t  tree_cond;

    std::atomic<uint32_t> shutdown;
    std::atomic<uint32_t> running;
};

#define NPUI_MAGIC       0x4E505632  /* "NPV2" */
#define NPUI_EVENT_MAGIC 0x4E504556  /* "NPEV" */

static NpuiSharedRegion* g_npui_direct_ptr = nullptr;
static NpuiSharedRegion** g_npui_region_ptr = nullptr;

/*
 * Called by nativephp_ui.c (via dlsym) to pass the shared region pointer directly.
 * This avoids symbol visibility issues with dlsym(RTLD_DEFAULT, "g_npui_region").
 */
extern "C" __attribute__((visibility("default")))
void NativeUI_RegisterRegion(void* ptr) {
    LOGI("UI: NativeUI_RegisterRegion called with ptr=%p", ptr);
    g_npui_direct_ptr = (NpuiSharedRegion*)ptr;
}

/*
 * Called by nativephp_ui.c on shutdown to clear the pointer.
 */
extern "C" __attribute__((visibility("default")))
void NativeUI_UnregisterRegion(void) {
    LOGI("UI: NativeUI_UnregisterRegion called");
    g_npui_direct_ptr = nullptr;
}

/*
 * Get the shared region pointer.
 * Tries direct pointer first (set by NativeUI_RegisterRegion),
 * then falls back to dlsym(RTLD_DEFAULT, "g_npui_region").
 */
static NpuiSharedRegion* get_ui_region() {
    /* Fast path: direct pointer from PHP */
    if (g_npui_direct_ptr != nullptr) {
        if (g_npui_direct_ptr->magic == NPUI_MAGIC) {
            return g_npui_direct_ptr;
        }
        return nullptr;
    }

    /* Fallback: dlsym lookup (try RTLD_DEFAULT, then explicit libphp.so handle) */
    if (g_npui_region_ptr == nullptr) {
        g_npui_region_ptr = (NpuiSharedRegion**)dlsym(RTLD_DEFAULT, "g_npui_region");
        if (g_npui_region_ptr == nullptr) {
            /* Android namespace-safe: try the PHP library directly */
            void* ph = dlopen("libphp.so", RTLD_NOLOAD | RTLD_NOW);
            if (ph) {
                g_npui_region_ptr = (NpuiSharedRegion**)dlsym(ph, "g_npui_region");
            }
        }
        if (g_npui_region_ptr == nullptr) {
            return nullptr;
        }
    }
    NpuiSharedRegion* region = *g_npui_region_ptr;
    if (region == nullptr || region->magic != NPUI_MAGIC) {
        return nullptr;
    }
    return region;
}

/* Check if UI shared memory is initialized */
static jboolean ui_is_ready(JNIEnv*, jclass) {
    return get_ui_region() != nullptr ? JNI_TRUE : JNI_FALSE;
}

/* Get current tree version (atomic read) */
static jint ui_get_tree_version(JNIEnv*, jclass) {
    auto* region = get_ui_region();
    if (!region) return 0;
    return (jint)region->tree_version.load(std::memory_order_acquire);
}

/* Copy the tree buffer into a Java byte array */
static jbyteArray ui_get_tree_buffer(JNIEnv* env, jclass) {
    auto* region = get_ui_region();
    if (!region) return nullptr;

    uint32_t size = region->tree_size.load(std::memory_order_acquire);
    if (size == 0 || size > (2 * 1024 * 1024)) return nullptr;

    /* Re-validate region after reading size (shutdown may have occurred) */
    if (region->shutdown.load(std::memory_order_acquire)) return nullptr;

    uint8_t* tree_buf = (uint8_t*)region + region->tree_offset;

    jbyteArray result = env->NewByteArray(size);
    if (result == nullptr) return nullptr;

    env->SetByteArrayRegion(result, 0, size, (jbyte*)tree_buf);
    return result;
}

/*
 * Block until the tree version changes, times out, or shutdown is signaled.
 * Returns: new version (>0), 0 on timeout, -1 on shutdown/error.
 */
static jint ui_wait_tree_update(JNIEnv*, jclass, jint current_version, jint timeout_ms) {
    auto* region = get_ui_region();
    if (!region) return -1;

    pthread_mutex_lock(&region->tree_mutex);

    while ((jint)region->tree_version.load(std::memory_order_acquire) == current_version &&
           !region->shutdown.load(std::memory_order_acquire)) {

        if (timeout_ms < 0) {
            pthread_cond_wait(&region->tree_cond, &region->tree_mutex);
        } else {
            struct timespec ts;
            clock_gettime(CLOCK_REALTIME, &ts);
            ts.tv_sec  += timeout_ms / 1000;
            ts.tv_nsec += (timeout_ms % 1000) * 1000000L;
            if (ts.tv_nsec >= 1000000000L) {
                ts.tv_sec++;
                ts.tv_nsec -= 1000000000L;
            }

            int rc = pthread_cond_timedwait(&region->tree_cond, &region->tree_mutex, &ts);
            if (rc == ETIMEDOUT) {
                pthread_mutex_unlock(&region->tree_mutex);
                return 0;
            }
        }

        /* After waking, re-validate region pointer — shutdown may have
         * unregistered and unmapped the region while we were waiting. */
        if (g_npui_direct_ptr == nullptr && get_ui_region() == nullptr) {
            pthread_mutex_unlock(&region->tree_mutex);
            return -1;
        }
    }

    if (region->shutdown.load(std::memory_order_acquire)) {
        pthread_mutex_unlock(&region->tree_mutex);
        return -1;
    }

    jint new_version = (jint)region->tree_version.load(std::memory_order_acquire);
    pthread_mutex_unlock(&region->tree_mutex);

    /* Acknowledge the version */
    region->tree_version_ack.store((uint32_t)new_version, std::memory_order_release);

    return new_version;
}

/*
 * Write a UI event to shared memory and wake PHP's wait_event().
 * Event wire format: [4]magic [1]type [4]callback_id [4]node_id [8]timestamp [2]data_size [N]data
 */
static void ui_write_event(JNIEnv* env, jclass, jint type, jint callback_id, jint node_id, jbyteArray data) {
    auto* region = get_ui_region();
    if (!region) return;

    /* Build event in stack buffer */
    uint8_t event_buf[512];
    size_t pos = 0;

    auto write_u8  = [&](uint8_t  v) { if (pos + 1 <= sizeof(event_buf)) { event_buf[pos++] = v; } };
    auto write_u16 = [&](uint16_t v) { if (pos + 2 <= sizeof(event_buf)) { memcpy(event_buf + pos, &v, 2); pos += 2; } };
    auto write_u32 = [&](uint32_t v) { if (pos + 4 <= sizeof(event_buf)) { memcpy(event_buf + pos, &v, 4); pos += 4; } };
    auto write_u64 = [&](uint64_t v) { if (pos + 8 <= sizeof(event_buf)) { memcpy(event_buf + pos, &v, 8); pos += 8; } };

    write_u32(NPUI_EVENT_MAGIC);
    write_u8((uint8_t)type);
    write_u32((uint32_t)callback_id);
    write_u32((uint32_t)node_id);

    /* Timestamp in milliseconds */
    struct timespec ts;
    clock_gettime(CLOCK_REALTIME, &ts);
    uint64_t timestamp = (uint64_t)ts.tv_sec * 1000ULL + (uint64_t)ts.tv_nsec / 1000000ULL;
    write_u64(timestamp);

    /* Event-specific data */
    jsize data_len = data ? env->GetArrayLength(data) : 0;
    write_u16((uint16_t)data_len);
    if (data_len > 0 && pos + data_len <= sizeof(event_buf)) {
        env->GetByteArrayRegion(data, 0, data_len, (jbyte*)(event_buf + pos));
        pos += data_len;
    }

    /* Write to shared memory under lock */
    pthread_mutex_lock(&region->event_mutex);

    uint8_t* shared_event_buf = (uint8_t*)region + region->event_offset;
    memcpy(shared_event_buf, event_buf, pos);
    region->event_size.store((uint32_t)pos, std::memory_order_release);
    region->event_count.store(1, std::memory_order_release);

    pthread_cond_signal(&region->event_cond);
    pthread_mutex_unlock(&region->event_mutex);

    LOGI("UI: Event written — type=%d cb=%d node=%d size=%zu", type, callback_id, node_id, pos);
}