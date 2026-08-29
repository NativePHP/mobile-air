plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

val googleServicesJson = file("google-services.json")
if (googleServicesJson.exists()) {
    apply(plugin = "com.google.gms.google-services")
}

android {
    namespace = "com.nativephp.mobile"
    compileSdk = REPLACE_COMPILE_SDK

    // Generated NativePHP plugin sources — owned by the plugin compiler,
    // wiped and rebuilt on every compile. Do not edit.
    sourceSets.getByName("main") {
        java.srcDir("src/nativephp/kotlin")
    }

    defaultConfig {
        applicationId = "REPLACE_APP_ID"
        minSdk = REPLACE_MIN_SDK
        targetSdk = REPLACE_TARGET_SDK
        versionCode = REPLACEMECODE
        versionName = "REPLACEME"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        externalNativeBuild {
            cmake {
                arguments(
                    "-DANDROID_STL=c++_shared",
                    "-DANDROID_PLATFORM=android-24",
                    "-DANDROID_ARM_NEON=TRUE"
                )
                cppFlags("-std=c++17", "-fexceptions", "-frtti")
                targets("php_wrapper")
                arguments("-DCMAKE_SHARED_LINKER_FLAGS=-Wl,-z,max-page-size=16384")
            }
        }

        ndk {
            // Specify target ABI
            abiFilters.add("arm64-v8a")
        }
    }

    signingConfigs {
        create("release") {
            val keystoreFile = project.findProperty("MYAPP_UPLOAD_STORE_FILE") as String?
            val keyAlias = project.findProperty("MYAPP_UPLOAD_KEY_ALIAS") as String?
            val storePassword = project.findProperty("MYAPP_UPLOAD_STORE_PASSWORD") as String?
            val keyPassword = project.findProperty("MYAPP_UPLOAD_KEY_PASSWORD") as String?
            
            if (!keystoreFile.isNullOrEmpty() && 
                !keyAlias.isNullOrEmpty() && 
                !storePassword.isNullOrEmpty() && 
                !keyPassword.isNullOrEmpty()) {
                
                val keystoreFileObj = file(keystoreFile)
                if (keystoreFileObj.exists()) {
                    storeFile = keystoreFileObj
                    this.keyAlias = keyAlias
                    this.storePassword = storePassword
                    this.keyPassword = keyPassword
                }
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = REPLACE_MINIFY_ENABLED
            isShrinkResources = REPLACE_SHRINK_RESOURCES
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            
            // Apply signing configuration if available
            val releaseSigningConfig = signingConfigs.getByName("release")
            if (releaseSigningConfig.storeFile != null) {
                signingConfig = releaseSigningConfig
            }
            
            ndk {
                debugSymbolLevel = "REPLACE_DEBUG_SYMBOLS"
            }
        }
        debug {
            isJniDebuggable = true
            ndk {
                debugSymbolLevel = "REPLACE_DEBUG_SYMBOLS"
            }
        }
        // Release-optimized build that shell profilers (Macrobenchmark, simpleperf,
        // Perfetto) can attach to. `isProfileable` injects <profileable shell="true">
        // for THIS variant only, so it never leaks into the production release/bundle.
        // Debug-signed so it installs with `adb install` — no release keystore, no
        // manual zipalign/apksigner. Driven by `native:run --build=profileable`.
        create("profileable") {
            initWith(getByName("release"))
            isDebuggable = false
            isProfileable = true
            // Always R8-minify, regardless of the app's minify_enabled config.
            // Play-delivered releases run R8, so an unminified profileable build
            // measures a cold start no user ever sees — with the native-ui
            // plugin's icon/coil deps that's ~58MB of dex vs ~9MB and ~+90ms
            // of bindApplication on a Pixel 9.
            isMinifyEnabled = true
            signingConfig = signingConfigs.getByName("debug")
            matchingFallbacks += listOf("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
        freeCompilerArgs += listOf(
            "-Xsuppress-version-warnings"
        )
        allWarningsAsErrors = false
    }

    buildFeatures {
        compose = true
    }

    externalNativeBuild {
        cmake {
            path = file("src/main/cpp/CMakeLists.txt")
            version = "3.22.1"
        }
    }

    packaging {
        jniLibs {
            useLegacyPackaging = true
            keepDebugSymbols.add("**/*.so")
        }
    }

    // Enable 16 KB memory page size alignment for Android 15+ devices
    bundle {
        abi {
            enableSplit = true
        }
        language {
            enableSplit = true
        }
        density {
            enableSplit = true
        }
    }

    // NDK version specification
    ndkVersion = "27.0.12077973" // Updated to NDK r27

    // Static libs are linked by CMake into libphp_wrapper.so — no pre-built jniLibs needed
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.androidx.material)
    implementation(libs.androidx.constraintlayout)

    // Compose BOM (Bill of Materials) - manages versions
    val composeBom = platform("androidx.compose:compose-bom:2025.12.00")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    // Compose essentials
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.activity:activity-compose:1.8.2")

    // Compose integration with Views
    implementation("androidx.compose.ui:ui-viewbinding")

    // Installs the APK-embedded baseline profile (library-shipped Compose/activity
    // rules merged by AGP, plus app/src/main/baseline-prof.txt if present) so ART
    // AOT-compiles the startup path on first launch instead of JIT-ing it. Without
    // this, adb-installed release builds run the whole first-frame path interpreted
    // until background dexopt kicks in days later — a direct cold-start TTID hit.
    implementation("androidx.profileinstaller:profileinstaller:1.4.1")

    // Debug tools
    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")

    // Android Request Inspector WebView library
    implementation("com.github.acsbendi:Android-Request-Inspector-WebView:1.0.3")

    // RxJava dependencies needed for the Request Inspector
    implementation("io.reactivex.rxjava2:rxjava:2.2.21")
    implementation("io.reactivex.rxjava2:rxandroid:2.1.1")
    implementation("io.reactivex.rxjava3:rxjava:3.1.5")
    implementation("io.reactivex.rxjava3:rxandroid:3.0.0")
    implementation("com.github.akarnokd:rxjava3-bridge:3.0.0")

    // Gson for JSON handling
    implementation("com.google.code.gson:gson:2.10.1")

    // WebKit for WebView features
    implementation(libs.androidx.webkit)
    implementation(libs.androidx.browser)

    testImplementation(libs.junit)
    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.ui.test.junit4)

    // AndroidX Security for encrypted storage
    implementation(libs.androidx.security.crypto)

    // Coil3 for image loading
    implementation("io.coil-kt.coil3:coil-compose:3.1.0")
    implementation("io.coil-kt.coil3:coil-network-okhttp:3.1.0")

    // CameraX for camera preview
    val camerax_version = "1.4.1"
    implementation("androidx.camera:camera-core:$camerax_version")
    implementation("androidx.camera:camera-camera2:$camerax_version")
    implementation("androidx.camera:camera-lifecycle:$camerax_version")
    implementation("androidx.camera:camera-view:$camerax_version")
}

// Bundle task verification will be handled by the signing configuration itself
