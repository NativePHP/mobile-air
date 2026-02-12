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
    compileSdk = 36

    defaultConfig {
        applicationId = "REPLACE_APP_ID"
        minSdk = 33
        targetSdk = 36
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
                targets("php_wrapper", "compat")
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
            pickFirsts.add("lib/arm64-v8a/libc++_shared.so")
            pickFirsts.add("lib/armeabi-v7a/libc++_shared.so")
            pickFirsts.add("lib/x86/libc++_shared.so")
            pickFirsts.add("lib/x86_64/libc++_shared.so")
        }

        // Exclude conflicting native libraries
        resources {
            excludes += "/lib/arm64-v8a/libstdc++.so"
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

    sourceSets {
        getByName("main") {
            jniLibs.srcDirs("src/main/jniLibs")
        }
    }
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

    implementation(platform(libs.firebase.bom))
    implementation("com.google.firebase:firebase-messaging")

    // AndroidX Security for encrypted storage
    implementation(libs.androidx.security.crypto)

    // CameraX for camera preview
    val camerax_version = "1.4.1"
    implementation("androidx.camera:camera-core:$camerax_version")
    implementation("androidx.camera:camera-camera2:$camerax_version")
    implementation("androidx.camera:camera-lifecycle:$camerax_version")
    implementation("androidx.camera:camera-view:$camerax_version")

    // WorkManager for background queue processing
    implementation("androidx.work:work-runtime-ktx:2.9.0")
}

// Bundle task verification will be handled by the signing configuration itself
