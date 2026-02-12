/**
 * NativePHP Bridge Library
 * Provides easy access to native device functions from JavaScript/TypeScript
 *
 * @example Modular Imports - Namespace
 * import { camera, dialog, scanner } from '@nativephp/native';
 *
 * // Show alert dialog
 * await dialog.alert('Hello', 'Welcome to NativePHP');
 *
 * // Take photo
 * const result = await camera.getPhoto();
 *
 * // Record video
 * const video = await camera.recordVideo();
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
 * // Listen for scan results (in your Vue/Livewire component)
 * // Livewire.on('native:Native\\Mobile\\Events\\Scanner\\CodeScanned', (event) => {
 * //   console.log('Scanned:', event.code);
 * // });
 *
 * @example Scanner - Simple Options Object
 * import { scanQR } from '@nativephp/native';
 *
 * // Quick scan with options
 * await scanQR({
 *   prompt: 'Scan barcode',
 *   formats: ['ean13', 'code128'],
 *   id: 'product-scanner'
 * });
 */

/**
 * Bridge call function - make calls to registered native bridge functions
 * @param {string} method - The registered method name (e.g., 'Dialog.Alert', 'MyPlugin.DoSomething')
 * @param {object} params - Parameters to pass to the native function
 * @returns {Promise<any>} The response data from the native function
 *
 * @example Custom bridge function call
 * import { bridgeCall } from '@nativephp/native';
 *
 * // Call a custom registered function
 * const result = await bridgeCall('MyPlugin.CustomAction', { foo: 'bar' });
 */
const baseUrl = '/_native/api/call';

export async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    return result.data;
}

// ============================================================================
// Dialog Functions
// ============================================================================

/**
 * PendingDialog - Fluent builder for native dialogs
 */
class PendingDialog {
    constructor() {
        this._title = '';
        this._message = '';
        this._buttons = ['OK'];
        this._id = null;
        this._event = null;
        this._started = false;
    }

    /**
     * Set dialog title
     * @param {string} title - Dialog title
     * @returns {PendingDialog}
     */
    title(title) {
        this._title = title;
        return this;
    }

    /**
     * Set dialog message
     * @param {string} message - Dialog message
     * @returns {PendingDialog}
     */
    message(message) {
        this._message = message;
        return this;
    }

    /**
     * Set dialog buttons
     * @param {string[]} buttons - Array of button labels
     * @returns {PendingDialog}
     */
    buttons(buttons) {
        this._buttons = buttons;
        return this;
    }

    /**
     * Set a unique identifier for this dialog
     * @param {string} id - Dialog ID
     * @returns {PendingDialog}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Set a custom event class name to fire
     * @param {string} event - Event class name
     * @returns {PendingDialog}
     */
    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Quick confirm dialog (OK/Cancel)
     * @param {string} title - Dialog title
     * @param {string} message - Dialog message
     * @returns {PendingDialog}
     */
    confirm(title, message) {
        this._title = title;
        this._message = message;
        this._buttons = ['Cancel', 'OK'];
        return this;
    }

    /**
     * Quick destructive confirm (Cancel/Delete)
     * @param {string} title - Dialog title
     * @param {string} message - Dialog message
     * @returns {PendingDialog}
     */
    confirmDelete(title, message) {
        this._title = title;
        this._message = message;
        this._buttons = ['Cancel', 'Delete'];
        return this;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .show() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {
            title: this._title,
            message: this._message,
            buttons: this._buttons
        };

        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return bridgeCall('Dialog.Alert', params).then(resolve, reject);
    }
}

/**
 * Show a native alert dialog
 * Can be called with parameters for immediate use, or without to get a builder
 *
 * @param {string} title - Alert title (optional if using builder)
 * @param {string} message - Alert message (optional if using builder)
 * @param {string[]} buttons - Array of button labels (optional)
 * @param {string} id - Optional ID for the alert
 * @param {string} event - Custom event class name
 * @returns {Promise<void>|PendingDialog}
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
function alertFunction(title, message, buttons, id, event) {
    // If no arguments, return builder
    if (arguments.length === 0) {
        return new PendingDialog();
    }

    // Otherwise, execute immediately
    const params = { title, message, buttons: buttons || ['OK'] };
    if (id) params.id = id;
    if (event) params.event = event;
    return bridgeCall('Dialog.Alert', params);
}

/**
 * Show a toast notification
 * @param {string} message - Toast message
 * @param {string} duration - "short" or "long" (default: "long")
 * @returns {Promise<{success: boolean}>}
 */
function toastFunction(message, duration = 'long') {
    return bridgeCall('Dialog.Toast', { message, duration });
}

export const dialog = {
    alert: alertFunction,
    toast: toastFunction
};

export { PendingDialog };

// ============================================================================
// Biometric Functions
// ============================================================================

/**
 * PendingBiometric - Fluent builder for biometric authentication
 */
class PendingBiometric {
    constructor() {
        this._id = null;
        this._event = null;
        this._started = false;
    }

    /**
     * Set a unique identifier for this authentication
     * @param {string} id - Operation ID
     * @returns {PendingBiometric}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Set a custom event class name to fire
     * @param {string} event - Event class name
     * @returns {PendingBiometric}
     */
    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .prompt() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return bridgeCall('Biometric.Prompt', params).then(resolve, reject);
    }
}

/**
 * Prompt for biometric authentication (Face ID, Fingerprint, etc.)
 * @returns {PendingBiometric}
 */
function promptFunction() {
    return new PendingBiometric();
}

export const biometric = {
    prompt: promptFunction
};

export { PendingBiometric };

// ============================================================================
// Device Functions
// ============================================================================

/**
 * Vibrate the device with a short haptic feedback
 * @returns {Promise<Object>} Object with success boolean
 */
export async function deviceVibrate() {
    return bridgeCall('Device.Vibrate', {});
}

/**
 * Toggle the device flashlight on/off
 * @returns {Promise<Object>} Object with success boolean and state boolean (on=true, off=false)
 */
export async function flashlight() {
    return bridgeCall('Device.ToggleFlashlight', {});
}

/**
 * Get the unique device ID
 * @returns {Promise<Object>} Object with id string
 */
export async function getId() {
    return bridgeCall('Device.GetId', {});
}

/**
 * Get detailed device information
 * @returns {Promise<Object>} Object with info JSON string
 */
export async function getInfo() {
    return bridgeCall('Device.GetInfo', {});
}

/**
 * Get battery information
 * @returns {Promise<Object>} Object with info JSON string (batteryLevel 0-1, isCharging boolean)
 */
export async function getBatteryInfo() {
    return bridgeCall('Device.GetBatteryInfo', {});
}

export const device = {
    vibrate: deviceVibrate,
    flashlight: flashlight,
    getId: getId,
    getInfo: getInfo,
    getBatteryInfo: getBatteryInfo
};

// ============================================================================
// System Functions
// ============================================================================

/**
 * Check if the current platform is iOS
 */
export async function isIos() {
    const result = await getInfo();
    if (result && result.info) {
        const deviceInfo = JSON.parse(result.info);
        return deviceInfo.platform === 'ios';
    }
    return false;
}

/**
 * Check if the current platform is Android
 */
export async function isAndroid() {
    const result = await getInfo();
    if (result && result.info) {
        const deviceInfo = JSON.parse(result.info);
        return deviceInfo.platform === 'android';
    }
    return false;
}

/**
 * Check if running on a mobile platform (iOS or Android)
 */
export async function isMobile() {
    const result = await getInfo();
    if (result && result.info) {
        const deviceInfo = JSON.parse(result.info);
        const platform = deviceInfo.platform || null;
        return ['ios', 'android'].includes(platform);
    }
    return false;
}

export const system = {
    isIos: isIos,
    isAndroid: isAndroid,
    isMobile: isMobile,
    flashlight: flashlight  // Legacy support - deprecated but kept for compatibility
};

// ============================================================================
// Browser Functions
// ============================================================================

/**
 * Open a URL in the system's default browser
 */
export async function openBrowser(url) {
    const result = await bridgeCall('Browser.Open', { url });
    return result?.success === true;
}

/**
 * Open a URL in an in-app browser (SFSafariViewController on iOS, Custom Tabs on Android)
 */
export async function openInApp(url) {
    const result = await bridgeCall('Browser.OpenInApp', { url });
    return result?.success === true;
}

/**
 * Open a URL in an authentication session (ASWebAuthenticationSession on iOS)
 * Automatically handles OAuth callbacks with nativephp:// scheme
 */
export async function openAuth(url) {
    const result = await bridgeCall('Browser.OpenAuth', { url });
    return result?.success === true;
}

export const browser = {
    open: openBrowser,
    inApp: openInApp,
    auth: openAuth
};

// ============================================================================
// Scanner Functions
// ============================================================================

/**
 * PendingScan - Fluent builder for QR/barcode scanning
 * Matches the PHP Scanner API
 */
class PendingScan {
    constructor() {
        this._prompt = null;
        this._continuous = false;
        this._formats = ['qr'];
        this._id = null;
        this._started = false;
    }

    /**
     * Set the prompt text shown on the scanner screen
     * @param {string} text - Prompt text
     * @returns {PendingScan}
     */
    prompt(text) {
        this._prompt = text;
        return this;
    }

    /**
     * Enable continuous scanning (scan multiple codes without closing)
     * @param {boolean} enabled - Enable continuous mode (default: true)
     * @returns {PendingScan}
     */
    continuous(enabled = true) {
        this._continuous = enabled;
        return this;
    }

    /**
     * Set which barcode formats to scan
     * @param {string[]} formats - Array of format strings: 'qr', 'ean13', 'ean8', 'code128', 'code39', 'upca', 'upce', 'all'
     * @returns {PendingScan}
     */
    formats(formats) {
        this._formats = formats;
        return this;
    }

    /**
     * Set a unique identifier for this scan session
     * @param {string} id - Session ID
     * @returns {PendingScan}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Get the scan session ID
     * @returns {string|null}
     */
    getId() {
        return this._id;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .scan() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        return bridgeCall('QrCode.Scan', {
            prompt: this._prompt ?? 'Scan QR Code',
            continuous: this._continuous,
            formats: this._formats,
            id: this._id
        }).then(resolve, reject);
    }
}

/**
 * Scan a QR code or barcode
 * @returns {PendingScan}
 */
function scanFunction() {
    return new PendingScan();
}

export const scanner = {
    scan: scanFunction
};

export { PendingScan };

// ============================================================================
// Gallery Functions
// ============================================================================

/**
 * PendingGalleryPick - Fluent builder for picking media from device gallery
 */
class PendingGalleryPick {
    constructor() {
        this._mediaType = 'all';
        this._multiple = false;
        this._maxItems = 10;
        this._id = null;
        this._event = null;
        this._started = false;
    }

    /**
     * Pick only images
     * @returns {PendingGalleryPick}
     */
    images() {
        this._mediaType = 'image';
        return this;
    }

    /**
     * Pick only videos
     * @returns {PendingGalleryPick}
     */
    videos() {
        this._mediaType = 'video';
        return this;
    }

    /**
     * Pick any media type (images and videos)
     * @returns {PendingGalleryPick}
     */
    all() {
        this._mediaType = 'all';
        return this;
    }

    /**
     * Allow multiple selection
     * @param {boolean} enabled - Enable multiple selection (default: true)
     * @returns {PendingGalleryPick}
     */
    multiple(enabled = true) {
        this._multiple = enabled;
        return this;
    }

    /**
     * Set maximum number of items when multiple selection is enabled
     * @param {number} max - Maximum items (default: 10)
     * @returns {PendingGalleryPick}
     */
    maxItems(max) {
        this._maxItems = max;
        return this;
    }

    /**
     * Set a unique identifier for this gallery pick
     * @param {string} id - Session ID
     * @returns {PendingGalleryPick}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Set a custom event class name to fire
     * @param {string} event - Event class name
     * @returns {PendingGalleryPick}
     */
    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Get the gallery pick session ID
     * @returns {string|null}
     */
    getId() {
        return this._id;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .pick() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {
            mediaType: this._mediaType,
            multiple: this._multiple,
            maxItems: this._maxItems
        };

        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return bridgeCall('Camera.PickMedia', params).then(resolve, reject);
    }
}

/**
 * Create a new gallery picker instance
 * @returns {PendingGalleryPick}
 */
export function gallery() {
    return new PendingGalleryPick();
}

/**
 * Pick a single image from gallery
 * @param {object} options - Gallery options (id, event)
 * @returns {Promise<void>}
 */
export async function pickImage(options = {}) {
    return bridgeCall('Camera.PickMedia', {
        mediaType: 'image',
        multiple: false,
        maxItems: 1,
        ...options
    });
}

/**
 * Pick multiple images from gallery
 * @param {object} options - Gallery options (maxItems, id, event)
 * @returns {Promise<void>}
 */
export async function pickImages(options = {}) {
    return bridgeCall('Camera.PickMedia', {
        mediaType: 'image',
        multiple: true,
        maxItems: options.maxItems || 10,
        ...options
    });
}

/**
 * Pick a single video from gallery
 * @param {object} options - Gallery options (id, event)
 * @returns {Promise<void>}
 */
export async function pickVideo(options = {}) {
    return bridgeCall('Camera.PickMedia', {
        mediaType: 'video',
        multiple: false,
        maxItems: 1,
        ...options
    });
}

/**
 * Pick multiple videos from gallery
 * @param {object} options - Gallery options (maxItems, id, event)
 * @returns {Promise<void>}
 */
export async function pickVideos(options = {}) {
    return bridgeCall('Camera.PickMedia', {
        mediaType: 'video',
        multiple: true,
        maxItems: options.maxItems || 10,
        ...options
    });
}

/**
 * Pick any media (images or videos) from gallery
 * @param {object} options - Gallery options (multiple, maxItems, id, event)
 * @returns {Promise<void>}
 */
export async function pickMedia(options = {}) {
    return bridgeCall('Camera.PickMedia', {
        mediaType: 'all',
        multiple: options.multiple || false,
        maxItems: options.maxItems || 10,
        ...options
    });
}

export { PendingGalleryPick };

// ============================================================================
// Network Functions
// ============================================================================

/**
 * Get network status
 * @returns {Promise<any>}
 */
export async function networkStatus() {
    return bridgeCall('Network.Status');
}

export const network = {
    status: networkStatus
};

// ============================================================================
// Camera Functions
// ============================================================================

/**
 * PendingPhotoCapture - Fluent builder for capturing photos
 */
class PendingPhotoCapture {
    constructor() {
        this._id = null;
        this._event = null;
        this._started = false;
    }

    /**
     * Set a unique identifier for this photo capture
     * @param {string} id - Operation ID
     * @returns {PendingPhotoCapture}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Set a custom event class name to fire
     * @param {string} event - Event class name
     * @returns {PendingPhotoCapture}
     */
    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Get the operation ID
     * @returns {string|null}
     */
    getId() {
        return this._id;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .capture() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return bridgeCall('Camera.GetPhoto', params).then(resolve, reject);
    }
}

/**
 * PendingVideoRecorder - Fluent builder for recording videos
 */
class PendingVideoRecorder {
    constructor() {
        this._id = null;
        this._event = null;
        this._maxDuration = null;
        this._started = false;
    }

    /**
     * Set a unique identifier for this video recording
     * @param {string} id - Operation ID
     * @returns {PendingVideoRecorder}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Set a custom event class name to fire
     * @param {string} event - Event class name
     * @returns {PendingVideoRecorder}
     */
    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Set maximum recording duration
     * @param {number} seconds - Maximum duration in seconds
     * @returns {PendingVideoRecorder}
     */
    maxDuration(seconds) {
        this._maxDuration = seconds;
        return this;
    }

    /**
     * Get the operation ID
     * @returns {string|null}
     */
    getId() {
        return this._id;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .record() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;
        if (this._maxDuration) params.maxDuration = this._maxDuration;

        return bridgeCall('Camera.RecordVideo', params).then(resolve, reject);
    }
}

/**
 * Capture a photo using the device camera
 * @returns {PendingPhotoCapture}
 */
function getPhotoFunction() {
    return new PendingPhotoCapture();
}

/**
 * Record a video using the device camera
 * @returns {PendingVideoRecorder}
 */
function recordVideoFunction() {
    return new PendingVideoRecorder();
}

/**
 * Pick media from the device gallery
 * Note: This uses the existing PendingGalleryPick builder
 * @returns {PendingGalleryPick}
 */
function pickImagesFunction() {
    return new PendingGalleryPick();
}

export const camera = {
    getPhoto: getPhotoFunction,
    recordVideo: recordVideoFunction,
    pickImages: pickImagesFunction
};

export { PendingPhotoCapture, PendingVideoRecorder };

// ============================================================================
// Audio Functions
// ============================================================================

/**
 * Pending microphone recording builder
 * Matches PHP: Microphone::record() returns PendingMicrophone
 */
class PendingMicrophone {
    constructor() {
        this._id = null;
        this._event = null;
        this._started = false;
    }

    id(id) {
        this._id = id;
        return this;
    }

    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .record() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return bridgeCall('Microphone.Start', params).then(resolve, reject);
    }
}

/**
 * Start microphone recording
 * @returns {PendingMicrophone}
 */
function recordMicrophoneFunction() {
    return new PendingMicrophone();
}

/**
 * Stop microphone recording
 * @returns {Promise<any>}
 */
function stopMicrophoneFunction() {
    return bridgeCall('Microphone.Stop', {});
}

/**
 * Pause microphone recording
 * @returns {Promise<any>}
 */
function pauseMicrophoneFunction() {
    return bridgeCall('Microphone.Pause', {});
}

/**
 * Resume microphone recording
 * @returns {Promise<any>}
 */
function resumeMicrophoneFunction() {
    return bridgeCall('Microphone.Resume', {});
}

/**
 * Get microphone recording status
 * @returns {Promise<any>}
 */
function getMicrophoneStatusFunction() {
    return bridgeCall('Microphone.GetStatus', {});
}

/**
 * Get the path to the last recorded audio file
 * @returns {Promise<any>}
 */
function getMicrophoneRecordingFunction() {
    return bridgeCall('Microphone.GetRecording', {});
}

export const microphone = {
    record: recordMicrophoneFunction,
    stop: stopMicrophoneFunction,
    pause: pauseMicrophoneFunction,
    resume: resumeMicrophoneFunction,
    getStatus: getMicrophoneStatusFunction,
    getRecording: getMicrophoneRecordingFunction
};

export { PendingMicrophone };

// ============================================================================
// Geolocation Functions
// ============================================================================

/**
 * Pending geolocation builder
 * Matches PHP: Geolocation methods return PendingGeolocation
 */
class PendingGeolocation {
    constructor(action) {
        this._action = action;
        this._fineAccuracy = false;
        this._id = null;
        this._event = null;
        this._remember = false;
        this._started = false;
    }

    /**
     * Use fine accuracy (GPS) instead of coarse (network-based)
     * @param {boolean} enabled - Enable fine accuracy (default: true)
     * @returns {PendingGeolocation}
     */
    fineAccuracy(enabled = true) {
        this._fineAccuracy = enabled;
        return this;
    }

    /**
     * Set a unique identifier for this operation
     * @param {string} id - Operation ID
     * @returns {PendingGeolocation}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Set a custom event class name to fire
     * @param {string} event - Event class name
     * @returns {PendingGeolocation}
     */
    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Remember the permission decision
     * @param {boolean} enabled - Enable remember (default: true)
     * @returns {PendingGeolocation}
     */
    remember(enabled = true) {
        this._remember = enabled;
        return this;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .get() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._fineAccuracy) params.fineAccuracy = this._fineAccuracy;
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;
        if (this._remember) params.remember = this._remember;

        let promise;
        if (this._action === 'getCurrentPosition') {
            promise = bridgeCall('Geolocation.GetCurrentPosition', params);
        } else if (this._action === 'checkPermissions') {
            promise = bridgeCall('Geolocation.CheckPermissions', params);
        } else if (this._action === 'requestPermissions') {
            promise = bridgeCall('Geolocation.RequestPermissions', params);
        }

        return promise.then(resolve, reject);
    }
}

/**
 * Get the current GPS location of the device
 * @returns {PendingGeolocation}
 */
function getCurrentPositionFunction() {
    return new PendingGeolocation('getCurrentPosition');
}

/**
 * Check current location permissions status
 * @returns {PendingGeolocation}
 */
function checkPermissionsFunction() {
    return new PendingGeolocation('checkPermissions');
}

/**
 * Request location permissions from the user
 * @returns {PendingGeolocation}
 */
function requestPermissionsFunction() {
    return new PendingGeolocation('requestPermissions');
}

export const geolocation = {
    getCurrentPosition: getCurrentPositionFunction,
    checkPermissions: checkPermissionsFunction,
    requestPermissions: requestPermissionsFunction
};

export { PendingGeolocation };

// ============================================================================
// Push Notifications Functions
// ============================================================================

/**
 * Pending push notification enrollment builder
 * Matches PHP: PushNotifications::enroll() returns PendingPushNotificationEnrollment
 */
class PendingPushNotificationEnrollment {
    constructor() {
        this._id = null;
        this._event = 'Native\\Mobile\\Events\\PushNotification\\TokenGenerated';
        this._started = false;
    }

    /**
     * Set a unique identifier for this enrollment
     * @param {string} id - Enrollment ID
     * @returns {PendingPushNotificationEnrollment}
     */
    id(id) {
        this._id = id;
        return this;
    }

    /**
     * Set a custom event class name to fire
     * @param {string} event - Event class name
     * @returns {PendingPushNotificationEnrollment}
     */
    event(event) {
        this._event = event;
        return this;
    }

    /**
     * Get the enrollment ID (generates a UUID if not set)
     * @returns {string}
     */
    getId() {
        if (!this._id) {
            this._id = crypto.randomUUID();
        }
        return this._id;
    }

    /**
     * Remember this enrollment ID for later retrieval
     * Note: In JavaScript, this just ensures an ID is generated
     * Session storage is handled on the PHP side
     * @returns {PendingPushNotificationEnrollment}
     */
    remember() {
        this.getId(); // Ensure ID is generated
        return this;
    }

    /**
     * Make this builder thenable so it can be awaited directly
     * This eliminates the need for .enroll() - just await the builder itself
     * @param {Function} resolve - Promise resolve function
     * @param {Function} reject - Promise reject function
     * @returns {Promise<void>}
     */
    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {
            id: this.getId(),
            event: this._event
        };

        return bridgeCall('PushNotification.RequestPermission', params).then(resolve, reject);
    }
}

/**
 * Check current push notification permission status without prompting the user
 * @returns {Promise<{status: string}>}
 */
function checkPermissionFunction() {
    return bridgeCall('PushNotification.CheckPermission', {});
}

/**
 * Request push notification permissions and enroll
 * @returns {PendingPushNotificationEnrollment}
 */
function enrollFunction() {
    return new PendingPushNotificationEnrollment();
}

/**
 * Get the current push notification token
 * @returns {Promise<{token: string|null}>}
 */
function getTokenFunction() {
    return bridgeCall('PushNotification.GetToken', {});
}

export const pushNotifications = {
    checkPermission: checkPermissionFunction,
    enroll: enrollFunction,
    getToken: getTokenFunction
};

export { PendingPushNotificationEnrollment };

// ============================================================================
// Share Functions
// ============================================================================

/**
 * Share a file or text using the native share sheet
 * @param {string} title - Share dialog title / subject (optional)
 * @param {string} message - Text message to share (optional)
 * @param {string} path - File path to share (optional)
 * @returns {Promise<any>}
 */
export async function shareFile(title, message, path) {
    return bridgeCall('Share.File', { title, message, filePath: path });
}

/**
 * Share a URL using the native share sheet
 * @param {string} title - Share dialog title / subject
 * @param {string} text - Text message to share
 * @param {string} url - URL to share
 * @returns {Promise<any>}
 */
export async function shareUrl(title, text, url) {
    return bridgeCall('Share.Url', { title, text, url });
}

export const share = {
    file: shareFile,
    url: shareUrl
};

// ============================================================================
// SecureStorage Functions
// ============================================================================

/**
 * Store a value securely in the device keychain/keystore
 * @param {string} key - The key to store the value under
 * @param {string|null} value - The value to store securely (null to delete)
 * @returns {Promise<{success: boolean}>}
 */
export async function secureStorageSet(key, value) {
    return bridgeCall('SecureStorage.Set', { key, value });
}

/**
 * Retrieve a value from secure storage
 * @param {string} key - The key to retrieve
 * @returns {Promise<{value: string|null}>}
 */
export async function secureStorageGet(key) {
    return bridgeCall('SecureStorage.Get', { key });
}

/**
 * Delete a value from secure storage
 * @param {string} key - The key to delete
 * @returns {Promise<{success: boolean}>}
 */
export async function secureStorageDelete(key) {
    return bridgeCall('SecureStorage.Delete', { key });
}

export const secureStorage = {
    set: secureStorageSet,
    get: secureStorageGet,
    delete: secureStorageDelete
};

// ============================================================================
// File Functions
// ============================================================================

/**
 * Move a file
 * @param {string} from - Source path
 * @param {string} to - Destination path
 * @returns {Promise<any>}
 */
export async function moveFile(from, to) {
    return bridgeCall('File.Move', { from, to });
}

/**
 * Copy a file
 * @param {string} from - Source path
 * @param {string} to - Destination path
 * @returns {Promise<any>}
 */
export async function copyFile(from, to) {
    return bridgeCall('File.Copy', { from, to });
}

export const file = {
    move: moveFile,
    copy: copyFile
};

// ============================================================================
// Edge Functions
// ============================================================================

/**
 * Set Edge components (async version)
 * @param {object|Array} components - Component data or array of components
 * @returns {Promise<any>}
 */
export async function setEdge(components) {
    const payload = Array.isArray(components) ? components : [components];
    return bridgeCall('Edge.Set', { components: payload });
}

/**
 * Set Edge components (synchronous version)
 *
 * Use this for Inertia/Vue/React SPAs where async calls may not complete
 * reliably during navigation. This blocks the JS thread until the native
 * side processes the update.
 *
 * @param {object|Array} components - Component data or array of components
 * @returns {void}
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
export function setEdgeSync(components) {
    const payload = Array.isArray(components) ? components : [components];
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/_native/api/call', false); // false = synchronous
    xhr.setRequestHeader('Content-Type', 'application/json');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
    xhr.send(JSON.stringify({
        method: 'Edge.Set',
        params: { components: payload }
    }));
}

/**
 * Clear all Edge components
 *
 * Removes all native UI components (TopBar, BottomNav, SideNav, Fab).
 * Useful when logging out or navigating to a screen without native UI.
 *
 * @returns {Promise<any>}
 *
 * @example
 * import { edge } from '#nativephp';
 *
 * // On logout
 * await edge.clear();
 */
export async function clearEdge() {
    return bridgeCall('Edge.Set', { components: [] });
}

/**
 * Clear all Edge components (synchronous version)
 * @returns {void}
 */
export function clearEdgeSync() {
    setEdgeSync([]);
}

export const edge = {
    set: setEdge,
    setSync: setEdgeSync,
    clear: clearEdge,
    clearSync: clearEdgeSync
};

// ============================================================================
// Native Event System
// ============================================================================

/**
 * Internal event listeners storage
 * @private
 */
const _eventListeners = {};

/**
 * Flag to track if we've set up the native-event listener
 * @private
 */
let _nativeEventListenerSetup = false;

/**
 * Set up the document listener for native-event custom events
 * @private
 */
function setupNativeEventListener() {
    if (_nativeEventListenerSetup) {
        return;
    }

    document.addEventListener("native-event", function (e) {
        let eventName = e.detail.event.replace(/^(\\\\)+/, '');
        const payload = e.detail.payload;

        // Dispatch to our listeners
        const cbs = _eventListeners[eventName] || [];
        cbs.forEach(cb => cb(payload, eventName));
    });

    _nativeEventListenerSetup = true;
}

/**
 * Listen for native events
 * @param {string} eventName - Event name to listen for
 * @param {function} callback - Callback function (payload, eventName) => void
 *
 * @example Vue/React/Inertia
 * import { on } from '../../../public/vendor/nativephp-mobile/native';
 *
 * on('Native\\Mobile\\Events\\Camera\\PhotoTaken', (event) => {
 *   console.log('Photo taken:', event);
 * });
 */
export function on(eventName, callback) {
    // Set up the listener on first use
    setupNativeEventListener();

    if (!_eventListeners[eventName]) {
        _eventListeners[eventName] = [];
    }
    _eventListeners[eventName].push(callback);
}

/**
 * Stop listening for native events (cleanup to prevent memory leaks)
 * @param {string} eventName - Event name to stop listening for
 * @param {function} callback - Callback function to remove
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
export function off(eventName, callback) {
    if (_eventListeners[eventName]) {
        _eventListeners[eventName] = _eventListeners[eventName].filter(cb => cb !== callback);
    }
}

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
export const Events = {
    Alert: {
        ButtonPressed: 'Native\\Mobile\\Events\\Alert\\ButtonPressed',
    },
    App: {
        UpdateInstalled: 'Native\\Mobile\\Events\\App\\UpdateInstalled',
    },
    Biometric: {
        Completed: 'Native\\Mobile\\Events\\Biometric\\Completed',
    },
    Camera: {
        PhotoTaken: 'Native\\Mobile\\Events\\Camera\\PhotoTaken',
        PhotoCancelled: 'Native\\Mobile\\Events\\Camera\\PhotoCancelled',
        VideoRecorded: 'Native\\Mobile\\Events\\Camera\\VideoRecorded',
        VideoCancelled: 'Native\\Mobile\\Events\\Camera\\VideoCancelled',
    },
    Gallery: {
        MediaSelected: 'Native\\Mobile\\Events\\Gallery\\MediaSelected',
    },
    Geolocation: {
        LocationReceived: 'Native\\Mobile\\Events\\Geolocation\\LocationReceived',
        PermissionStatusReceived: 'Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived',
        PermissionRequestResult: 'Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult',
    },
    Microphone: {
        MicrophoneRecorded: 'Native\\Mobile\\Events\\Microphone\\MicrophoneRecorded',
        MicrophoneCancelled: 'Native\\Mobile\\Events\\Microphone\\MicrophoneCancelled',
    },
    PushNotification: {
        TokenGenerated: 'Native\\Mobile\\Events\\PushNotification\\TokenGenerated',
    },
    Scanner: {
        CodeScanned: 'Native\\Mobile\\Events\\Scanner\\CodeScanned',
    }
};