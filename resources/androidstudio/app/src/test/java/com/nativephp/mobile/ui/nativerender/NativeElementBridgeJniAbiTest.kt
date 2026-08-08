package com.nativephp.mobile.ui.nativerender

import java.lang.reflect.Modifier
import org.junit.Assert.assertTrue
import org.junit.Test

class NativeElementBridgeJniAbiTest {
    @Test
    fun lifecycleEntryPointsAreJavaStaticMethods() {
        val bridgeClass = Class.forName(
            NativeElementBridge::class.java.name,
            false,
            NativeElementBridge::class.java.classLoader,
        )

        listOf("startWatching", "stopWatching", "postTreeUpdate").forEach { methodName ->
            val method = bridgeClass.getDeclaredMethod(methodName)

            assertTrue(
                "$methodName must be callable by JNI GetStaticMethodID",
                Modifier.isStatic(method.modifiers),
            )
        }
    }
}
