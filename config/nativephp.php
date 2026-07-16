<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App Version Name
    |--------------------------------------------------------------------------
    |
    | This is the human-readable version of your app (e.g. "1.0.0"). It is
    | used as the versionName in Android builds and may be displayed in
    | the app or console to determine the current app release version.
    |
    */

    'version' => env('NATIVEPHP_APP_VERSION', 'DEBUG'),

    /*
    |--------------------------------------------------------------------------
    | App Version Code
    |--------------------------------------------------------------------------
    |
    | This is the internal numeric version code used for Play Store builds.
    | It must increase with every release. This is used as versionCode in
    | Android builds and is required for publishing updates to the store.
    |
    */

    'version_code' => env('NATIVEPHP_APP_VERSION_CODE', 1),

    /*
    |--------------------------------------------------------------------------
    | App ID
    |--------------------------------------------------------------------------
    |
    | This is the unique ID of your application used by Android to identify
    | the app package. It is typically written in reverse domain format,
    | such as "com.nativephp.app".
    |
    */

    'app_id' => env('NATIVEPHP_APP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Deeplink Scheme
    |--------------------------------------------------------------------------
    |
    | The deep link scheme to use for opening your app from URLs. For
    | example, using the scheme "nativephp" allows links like:
    | nativephp://some/path to open the app directly.
    |
    */

    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    /*
    |--------------------------------------------------------------------------
    | Deeplink Host
    |--------------------------------------------------------------------------
    |
    | The domain name to associate with verified HTTPS links and NFC tags.
    | This allows URLs like https://your-host.com/path to open your app
    | when tapped from an NFC tag or clicked from the browser.
    |
    */

    'deeplink_host' => env('NATIVEPHP_DEEPLINK_HOST'),

    /*
    |--------------------------------------------------------------------------
    | Start URL
    |--------------------------------------------------------------------------
    |
    | The initial URL/path to load when the app starts. This should be a
    | path relative to the app root (e.g., "/dashboard", "/onboarding").
    | If not set, the app will load the root path ("/").
    |
    */

    'start_url' => env('NATIVEPHP_START_URL', '/'),

    /*
    |--------------------------------------------------------------------------
    | Development Team (iOS)
    |--------------------------------------------------------------------------
    |
    | The Apple Developer Team ID to use for code signing iOS apps. This is
    | automatically detected from your installed certificates, but you can
    | override it here if needed. Find your Team ID in your Apple Developer
    | account under Membership details.
    |
    */
    'development_team' => env('NATIVEPHP_DEVELOPMENT_TEAM'),

    /*
    |--------------------------------------------------------------------------
    | iOS Permission Strings (Info.plist Overrides)
    |--------------------------------------------------------------------------
    |
    | Override iOS Info.plist usage descriptions provided by plugins. Anything
    | you set here is applied AFTER all plugin manifests are merged, so it
    | always wins — useful when multiple plugins claim the same key (e.g.
    | mobile-camera and mobile-scanner both set NSCameraUsageDescription) and
    | you want a single explicit string for App Store review.
    |
    | Android has no equivalent: permission rationale is shown by app code at
    | runtime, not declared in the manifest, so this block is iOS-only.
    |
    */

    'permissions' => [
        // 'NSCameraUsageDescription' => 'Used to take a profile photo.',
        // 'NSMicrophoneUsageDescription' => 'Used to record audio with your videos.',
        // 'NSPhotoLibraryUsageDescription' => 'Used to select photos for your post.',
    ],

    /*
    |--------------------------------------------------------------------------
    | iOS Permission String Localizations
    |--------------------------------------------------------------------------
    |
    | Provide per-locale overrides for the strings declared above. Each key is
    | a BCP 47 locale code (e.g. 'nl', 'fr', 'zh-Hans') and its value mirrors
    | the `permissions` array shape. At build time these are written to
    | {locale}.lproj/InfoPlist.strings inside the iOS bundle, and the locales
    | are registered with the Xcode project so they ship with the app.
    |
    | iOS picks the right string at runtime based on the user's preferred
    | language, falling back to the value in `permissions` (Info.plist).
    |
    | Plugins can ship their own localizations via `ios.info_plist_localizations`
    | in their nativephp.json — app-level entries win on key collisions.
    |
    */

    'permission_localizations' => [
        // 'nl' => [
        //     'NSCameraUsageDescription' => 'Gebruikt om een profielfoto te maken.',
        //     'NSMicrophoneUsageDescription' => 'Gebruikt om audio op te nemen bij je video\'s.',
        //     'NSPhotoLibraryUsageDescription' => 'Gebruikt om foto\'s voor je bericht te selecteren.',
        // ],
        // 'fr' => [
        //     'NSCameraUsageDescription' => 'Utilisé pour prendre une photo de profil.',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Keys to Clean Up
    |--------------------------------------------------------------------------
    |
    | These are keys that will be removed from the .env file during app
    | bundling to prevent secrets or development credentials from being
    | leaked. Wildcards are supported (e.g. AWS_* or *_SECRET).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Runtime Configuration
    |--------------------------------------------------------------------------
    |
    | Controls how the PHP interpreter runs on device. In 'persistent' mode,
    | PHP boots once and stays alive — subsequent requests dispatch through
    | the running interpreter (~5-30ms instead of ~200-300ms). In 'classic'
    | mode, each request does a full php_embed_init/shutdown cycle.
    |
    | reset_instances:        Clear resolved facade instances between dispatches
    | gc_between_dispatches:  Run gc_collect_cycles() between dispatches
    |
    */

    'runtime' => [
        'mode' => env('NATIVEPHP_RUNTIME_MODE', 'persistent'),
        'reset_instances' => true,
        'gc_between_dispatches' => false,
    ],

    'cleanup_env_keys' => [
        'AWS_*',
        'GITHUB_*',
        'DO_SPACES_*',
        '*_SECRET',
        'DB_PASSWORD',
        'DB_USERNAME',
    ],

    /*
    |--------------------------------------------------------------------------
    | Files to Exclude Before Bundling
    |--------------------------------------------------------------------------
    |
    | These files and folders will be removed before the final bundle is
    | built for production. You may use glob/wildcard patterns here to
    | skip unnecessary assets like logs, sessions, or temp data.
    |
    */

    'cleanup_exclude_files' => [
        'storage/framework/sessions',
        'storage/framework/cache',
        'storage/framework/testing',
        'storage/logs/laravel.log',
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Configuration
    |--------------------------------------------------------------------------
    |
    | Controls the persistent PHP runtime behavior. In 'persistent' mode,
    | Laravel boots once and the kernel is reused across requests (~5-30ms
    | per dispatch instead of ~200-300ms). Falls back to 'classic' mode
    | (full init/shutdown per request) if persistent boot fails.
    |
    */

    'runtime' => [
        'mode' => 'persistent', // 'classic' or 'persistent'
        'reset_instances' => true,
        'gc_between_dispatches' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Configuration
    |--------------------------------------------------------------------------
    |
    | Controls the persistent PHP runtime behavior. In 'persistent' mode,
    | Laravel boots once and the kernel is reused across requests (~5-30ms
    | per dispatch instead of ~200-300ms). Falls back to 'classic' mode
    | (full init/shutdown per request) if persistent boot fails.
    |
    */

    'runtime' => [
        'mode' => env('NATIVEPHP_RUNTIME_MODE', 'persistent'),
        'reset_instances' => true,
        'gc_between_dispatches' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | FPS Overlay
    |--------------------------------------------------------------------------
    |
    | When true, shows a small dev overlay in the top-trailing corner with
    | live FPS / p99 frame time / jank count. Useful for spotting jank
    | during development. Default off so production users never see it.
    | Toggle via `NATIVEPHP_FPS_OVERLAY=true` in `.env`.
    |
    */
    'fps_overlay' => env('NATIVEPHP_FPS_OVERLAY', false),

    'android' => [
        'gradle_jdk_path' => env('NATIVEPHP_GRADLE_PATH'),
        'android_sdk_path' => env('NATIVEPHP_ANDROID_SDK_LOCATION'),
        'emulator_path' => env('ANDROID_EMULATOR'),
        '7zip-location' => env('NATIVEPHP_7ZIP_LOCATION', 'C:\\Program Files\\7-Zip\\7z.exe'),

        /*
        |--------------------------------------------------------------------------
        | Android SDK Versions
        |--------------------------------------------------------------------------
        |
        | Configure the Android SDK versions for your app build. These control
        | which Android versions your app can run on and which APIs are available.
        |
        | compile_sdk: The SDK version used to compile your app (latest features)
        | min_sdk:     The minimum Android version your app supports
        | target_sdk:  The SDK version your app is designed and tested for
        |
        */
        'compile_sdk' => env('NATIVEPHP_ANDROID_COMPILE_SDK', 36),
        'min_sdk' => env('NATIVEPHP_ANDROID_MIN_SDK', 33),
        'target_sdk' => env('NATIVEPHP_ANDROID_TARGET_SDK', 36),

        /*
        |--------------------------------------------------------------------------
        | Status Bar Style
        |--------------------------------------------------------------------------
        |
        | Set the color of the status bar and navigation bar icons.
        | Options: 'auto'  - Auto-detect from system theme (recommended)
        |          'light' - Light/white icons
        |          'dark'  - Dark icons
        |
        */
        'status_bar_style' => 'auto',

        /*
        |--------------------------------------------------------------------------
        | Android Theme Colors
        |--------------------------------------------------------------------------
        |
        | Colors applied to the generated Android theme (Theme.AndroidPHP). These
        | drive the colorPrimary / colorOnPrimary values used by native dialogs
        | such as the date and time pickers (OK / Cancel buttons).
        |
        | The night value is written to values-night/themes.xml so Android can
        | switch automatically when the device is in dark mode.
        |
        | Values must be hex strings: #RRGGBB or #AARRGGBB. Wrap them in quotes
        | inside your .env file (e.g. NATIVEPHP_ANDROID_COLOR_PRIMARY="#04ABA6")
        | because '#' starts a comment in .env.
        |
        | Both values/themes.xml and values-night/themes.xml are written from
        | these values during `php artisan native:install`.
        |
        */
        'theme' => [
            'color_primary' => env('NATIVEPHP_ANDROID_COLOR_PRIMARY', '#04ABA6'),
            'color_primary_night' => env('NATIVEPHP_ANDROID_COLOR_PRIMARY_NIGHT', '#FFFFFF'),
            'color_on_primary' => env('NATIVEPHP_ANDROID_COLOR_ON_PRIMARY', '#FFFFFF'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Android Build Configuration
        |--------------------------------------------------------------------------
        |
        | These options control how your Android app is built and optimized.
        | The defaults maintain current behavior while allowing customization
        | for production builds, debugging, and app store optimization.
        |
        */
        'build' => [
            // R8/ProGuard Configuration - currently disabled
            'minify_enabled' => env('NATIVEPHP_ANDROID_MINIFY_ENABLED', false),
            'shrink_resources' => env('NATIVEPHP_ANDROID_SHRINK_RESOURCES', false),
            'obfuscate' => env('NATIVEPHP_ANDROID_OBFUSCATE', false),

            // Debug Symbol Configuration - currently enabled
            'debug_symbols' => env('NATIVEPHP_ANDROID_DEBUG_SYMBOLS', 'FULL'),
            'generate_mapping_files' => env('NATIVEPHP_ANDROID_MAPPING_FILES', false),
            'mapping_file_path' => env('NATIVEPHP_ANDROID_MAPPING_PATH', 'build/outputs/mapping/release/'),

            // ProGuard Rules - currently disabled
            'keep_line_numbers' => env('NATIVEPHP_ANDROID_KEEP_LINE_NUMBERS', false),
            'keep_source_file' => env('NATIVEPHP_ANDROID_KEEP_SOURCE_FILE', false),
            'custom_proguard_rules' => env('NATIVEPHP_ANDROID_CUSTOM_PROGUARD_RULES', []),

            // Build Performance - using Gradle defaults
            'parallel_builds' => env('NATIVEPHP_ANDROID_PARALLEL_BUILDS', true),
            'incremental_builds' => env('NATIVEPHP_ANDROID_INCREMENTAL_BUILDS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the NativePHP development server that allows hot
    | reloading of mobile applications during development.
    |
    */

    'server' => [
        // HTTP server port for serving the app
        'http_port' => env('NATIVEPHP_HTTP_PORT', 3000),

        // WebSocket server port for hot reload communication
        'ws_port' => env('NATIVEPHP_WS_PORT', 8081),

        // Service name advertised on the network
        'service_name' => env('NATIVEPHP_SERVICE_NAME', 'NativePHP Server'),

        // Service type for mDNS advertisement
        'service_type' => '_http._tcp',

        // Public directory to serve (relative to Laravel root)
        'public_path' => env('NATIVEPHP_PUBLIC_PATH', 'public'),

        // Build output directory (where the ZIP will be created)
        'build_path' => env('NATIVEPHP_BUILD_PATH', 'storage/app/native-build'),

        // Automatically open browser with QR code when server starts.
        // Default off — the terminal renders a scannable QR. Pass --browser
        // to native:jump (or set NATIVEPHP_OPEN_BROWSER=true) to opt in.
        'open_browser' => env('NATIVEPHP_OPEN_BROWSER', false),

        // Watch these directories for changes
        'watch_paths' => [
            'app',
            'resources',
            'routes',
            'public/build',
        ],

        // File extensions to watch for changes
        'watch_extensions' => ['php', 'blade.php', 'js', 'css', 'ts', 'vue', 'json'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hot Reload Configuration
    |--------------------------------------------------------------------------
    */
    'hot_reload' => [
        'watch_paths' => [
            'app',
            'resources',
            'routes',
            'config',
            'public',
        ],

        'exclude_patterns' => [
            '\.git',
            'storage',
            'tests',
            'nativephp',
            'credentials',
            'node_modules',
            '\.swp',
            '\.tmp',
            '~',
            '\.log',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | App Store Connect API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for uploading apps to App Store Connect using the API.
    | These values are used for automated uploads during the package process.
    | Store sensitive data in environment variables for security.
    |
    */
    'app_store_connect' => [
        'api_key' => env('APP_STORE_API_KEY'),
        'api_key_id' => env('APP_STORE_API_KEY_ID'),
        'api_issuer_id' => env('APP_STORE_API_ISSUER_ID'),
        'app_name' => env('APP_STORE_APP_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | iPad Support
    |--------------------------------------------------------------------------
    |
    | Enable or disable iPad support for your iOS app. When enabled, your app
    | will support iPad devices and all iPad orientations (portrait, upside down,
    | landscape left, and landscape right) as required by Apple's App Store
    | guidelines. When disabled, your app will be iPhone-only.
    |
    | Note: Once an app is deployed to the App Store with iPad
    | support you cannot revoke this action.
    |
    */
    'ipad' => false,

    /*
    |--------------------------------------------------------------------------
    | Device Orientation Support
    |--------------------------------------------------------------------------
    |
    | Configure which orientations your app supports on different devices.
    | This will be applied during the build process to set appropriate
    | constraints in Info.plist (iOS) and AndroidManifest.xml (Android).
    |
    | For iPhone and Android, you can configure specific orientations.
    | For iPad, when enabled above, all orientations are automatically supported
    | as required by Apple's App Store guidelines.
    |
    | If all orientations are false for iPhone, the build will fail with a
    | helpful error message. If all orientations are false for Android, the
    | build will fail with a helpful error message.
    |
    */
    'orientation' => [
        'iphone' => [
            'portrait' => true,
            'upside_down' => false,
            'landscape_left' => false,
            'landscape_right' => false,
        ],
        'android' => [
            'portrait' => true,
            'upside_down' => false,
            'landscape_left' => false,
            'landscape_right' => false,
        ],
    ],
];
