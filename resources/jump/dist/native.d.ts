/**
 * NativePHP Bridge Library TypeScript Declarations
 *
 * @example Modular Imports - Namespace
 * import { camera, dialog, scanner } from '@nativephp/native';
 *
 * await dialog.alert('Hello', 'Welcome');
 * const photo = await camera.getPhoto();
 *
 * @example Modular Imports - Individual Functions
 * import { getPhoto, alert, scanner } from '@nativephp/native';
 *
 * const photo = await getPhoto();
 * await alert('Success', 'Photo captured!');
 *
 * @example Scanner - Fluent Builder API
 * import { scanner } from '@nativephp/native';
 *
 * // Basic QR scan
 * await scanner().scan();
 *
 * // Advanced scanning with options
 * await scanner()
 *   .prompt('Scan your ticket')
 *   .continuous(true)
 *   .formats(['qr', 'ean13'])
 *   .id('ticket-scanner')
 *   .scan();
 *
 * @example Scanner - Simple Options Object
 * import { scanQR } from '@nativephp/native';
 *
 * await scanQR({
 *   prompt: 'Scan barcode',
 *   formats: ['ean13', 'code128'],
 *   id: 'product-scanner'
 * });
 *
 * @example Custom Bridge Function Calls
 * import { bridgeCall } from '@nativephp/native';
 *
 * // Call a custom registered function
 * const result = await bridgeCall('MyPlugin.CustomAction', { foo: 'bar' });
 */

// ============================================================================
// Bridge Call Function
// ============================================================================

/**
 * Make calls to registered native bridge functions
 * Use this to call custom bridge functions registered by plugins
 *
 * @param method - The registered method name (e.g., 'Dialog.Alert', 'MyPlugin.DoSomething')
 * @param params - Parameters to pass to the native function
 * @returns The response data from the native function
 *
 * @example
 * import { bridgeCall } from '@nativephp/native';
 *
 * // Call a custom registered function
 * const result = await bridgeCall('MyPlugin.CustomAction', { foo: 'bar' });
 */
export function bridgeCall(method: string, params?: Record<string, any>): Promise<any>;

// Edge Component Interfaces
export interface EdgeComponent {
    type: string;
    data: Record<string, any>;
}

// ============================================================================
// Dialog Functions
// ============================================================================

/**
 * PendingDialog - Fluent builder for native dialogs
 */
export class PendingDialog implements PromiseLike<void> {
    constructor();

    /**
     * Set dialog title
     */
    title(title: string): PendingDialog;

    /**
     * Set dialog message
     */
    message(message: string): PendingDialog;

    /**
     * Set dialog buttons
     */
    buttons(buttons: string[]): PendingDialog;

    /**
     * Set a unique identifier for this dialog
     */
    id(id: string): PendingDialog;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingDialog;

    /**
     * Quick confirm dialog (OK/Cancel)
     */
    confirm(title: string, message: string): PendingDialog;

    /**
     * Quick destructive confirm (Cancel/Delete)
     */
    confirmDelete(title: string, message: string): PendingDialog;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .show() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * Dialog namespace - matches PHP Dialog facade
 */
export const dialog: {
    /**
     * Show a native alert dialog
     * Can be called with parameters for immediate use, or without to get a builder
     *
     * @example Simple usage
     * await dialog.alert('Title', 'Message');
     *
     * @example Builder usage
     * await dialog.alert()
     *   .title('Title')
     *   .message('Message')
     *   .buttons(['OK', 'Cancel'])
     *   .show();
     */
    alert: {
        (): PendingDialog;
        (title: string, message: string, buttons?: string[], id?: string, event?: string): Promise<void>;
    };

    /**
     * Show a toast notification
     */
    toast(message: string, duration?: string): Promise<{ success: boolean }>;
};

// ============================================================================
// Biometric Functions
// ============================================================================

/**
 * PendingBiometric - Fluent builder for biometric authentication
 */
export class PendingBiometric implements PromiseLike<void> {
    constructor();

    /**
     * Set a unique identifier for this authentication
     */
    id(id: string): PendingBiometric;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingBiometric;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .prompt() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * Biometric namespace - matches PHP Biometrics facade
 */
export const biometric: {
    /**
     * Prompt for biometric authentication (Face ID, Fingerprint, etc.)
     * Returns a PendingBiometric builder
     *
     * @example
     * await biometric.prompt().id('auth-check').prompt();
     */
    prompt(): PendingBiometric;
};

// ============================================================================
// Device Functions
// ============================================================================

/**
 * Device namespace - matches PHP Device facade
 */
export const device: {
    /**
     * Vibrate the device with a short haptic feedback
     */
    vibrate(): Promise<{ success: boolean }>;

    /**
     * Toggle the device flashlight on/off
     */
    flashlight(): Promise<{ success: boolean; state: boolean }>;

    /**
     * Get the unique device ID
     */
    getId(): Promise<{ id: string }>;

    /**
     * Get detailed device information
     */
    getInfo(): Promise<{ info: string }>;

    /**
     * Get battery information
     */
    getBatteryInfo(): Promise<{ info: string }>;
};

// ============================================================================
// System Functions
// ============================================================================

/**
 * Check if the current platform is iOS
 */
export function isIos(): Promise<boolean>;

/**
 * Check if the current platform is Android
 */
export function isAndroid(): Promise<boolean>;

/**
 * Check if running on a mobile platform (iOS or Android)
 */
export function isMobile(): Promise<boolean>;

/**
 * System namespace - matches PHP System facade
 * Provides platform detection utilities
 */
export const system: {
    /**
     * Check if the current platform is iOS
     */
    isIos(): Promise<boolean>;

    /**
     * Check if the current platform is Android
     */
    isAndroid(): Promise<boolean>;

    /**
     * Check if running on a mobile platform (iOS or Android)
     */
    isMobile(): Promise<boolean>;

    /**
     * Toggle the device flashlight on/off
     * @deprecated Use device.flashlight() instead
     */
    flashlight(): Promise<{ success: boolean; state: boolean }>;
};

// ============================================================================
// Browser Functions
// ============================================================================

/**
 * Open a URL in the system's default browser
 */
export function openBrowser(url: string): Promise<boolean>;

/**
 * Open a URL in an in-app browser (SFSafariViewController on iOS, Custom Tabs on Android)
 */
export function openInApp(url: string): Promise<boolean>;

/**
 * Open a URL in an authentication session (ASWebAuthenticationSession on iOS)
 * Automatically handles OAuth callbacks with nativephp:// scheme
 */
export function openAuth(url: string): Promise<boolean>;

/**
 * Browser namespace - matches PHP Browser facade
 */
export const browser: {
    /**
     * Open a URL in the system's default browser
     */
    open(url: string): Promise<boolean>;

    /**
     * Open a URL in an in-app browser (SFSafariViewController on iOS, Custom Tabs on Android)
     */
    inApp(url: string): Promise<boolean>;

    /**
     * Open a URL in an authentication session (ASWebAuthenticationSession on iOS)
     * Automatically handles OAuth callbacks with nativephp:// scheme
     */
    auth(url: string): Promise<boolean>;
};

// ============================================================================
// Scanner Functions
// ============================================================================

/**
 * Scanner options interface
 */
export interface ScannerOptions {
    prompt?: string;
    continuous?: boolean;
    formats?: string[];
    id?: string | null;
}

/**
 * PendingScan - Fluent builder for QR/barcode scanning
 * Matches the PHP Scanner API
 */
export class PendingScan implements PromiseLike<void> {
    constructor();

    /**
     * Set the prompt text shown on the scanner screen
     */
    prompt(text: string): PendingScan;

    /**
     * Enable continuous scanning (scan multiple codes without closing)
     */
    continuous(enabled?: boolean): PendingScan;

    /**
     * Set which barcode formats to scan
     * Options: 'qr', 'ean13', 'ean8', 'code128', 'code39', 'upca', 'upce', 'all'
     */
    formats(formats: string[]): PendingScan;

    /**
     * Set a unique identifier for this scan session
     */
    id(id: string): PendingScan;

    /**
     * Get the scan session ID
     */
    getId(): string | null;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .scan() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * Scanner namespace - matches PHP Scanner facade
 */
export const scanner: {
    /**
     * Scan a QR code or barcode
     * Returns a PendingScan builder
     *
     * @example
     * await scanner.scan()
     *   .prompt('Scan your ticket')
     *   .formats(['qr', 'ean13'])
     *   .scan();
     */
    scan(): PendingScan;
};

// ============================================================================
// Gallery Functions
// ============================================================================

/**
 * Gallery picker options interface
 */
export interface GalleryOptions {
    mediaType?: 'image' | 'video' | 'all';
    multiple?: boolean;
    maxItems?: number;
    id?: string | null;
    event?: string | null;
}

/**
 * PendingGalleryPick - Fluent builder for picking media from device gallery
 */
export class PendingGalleryPick implements PromiseLike<void> {
    constructor();

    /**
     * Pick only images
     */
    images(): PendingGalleryPick;

    /**
     * Pick only videos
     */
    videos(): PendingGalleryPick;

    /**
     * Pick any media type (images and videos)
     */
    all(): PendingGalleryPick;

    /**
     * Allow multiple selection
     */
    multiple(enabled?: boolean): PendingGalleryPick;

    /**
     * Set maximum number of items when multiple selection is enabled
     */
    maxItems(max: number): PendingGalleryPick;

    /**
     * Set a unique identifier for this gallery pick
     */
    id(id: string): PendingGalleryPick;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingGalleryPick;

    /**
     * Get the gallery pick session ID
     */
    getId(): string | null;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .pick() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * Create a new gallery picker instance
 */
export function gallery(): PendingGalleryPick;

/**
 * Pick a single image from gallery
 */
export function pickImage(options?: Omit<GalleryOptions, 'mediaType' | 'multiple' | 'maxItems'>): Promise<void>;

/**
 * Pick multiple images from gallery
 */
export function pickImages(options?: Omit<GalleryOptions, 'mediaType' | 'multiple'>): Promise<void>;

/**
 * Pick a single video from gallery
 */
export function pickVideo(options?: Omit<GalleryOptions, 'mediaType' | 'multiple' | 'maxItems'>): Promise<void>;

/**
 * Pick multiple videos from gallery
 */
export function pickVideos(options?: Omit<GalleryOptions, 'mediaType' | 'multiple'>): Promise<void>;

/**
 * Pick any media (images or videos) from gallery
 */
export function pickMedia(options?: Omit<GalleryOptions, 'mediaType'>): Promise<void>;

// ============================================================================
// Network Functions
// ============================================================================

/**
 * Get network status
 */
export function networkStatus(): Promise<{
    connected: boolean;
    type?: string;
}>;

export const network: {
    status: typeof networkStatus;
};

// ============================================================================
// Camera Functions
// ============================================================================

/**
 * PendingPhotoCapture - Fluent builder for capturing photos
 */
export class PendingPhotoCapture implements PromiseLike<void> {
    constructor();

    /**
     * Set a unique identifier for this photo capture
     */
    id(id: string): PendingPhotoCapture;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingPhotoCapture;

    /**
     * Get the operation ID
     */
    getId(): string | null;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .capture() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * PendingVideoRecorder - Fluent builder for recording videos
 */
export class PendingVideoRecorder implements PromiseLike<void> {
    constructor();

    /**
     * Set a unique identifier for this video recording
     */
    id(id: string): PendingVideoRecorder;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingVideoRecorder;

    /**
     * Set maximum recording duration
     */
    maxDuration(seconds: number): PendingVideoRecorder;

    /**
     * Get the operation ID
     */
    getId(): string | null;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .record() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * Camera namespace - matches PHP Camera facade
 */
export const camera: {
    /**
     * Capture a photo using the device camera
     * Returns a PendingPhotoCapture builder
     *
     * @example
     * await camera.getPhoto()
     *   .id('profile-pic')
     *   .capture();
     */
    getPhoto(): PendingPhotoCapture;

    /**
     * Record a video using the device camera
     * Returns a PendingVideoRecorder builder
     *
     * @example
     * await camera.recordVideo()
     *   .maxDuration(60)
     *   .record();
     */
    recordVideo(): PendingVideoRecorder;

    /**
     * Pick media from the device gallery
     * Returns a PendingGalleryPick builder
     *
     * @example
     * await camera.pickImages()
     *   .multiple()
     *   .maxItems(5)
     *   .pick();
     */
    pickImages(): PendingGalleryPick;
};

// ============================================================================
// Microphone Functions
// ============================================================================

/**
 * PendingMicrophone - Fluent builder for microphone recording
 */
export class PendingMicrophone implements PromiseLike<void> {
    constructor();

    /**
     * Set a unique identifier for this recording
     */
    id(id: string): PendingMicrophone;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingMicrophone;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .record() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * Microphone namespace - matches PHP Microphone facade
 */
export const microphone: {
    /**
     * Start microphone recording
     * Returns a PendingMicrophone builder
     *
     * @example
     * await microphone.record()
     *   .id('voice-memo')
     *   .record();
     */
    record(): PendingMicrophone;

    /**
     * Stop microphone recording
     */
    stop(): Promise<any>;

    /**
     * Pause microphone recording
     */
    pause(): Promise<any>;

    /**
     * Resume microphone recording
     */
    resume(): Promise<any>;

    /**
     * Get microphone recording status
     */
    getStatus(): Promise<any>;

    /**
     * Get the path to the last recorded audio file
     */
    getRecording(): Promise<any>;
};

// ============================================================================
// Share Functions
// ============================================================================

/**
 * Share a file or text using the native share sheet
 * @param title - Share dialog title / subject (optional)
 * @param message - Text message to share (optional)
 * @param path - File path to share (optional)
 */
export function shareFile(title: string, message: string, path: string): Promise<any>;

export const share: {
    file: typeof shareFile;
};

// ============================================================================
// SecureStorage Functions
// ============================================================================

/**
 * Store a value securely in the device keychain/keystore
 */
export function secureStorageSet(key: string, value: string | null): Promise<{ success: boolean }>;

/**
 * Retrieve a value from secure storage
 */
export function secureStorageGet(key: string): Promise<{ value: string | null }>;

/**
 * Delete a value from secure storage
 */
export function secureStorageDelete(key: string): Promise<{ success: boolean }>;

export const secureStorage: {
    set: typeof secureStorageSet;
    get: typeof secureStorageGet;
    delete: typeof secureStorageDelete;
};

// ============================================================================
// File Functions
// ============================================================================

/**
 * Move a file
 */
export function moveFile(from: string, to: string): Promise<any>;

/**
 * Copy a file
 */
export function copyFile(from: string, to: string): Promise<any>;

export const file: {
    move: typeof moveFile;
    copy: typeof copyFile;
};

// ============================================================================
// Geolocation Functions
// ============================================================================

/**
 * PendingGeolocation - Fluent builder for geolocation operations
 */
export class PendingGeolocation implements PromiseLike<void> {
    constructor(action: 'getCurrentPosition' | 'checkPermissions' | 'requestPermissions');

    /**
     * Use fine accuracy (GPS) instead of coarse (network-based)
     */
    fineAccuracy(enabled?: boolean): PendingGeolocation;

    /**
     * Set a unique identifier for this operation
     */
    id(id: string): PendingGeolocation;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingGeolocation;

    /**
     * Remember the permission decision
     */
    remember(enabled?: boolean): PendingGeolocation;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .get() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * Geolocation namespace - matches PHP Geolocation facade
 */
export const geolocation: {
    /**
     * Get the current GPS location of the device
     * Returns a PendingGeolocation builder
     *
     * @example
     * await geolocation.getCurrentPosition()
     *   .fineAccuracy(true)
     *   .id('current-loc')
     *   .get();
     */
    getCurrentPosition(): PendingGeolocation;

    /**
     * Check current location permissions status
     * Returns a PendingGeolocation builder
     *
     * @example
     * await geolocation.checkPermissions()
     *   .id('perm-check')
     *   .get();
     */
    checkPermissions(): PendingGeolocation;

    /**
     * Request location permissions from the user
     * Returns a PendingGeolocation builder
     *
     * @example
     * await geolocation.requestPermissions()
     *   .remember()
     *   .get();
     */
    requestPermissions(): PendingGeolocation;
};

// ============================================================================
// Push Notifications Functions
// ============================================================================

/**
 * PendingPushNotificationEnrollment - Fluent builder for push notification enrollment
 */
export class PendingPushNotificationEnrollment implements PromiseLike<void> {
    constructor();

    /**
     * Set a unique identifier for this enrollment
     */
    id(id: string): PendingPushNotificationEnrollment;

    /**
     * Set a custom event class name to fire
     */
    event(event: string): PendingPushNotificationEnrollment;

    /**
     * Get the enrollment ID (generates a UUID if not set)
     */
    getId(): string;

    /**
     * Remember this enrollment ID for later retrieval
     * Note: In JavaScript, this just ensures an ID is generated
     * Session storage is handled on the PHP side
     */
    remember(): PendingPushNotificationEnrollment;

    /**
     * Makes this builder thenable - can be awaited directly
     * No need to call .enroll() anymore, just await the builder
     */
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

/**
 * PushNotifications namespace - matches PHP PushNotifications facade
 */
export const pushNotifications: {
    /**
     * Check current push notification permission status without prompting the user
     * Returns: "granted", "denied", "not_determined", "provisional", or "ephemeral"
     */
    checkPermission(): Promise<{ status: string }>;

    /**
     * Request push notification permissions and enroll
     * Returns a PendingPushNotificationEnrollment builder
     *
     * @example
     * await pushNotifications.enroll()
     *   .id('main-enrollment')
     *   .remember()
     *   .enroll();
     */
    enroll(): PendingPushNotificationEnrollment;

    /**
     * Get the current push notification token
     * Returns APNS token on iOS, FCM token on Android, or null if not available
     */
    getToken(): Promise<{ token: string | null }>;
};

// ============================================================================
// Browser Functions
// ============================================================================

/**
 * Open a URL in the system's default browser
 *
 * @param url - The URL to open
 * @returns Promise resolving to success status
 *
 * @example
 * const success = await openInBrowser('https://example.com');
 * if (success) {
 *   console.log('URL opened in browser');
 * }
 */
export function openInBrowser(url: string): Promise<{ success: boolean }>;

/**
 * Open a URL in an in-app browser
 * Uses SFSafariViewController on iOS and Chrome Custom Tabs on Android
 *
 * @param url - The URL to open
 * @returns Promise resolving to success status
 *
 * @example
 * await openInAppBrowser('https://example.com');
 */
export function openInAppBrowser(url: string): Promise<{ success: boolean }>;

/**
 * Open a URL in an authentication session
 * Uses ASWebAuthenticationSession on iOS
 * Automatically handles OAuth callbacks with nativephp:// scheme
 *
 * @param url - The authentication URL to open
 * @returns Promise resolving to success status
 *
 * @example
 * await openAuthSession('https://auth.example.com/oauth');
 */
export function openAuthSession(url: string): Promise<{ success: boolean }>;

// ============================================================================
// Edge Functions
// ============================================================================

/**
 * Set Edge components (async version)
 * @param components - Component data or array of components
 * @returns Promise that resolves when components are set
 */
export function setEdge(components: Record<string, any> | EdgeComponent[]): Promise<any>;

/**
 * Set Edge components (synchronous version)
 *
 * Use this for Inertia/Vue/React SPAs where async calls may not complete
 * reliably during navigation. This blocks the JS thread until the native
 * side processes the update.
 *
 * @param components - Component data or array of components
 *
 * @example
 * import { edge } from '#nativephp';
 *
 * // In an Inertia navigation handler
 * router.on('finish', () => {
 *     edge.setSync([
 *         { type: 'bottom_nav', data: { children: [...] } }
 *     ]);
 * });
 */
export function setEdgeSync(components: Record<string, any> | EdgeComponent[]): void;

/**
 * Clear all Edge components
 *
 * Removes all native UI components (TopBar, BottomNav, SideNav, Fab).
 * Useful when logging out or navigating to a screen without native UI.
 *
 * @returns Promise that resolves when components are cleared
 */
export function clearEdge(): Promise<any>;

/**
 * Clear all Edge components (synchronous version)
 */
export function clearEdgeSync(): void;

export const edge: {
    /** Set Edge components (async) */
    set: typeof setEdge;
    /** Set Edge components (sync - use for Inertia/SPA navigation) */
    setSync: typeof setEdgeSync;
    /** Clear all Edge components (async) */
    clear: typeof clearEdge;
    /** Clear all Edge components (sync) */
    clearSync: typeof clearEdgeSync;
};

// ============================================================================
// Native Event System
// ============================================================================

/**
 * Listen for native events
 *
 * @example Vue/React/Inertia
 * import { on } from '../../../public/vendor/nativephp-mobile/native';
 *
 * on('Native\\Mobile\\Events\\Camera\\PhotoTaken', (event) => {
 *   console.log('Photo taken:', event);
 * });
 */
export function on(eventName: string, callback: (payload: any, eventName: string) => void): void;

/**
 * Stop listening for native events (cleanup to prevent memory leaks)
 *
 * @example Vue/React/Inertia - Component Cleanup
 * import { on, off } from '../../../public/vendor/nativephp-mobile/native';
 *
 * // In mounted/useEffect
 * const handler = (event) => console.log(event);
 * on('Native\\Mobile\\Events\\Camera\\PhotoTaken', handler);
 *
 * // In unmounted/cleanup
 * off('Native\\Mobile\\Events\\Camera\\PhotoTaken', handler);
 */
export function off(eventName: string, callback: (payload: any, eventName: string) => void): void;

// ============================================================================
// Native Event Constants
// ============================================================================

/**
 * Native event class name constants for type-safe event listening
 * Use these instead of typing out full namespace strings
 *
 * @example
 * import { on, Events } from '@nativephp/native';
 *
 * // Instead of: on('Native\\Mobile\\Events\\Alert\\ButtonPressed', handler)
 * on(Events.Alert.ButtonPressed, handler);
 */
export const Events: {
    Alert: {
        ButtonPressed: string;
    };
    App: {
        UpdateInstalled: string;
    };
    Biometric: {
        Completed: string;
    };
    Camera: {
        PhotoTaken: string;
        PhotoCancelled: string;
        VideoRecorded: string;
        VideoCancelled: string;
    };
    Gallery: {
        MediaSelected: string;
    };
    Geolocation: {
        LocationReceived: string;
        PermissionStatusReceived: string;
        PermissionRequestResult: string;
    };
    Microphone: {
        MicrophoneRecorded: string;
        MicrophoneCancelled: string;
    };
    PushNotification: {
        TokenGenerated: string;
    };
    Scanner: {
        CodeScanned: string;
    };
};
