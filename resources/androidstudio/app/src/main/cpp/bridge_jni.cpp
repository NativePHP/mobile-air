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

// Cached refs for NativeElementBridge — used by NativeElement_PostTreeUpdate() / stopWatching()
static jclass g_elementBridgeClass = nullptr;
static jmethodID g_postTreeUpdateMethod = nullptr;
static jmethodID g_stopWatchingMethod = nullptr;
static jmethodID g_startWatchingMethod = nullptr;

// Forward declarations for Element bridge JNI functions
static jboolean element_is_ready(JNIEnv*, jclass);
static jint element_wait_update(JNIEnv*, jclass, jint, jint);
static jobject element_get_flat_buffer(JNIEnv*, jclass);
static jobject element_get_prop_buffer(JNIEnv*, jclass);
static jobjectArray element_get_type_table(JNIEnv*, jclass);
static jint element_get_node_count(JNIEnv*, jclass);
static void element_write_event(JNIEnv*, jclass, jint, jint, jint, jbyteArray);

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

    /* Register Element bridge native methods */
    static JNINativeMethod elementMethods[] = {
        {(char*)"nativeElementIsReady",    (char*)"()Z",                       (void*)element_is_ready},
        {(char*)"nativeElementWaitUpdate", (char*)"(II)I",                     (void*)element_wait_update},
        {(char*)"nativeGetFlatBuffer",     (char*)"()Ljava/nio/ByteBuffer;",   (void*)element_get_flat_buffer},
        {(char*)"nativeGetPropBuffer",     (char*)"()Ljava/nio/ByteBuffer;",   (void*)element_get_prop_buffer},
        {(char*)"nativeGetTypeTable",      (char*)"()[Ljava/lang/String;",     (void*)element_get_type_table},
        {(char*)"nativeGetNodeCount",      (char*)"()I",                       (void*)element_get_node_count},
        {(char*)"nativeElementWriteEvent", (char*)"(III[B)V",                  (void*)element_write_event},
    };

    jclass elClass = env->FindClass("com/nativephp/mobile/ui/nativerender/NativeElementBridge");
    if (elClass != nullptr) {
        if (env->RegisterNatives(elClass, elementMethods, sizeof(elementMethods) / sizeof(elementMethods[0])) == 0) {
            LOGI("BridgeJNI: Element bridge native methods registered");
        } else {
            LOGE("BridgeJNI: Failed to register Element bridge native methods");
        }

        /* Cache class + method refs for direct C → Kotlin push */
        g_elementBridgeClass = reinterpret_cast<jclass>(env->NewGlobalRef(elClass));
        g_postTreeUpdateMethod = env->GetStaticMethodID(g_elementBridgeClass, "postTreeUpdate", "()V");
        if (g_postTreeUpdateMethod) {
            LOGI("BridgeJNI: Cached postTreeUpdate method for direct JNI push");
        } else {
            LOGE("BridgeJNI: Failed to find postTreeUpdate method");
            env->ExceptionClear();
        }

        g_stopWatchingMethod = env->GetStaticMethodID(g_elementBridgeClass, "stopWatching", "()V");
        if (g_stopWatchingMethod) {
            LOGI("BridgeJNI: Cached stopWatching method for element teardown");
        } else {
            LOGE("BridgeJNI: Failed to find stopWatching method");
            env->ExceptionClear();
        }

        g_startWatchingMethod = env->GetStaticMethodID(g_elementBridgeClass, "startWatching", "()V");
        if (g_startWatchingMethod) {
            LOGI("BridgeJNI: Cached startWatching method for element setup");
        } else {
            LOGE("BridgeJNI: Failed to find startWatching method");
            env->ExceptionClear();
        }

        env->DeleteLocalRef(elClass);
    } else {
        env->ExceptionClear();
        LOGI("BridgeJNI: NativeElementBridge class not found (Element runtime disabled)");
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

// ── Native UI bridge stubs ──────────────────────────────
// These symbols are referenced by the nativephp_ui and nphp_element
// PHP extensions compiled into libphp.a.  They must be present at
// link time now that we use static linking with --whole-archive.

static void* g_nativeui_region  = nullptr;
static void* g_element_region   = nullptr;

extern "C" void NativeUI_RegisterRegion(void* region) {
    g_nativeui_region = region;
    LOGI("NativeUI_RegisterRegion: %p", region);
}

extern "C" void NativeUI_UnregisterRegion(void) {
    LOGI("NativeUI_UnregisterRegion");
    g_nativeui_region = nullptr;
}

extern "C" void NativeElement_RegisterRegion(void* region) {
    g_element_region = region;
    LOGI("NativeElement_RegisterRegion: %p", region);
}

extern "C" void NativeElement_UnregisterRegion(void) {
    LOGI("NativeElement_UnregisterRegion");
    g_element_region = nullptr;
}

extern "C" void NativeElement_PostTreeUpdate(void) {
    LOGI("NativeElement_PostTreeUpdate");
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
 * NativeUI Bridge — Legacy shared-memory UI system
 * ═══════════════════════════════════════════════════════════ */

static void* g_native_ui_region = nullptr;

extern "C" __attribute__((visibility("default")))
void NativeUI_RegisterRegion(void* ptr) {
    LOGI("NativeUI: RegisterRegion ptr=%p", ptr);
    g_native_ui_region = ptr;
}

extern "C" __attribute__((visibility("default")))
void NativeUI_UnregisterRegion(void) {
    LOGI("NativeUI: UnregisterRegion");
    g_native_ui_region = nullptr;
}

/* ═══════════════════════════════════════════════════════════
 * Element Runtime Bridge — Direct Flat Buffer Access
 *
 * JNI functions for the Element runtime. Reads fixed-stride
 * flat nodes from malloc'd buffers instead of parsing V2 binary.
 * ═══════════════════════════════════════════════════════════ */

#define NPHP_ELEMENT_MAGIC   0x4E504845  /* "NPHE" — must match nphp_element.h */
#define NPHP_EVENT_MAGIC_EL  0x4E504556  /* "NPEV" — same format */

/*
 * Element region struct — must match nphp_element_region_t in nphp_element.h EXACTLY.
 * Uses void* for zval* since we don't have php.h here.
 */
struct NphpElementRegion {
    uint32_t magic;

    std::atomic<uint32_t> tree_version;
    std::atomic<uint32_t> shutdown;
    std::atomic<uint32_t> running;
    std::atomic<uint32_t> node_count;
    std::atomic<uint32_t> flat_buffer_size;
    std::atomic<uint32_t> prop_buffer_size;

    /* Sync */
    pthread_mutex_t tree_mutex;
    pthread_cond_t  tree_cond;
    pthread_mutex_t event_mutex;
    pthread_cond_t  event_cond;

    /* Events */
    std::atomic<uint32_t> event_size;
    std::atomic<uint32_t> event_count;
    uint8_t event_buffer[4096];

    /* Buffers (heap-allocated) */
    uint8_t* flat_buffer;
    uint8_t* prop_buffer;

    /* Shadow buffers for frame-skip optimization */
    uint8_t* shadow_flat_buffer;
    uint8_t* shadow_prop_buffer;
    uint32_t shadow_node_count;
    uint32_t shadow_flat_size;
    uint32_t shadow_prop_size;

    /* Type interning table */
    uint8_t  type_count;
    uint16_t type_offsets[128];
    char     type_table[4096];

    /* Held zval reference (opaque to JNI) */
    void* current_tree;
};

static NphpElementRegion* g_element_direct_ptr = nullptr;

extern "C" __attribute__((visibility("default")))
void NativeElement_RegisterRegion(void* ptr) {
    LOGI("Element: NativeElement_RegisterRegion called with ptr=%p", ptr);
    g_element_direct_ptr = (NphpElementRegion*)ptr;

    // Restart the shadow thread so it can process tree updates for the new page
    if (g_elementBridgeClass && g_startWatchingMethod) {
        JNIEnv* env = GetJNIEnv();
        if (env) {
            LOGI("Element: RegisterRegion — calling startWatching()");
            env->CallStaticVoidMethod(g_elementBridgeClass, g_startWatchingMethod);
            if (env->ExceptionCheck()) {
                LOGE("Element: RegisterRegion — startWatching exception");
                env->ExceptionClear();
            }
        }
    }
}

extern "C" __attribute__((visibility("default")))
void NativeElement_UnregisterRegion(void) {
    LOGI("Element: NativeElement_UnregisterRegion called");
    g_element_direct_ptr = nullptr;

    // Notify Kotlin to hide the native Compose overlay
    if (g_elementBridgeClass && g_stopWatchingMethod) {
        JNIEnv* env = GetJNIEnv();
        if (env) {
            LOGI("Element: UnregisterRegion — calling stopWatching()");
            env->CallStaticVoidMethod(g_elementBridgeClass, g_stopWatchingMethod);
            if (env->ExceptionCheck()) {
                LOGE("Element: UnregisterRegion — stopWatching exception");
                env->ExceptionDescribe();
                env->ExceptionClear();
            }
        }
    }
}

static NphpElementRegion* get_element_region() {
    if (g_element_direct_ptr != nullptr &&
        g_element_direct_ptr->magic == NPHP_ELEMENT_MAGIC) {
        return g_element_direct_ptr;
    }
    return nullptr;
}

/* ── Element JNI Functions ── */

static jboolean element_is_ready(JNIEnv*, jclass) {
    return get_element_region() != nullptr ? JNI_TRUE : JNI_FALSE;
}

/* Legacy stub — tree updates now push directly via NativeElement_PostTreeUpdate().
 * Kept for JNI registration compat; not called by Kotlin. */
static jint element_wait_update(JNIEnv*, jclass, jint current_version, jint /*timeout_ms*/) {
    auto* region = get_element_region();
    if (!region) return -1;
    return (jint)region->tree_version.load(std::memory_order_acquire);
}

static jobject element_get_flat_buffer(JNIEnv* env, jclass) {
    auto* region = get_element_region();
    if (!region) return nullptr;

    uint32_t size = region->flat_buffer_size.load(std::memory_order_acquire);
    if (size == 0 || !region->flat_buffer) return nullptr;
    if (region->shutdown.load(std::memory_order_acquire)) return nullptr;

    return env->NewDirectByteBuffer(region->flat_buffer, size);
}

static jobject element_get_prop_buffer(JNIEnv* env, jclass) {
    auto* region = get_element_region();
    if (!region) return nullptr;

    uint32_t size = region->prop_buffer_size.load(std::memory_order_acquire);
    if (size == 0 || !region->prop_buffer) return nullptr;
    if (region->shutdown.load(std::memory_order_acquire)) return nullptr;

    return env->NewDirectByteBuffer(region->prop_buffer, size);
}

static jobjectArray element_get_type_table(JNIEnv* env, jclass) {
    auto* region = get_element_region();
    if (!region || region->type_count == 0) return nullptr;

    jclass stringClass = env->FindClass("java/lang/String");
    if (!stringClass) return nullptr;

    jobjectArray arr = env->NewObjectArray(region->type_count, stringClass, nullptr);
    if (!arr) {
        env->DeleteLocalRef(stringClass);
        return nullptr;
    }

    for (int i = 0; i < region->type_count; i++) {
        const char* str = region->type_table + region->type_offsets[i];
        jstring jstr = env->NewStringUTF(str);
        if (jstr) {
            env->SetObjectArrayElement(arr, i, jstr);
            env->DeleteLocalRef(jstr);
        }
    }

    env->DeleteLocalRef(stringClass);
    return arr;
}

static jint element_get_node_count(JNIEnv*, jclass) {
    auto* region = get_element_region();
    if (!region) return 0;
    return (jint)region->node_count.load(std::memory_order_acquire);
}

static void element_write_event(JNIEnv* env, jclass, jint type, jint callback_id, jint node_id, jbyteArray data) {
    auto* region = get_element_region();
    if (!region) {
        LOGE("Element: Event DROPPED — region is NULL (type=%d cb=%d node=%d)", type, callback_id, node_id);
        return;
    }

    /* Build event in stack buffer — same NPEV format */
    uint8_t event_buf[512];
    size_t pos = 0;

    auto write_u8  = [&](uint8_t  v) { if (pos + 1 <= sizeof(event_buf)) { event_buf[pos++] = v; } };
    auto write_u16 = [&](uint16_t v) { if (pos + 2 <= sizeof(event_buf)) { memcpy(event_buf + pos, &v, 2); pos += 2; } };
    auto write_u32 = [&](uint32_t v) { if (pos + 4 <= sizeof(event_buf)) { memcpy(event_buf + pos, &v, 4); pos += 4; } };
    auto write_u64 = [&](uint64_t v) { if (pos + 8 <= sizeof(event_buf)) { memcpy(event_buf + pos, &v, 8); pos += 8; } };

    write_u32(NPHP_EVENT_MAGIC_EL);
    write_u8((uint8_t)type);
    write_u32((uint32_t)callback_id);
    write_u32((uint32_t)node_id);

    struct timespec ts;
    clock_gettime(CLOCK_REALTIME, &ts);
    uint64_t timestamp = (uint64_t)ts.tv_sec * 1000ULL + (uint64_t)ts.tv_nsec / 1000000ULL;
    write_u64(timestamp);

    jsize data_len = data ? env->GetArrayLength(data) : 0;
    write_u16((uint16_t)data_len);
    if (data_len > 0 && pos + data_len <= sizeof(event_buf)) {
        env->GetByteArrayRegion(data, 0, data_len, (jbyte*)(event_buf + pos));
        pos += data_len;
    }

    /* Write to inline event buffer under lock */
    pthread_mutex_lock(&region->event_mutex);

    memcpy(region->event_buffer, event_buf, pos);
    region->event_size.store((uint32_t)pos, std::memory_order_release);
    region->event_count.store(1, std::memory_order_release);

    pthread_cond_signal(&region->event_cond);
    pthread_mutex_unlock(&region->event_mutex);

    LOGI("Element: Event written — type=%d cb=%d node=%d size=%zu", type, callback_id, node_id, pos);
}

/* ═══════════════════════════════════════════════════════════
 * Direct JNI Push — called from nphp_element.c on PHP thread
 *
 * After nphp_element_publish() builds the flat buffer, it calls
 * this to push the tree to Kotlin's NativeElementBridge.postTreeUpdate()
 * which parses the buffer and posts to the Compose renderer.
 * ═══════════════════════════════════════════════════════════ */

extern "C" __attribute__((visibility("default")))
void NativeElement_PostTreeUpdate() {
    if (!g_elementBridgeClass || !g_postTreeUpdateMethod) {
        LOGE("Element: PostTreeUpdate — JNI refs not cached");
        return;
    }

    JNIEnv* env = GetJNIEnv();
    if (!env) {
        LOGE("Element: PostTreeUpdate — failed to get JNIEnv");
        return;
    }

    auto* region = get_element_region();
    if (region) {
        LOGI("Element: PostTreeUpdate calling Kotlin (nodes=%u flat=%u types=%d ver=%u)",
             region->node_count.load(std::memory_order_acquire),
             region->flat_buffer_size.load(std::memory_order_acquire),
             (int)region->type_count,
             region->tree_version.load(std::memory_order_acquire));
    }

    env->CallStaticVoidMethod(g_elementBridgeClass, g_postTreeUpdateMethod);

    if (env->ExceptionCheck()) {
        LOGE("Element: PostTreeUpdate — Java exception thrown");
        env->ExceptionDescribe();
        env->ExceptionClear();
    }
}