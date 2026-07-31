# Add project specific ProGuard rules here.
# You can control the set of applied configuration files using the
# proguardFiles setting in build.gradle.
#
# For more details, see
#   http://developer.android.com/guide/developing/tools/proguard.html

# NativePHP WebView JavaScript Interface
# Keep all classes that are used as JavaScript interfaces
-keepclassmembers class com.**.bridge.** {
    @android.webkit.JavascriptInterface <methods>;
}

# Keep PHP bridge classes
-keep class com.**.bridge.** { *; }

# Keep native method names for JNI
-keepclasseswithmembernames class * {
    native <methods>;
}

# Firebase/Google Play Services
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }
-dontwarn com.google.firebase.**
-dontwarn com.google.android.gms.**

# AndroidX Security (for secure storage)
-keep class androidx.security.crypto.** { *; }
-dontwarn androidx.security.crypto.**

# Biometric library
-keep class androidx.biometric.** { *; }
-dontwarn androidx.biometric.**

# Gson (for JSON serialization)
-keep class com.google.gson.** { *; }
-keep class * implements com.google.gson.TypeAdapter
-keep class * implements com.google.gson.TypeAdapterFactory
-keep class * implements com.google.gson.JsonSerializer
-keep class * implements com.google.gson.JsonDeserializer
-keepclassmembers,allowobfuscation class * {
    @com.google.gson.annotations.SerializedName <fields>;
}

# RxJava
-keep class io.reactivex.** { *; }
-dontwarn io.reactivex.**

# Debug information preservation (configurable)
REPLACE_KEEP_LINE_NUMBERS
REPLACE_KEEP_SOURCE_FILE

# Obfuscation control (configurable)
REPLACE_OBFUSCATION_CONTROL

# Custom ProGuard rules (configurable)
REPLACE_CUSTOM_PROGUARD_RULES