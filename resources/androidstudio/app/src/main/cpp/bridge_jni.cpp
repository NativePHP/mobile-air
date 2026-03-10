#include <jni.h>
#include <android/log.h>
#include <string>

#define LOG_TAG "BridgeJNI"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

// Use the shared JavaVM from php_bridge.c
extern "C" JavaVM* g_jvm;

static jclass g_bridgeRouterClass = nullptr;
static jmethodID g_nativePHPCanMethod = nullptr;
static jmethodID g_nativePHPCallMethod = nullptr;

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