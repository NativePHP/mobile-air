/**
 * NativePHP Mobile Bridge Library
 * Provides native device functions from JavaScript/TypeScript
 *
 * @example Core Imports
 * import { Dialog, Device, Camera, Biometric, Geolocation } from '@nativephp/mobile';
 *
 * // Show alert dialog
 * await Dialog.alert('Hello', 'Welcome to NativePHP');
 *
 * // Take a photo
 * await Camera.getPhoto().id('profile-pic');
 *
 * // Get location
 * await Geolocation.getCurrentPosition().fineAccuracy(true);
 */

const baseUrl = '/_native/api/call';

/**
 * Bridge call function - make calls to registered native bridge functions
 * @param {string} method - The registered method name (e.g., 'Dialog.Alert', 'Camera.GetPhoto')
 * @param {object} params - Parameters to pass to the native function
 * @returns {Promise<any>} The response data from the native function
 *
 * @example Custom bridge function call
 * import { BridgeCall } from '@nativephp/mobile';
 *
 * // Call a custom registered function
 * const result = await BridgeCall('MyPlugin.CustomAction', { foo: 'bar' });
 */
export async function BridgeCall(method, params = {}) {
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

    title(title) {
        this._title = title;
        return this;
    }

    message(message) {
        this._message = message;
        return this;
    }

    buttons(buttons) {
        this._buttons = buttons;
        return this;
    }

    id(id) {
        this._id = id;
        return this;
    }

    event(event) {
        this._event = event;
        return this;
    }

    confirm(title, message) {
        this._title = title;
        this._message = message;
        this._buttons = ['Cancel', 'OK'];
        return this;
    }

    confirmDelete(title, message) {
        this._title = title;
        this._message = message;
        this._buttons = ['Cancel', 'Delete'];
        return this;
    }

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

        return BridgeCall('Dialog.Alert', params).then(resolve, reject);
    }
}

function alertFunction(title, message, buttons, id, event) {
    if (arguments.length === 0) {
        return new PendingDialog();
    }

    const params = { title, message, buttons: buttons || ['OK'] };
    if (id) params.id = id;
    if (event) params.event = event;
    return BridgeCall('Dialog.Alert', params);
}

function toastFunction(message, duration = 'long') {
    return BridgeCall('Dialog.Toast', { message, duration });
}

export const Dialog = {
    alert: alertFunction,
    toast: toastFunction
};

export { PendingDialog };

// ============================================================================
// Device Functions
// ============================================================================

export async function DeviceVibrate() {
    return BridgeCall('Device.Vibrate', {});
}

export async function Flashlight() {
    return BridgeCall('Device.ToggleFlashlight', {});
}

export async function GetId() {
    return BridgeCall('Device.GetId', {});
}

export async function GetInfo() {
    return BridgeCall('Device.GetInfo', {});
}

export async function GetBatteryInfo() {
    return BridgeCall('Device.GetBatteryInfo', {});
}

export const Device = {
    vibrate: DeviceVibrate,
    flashlight: Flashlight,
    getId: GetId,
    getInfo: GetInfo,
    getBatteryInfo: GetBatteryInfo
};

// ============================================================================
// Haptics Functions (legacy - use Device.vibrate() instead)
// ============================================================================

export const Haptics = {
    vibrate: DeviceVibrate
};

// ============================================================================
// System Functions
// ============================================================================

export async function IsIos() {
    const result = await GetInfo();
    if (result && result.info) {
        const deviceInfo = JSON.parse(result.info);
        return deviceInfo.platform === 'ios';
    }
    return false;
}

export async function IsAndroid() {
    const result = await GetInfo();
    if (result && result.info) {
        const deviceInfo = JSON.parse(result.info);
        return deviceInfo.platform === 'android';
    }
    return false;
}

export async function IsMobile() {
    const result = await GetInfo();
    if (result && result.info) {
        const deviceInfo = JSON.parse(result.info);
        const platform = deviceInfo.platform || null;
        return ['ios', 'android'].includes(platform);
    }
    return false;
}

export const System = {
    isIos: IsIos,
    isAndroid: IsAndroid,
    isMobile: IsMobile,
    flashlight: Flashlight
};

// ============================================================================
// Network Functions
// ============================================================================

export async function NetworkStatus() {
    return BridgeCall('Network.Status');
}

export const Network = {
    status: NetworkStatus
};

// ============================================================================
// Share Functions
// ============================================================================

export async function ShareFile(title, message, path) {
    return BridgeCall('Share.File', { title, message, filePath: path });
}

export async function ShareUrl(title, text, url) {
    return BridgeCall('Share.Url', { title, text, url });
}

export const Share = {
    file: ShareFile,
    url: ShareUrl
};

// ============================================================================
// SecureStorage Functions
// ============================================================================

export async function SecureStorageSet(key, value) {
    return BridgeCall('SecureStorage.Set', { key, value });
}

export async function SecureStorageGet(key) {
    return BridgeCall('SecureStorage.Get', { key });
}

export async function SecureStorageDelete(key) {
    return BridgeCall('SecureStorage.Delete', { key });
}

export const SecureStorage = {
    set: SecureStorageSet,
    get: SecureStorageGet,
    delete: SecureStorageDelete
};

// ============================================================================
// File Functions
// ============================================================================

export async function MoveFile(from, to) {
    return BridgeCall('File.Move', { from, to });
}

export async function CopyFile(from, to) {
    return BridgeCall('File.Copy', { from, to });
}

export const File = {
    move: MoveFile,
    copy: CopyFile
};

// ============================================================================
// Edge Functions
// ============================================================================

export async function SetEdge(components) {
    const payload = Array.isArray(components) ? components : [components];
    return BridgeCall('Edge.Set', { components: payload });
}

export function SetEdgeSync(components) {
    const payload = Array.isArray(components) ? components : [components];
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/_native/api/call', false);
    xhr.setRequestHeader('Content-Type', 'application/json');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
    xhr.send(JSON.stringify({
        method: 'Edge.Set',
        params: { components: payload }
    }));
}

export async function ClearEdge() {
    return BridgeCall('Edge.Set', { components: [] });
}

export function ClearEdgeSync() {
    SetEdgeSync([]);
}

export const Edge = {
    set: SetEdge,
    setSync: SetEdgeSync,
    clear: ClearEdge,
    clearSync: ClearEdgeSync
};

// ============================================================================
// Camera Functions
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

    images() {
        this._mediaType = 'image';
        return this;
    }

    videos() {
        this._mediaType = 'video';
        return this;
    }

    all() {
        this._mediaType = 'all';
        return this;
    }

    multiple(enabled = true) {
        this._multiple = enabled;
        return this;
    }

    maxItems(max) {
        this._maxItems = max;
        return this;
    }

    id(id) {
        this._id = id;
        return this;
    }

    event(event) {
        this._event = event;
        return this;
    }

    getId() {
        return this._id;
    }

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

        return BridgeCall('Camera.PickMedia', params).then(resolve, reject);
    }
}

/**
 * PendingPhotoCapture - Fluent builder for capturing photos
 */
class PendingPhotoCapture {
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

    getId() {
        return this._id;
    }

    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return BridgeCall('Camera.GetPhoto', params).then(resolve, reject);
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

    id(id) {
        this._id = id;
        return this;
    }

    event(event) {
        this._event = event;
        return this;
    }

    maxDuration(seconds) {
        this._maxDuration = seconds;
        return this;
    }

    getId() {
        return this._id;
    }

    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;
        if (this._maxDuration) params.maxDuration = this._maxDuration;

        return BridgeCall('Camera.RecordVideo', params).then(resolve, reject);
    }
}

export function Gallery() {
    return new PendingGalleryPick();
}

export async function PickImage(options = {}) {
    return BridgeCall('Camera.PickMedia', {
        mediaType: 'image',
        multiple: false,
        maxItems: 1,
        ...options
    });
}

export async function PickImages(options = {}) {
    return BridgeCall('Camera.PickMedia', {
        mediaType: 'image',
        multiple: true,
        maxItems: options.maxItems || 10,
        ...options
    });
}

export async function PickVideo(options = {}) {
    return BridgeCall('Camera.PickMedia', {
        mediaType: 'video',
        multiple: false,
        maxItems: 1,
        ...options
    });
}

export async function PickVideos(options = {}) {
    return BridgeCall('Camera.PickMedia', {
        mediaType: 'video',
        multiple: true,
        maxItems: options.maxItems || 10,
        ...options
    });
}

export async function PickMedia(options = {}) {
    return BridgeCall('Camera.PickMedia', {
        mediaType: 'all',
        multiple: options.multiple || false,
        maxItems: options.maxItems || 10,
        ...options
    });
}

export const Camera = {
    getPhoto: () => new PendingPhotoCapture(),
    recordVideo: () => new PendingVideoRecorder(),
    pickImages: () => new PendingGalleryPick()
};

export { PendingGalleryPick, PendingPhotoCapture, PendingVideoRecorder };

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

    id(id) {
        this._id = id;
        return this;
    }

    event(event) {
        this._event = event;
        return this;
    }

    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return BridgeCall('Biometric.Prompt', params).then(resolve, reject);
    }
}

export const Biometrics = {
    prompt: () => new PendingBiometric()
};

// Alias for backwards compatibility
export const Biometric = Biometrics;

export { PendingBiometric };

// ============================================================================
// Geolocation Functions
// ============================================================================

/**
 * PendingGeolocation - Fluent builder for geolocation operations
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

    fineAccuracy(enabled = true) {
        this._fineAccuracy = enabled;
        return this;
    }

    id(id) {
        this._id = id;
        return this;
    }

    event(event) {
        this._event = event;
        return this;
    }

    remember(enabled = true) {
        this._remember = enabled;
        return this;
    }

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

        let method;
        if (this._action === 'getCurrentPosition') {
            method = 'Geolocation.GetCurrentPosition';
        } else if (this._action === 'checkPermissions') {
            method = 'Geolocation.CheckPermissions';
        } else if (this._action === 'requestPermissions') {
            method = 'Geolocation.RequestPermissions';
        }

        return BridgeCall(method, params).then(resolve, reject);
    }
}

export const Geolocation = {
    getCurrentPosition: () => new PendingGeolocation('getCurrentPosition'),
    checkPermissions: () => new PendingGeolocation('checkPermissions'),
    requestPermissions: () => new PendingGeolocation('requestPermissions')
};

export { PendingGeolocation };

// ============================================================================
// Scanner Functions
// ============================================================================

/**
 * PendingScan - Fluent builder for QR/barcode scanning
 */
class PendingScan {
    constructor() {
        this._prompt = null;
        this._continuous = false;
        this._formats = ['qr'];
        this._id = null;
        this._started = false;
    }

    prompt(text) {
        this._prompt = text;
        return this;
    }

    continuous(enabled = true) {
        this._continuous = enabled;
        return this;
    }

    formats(formats) {
        this._formats = formats;
        return this;
    }

    id(id) {
        this._id = id;
        return this;
    }

    getId() {
        return this._id;
    }

    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        return BridgeCall('Scanner.Scan', {
            prompt: this._prompt ?? 'Scan QR Code',
            continuous: this._continuous,
            formats: this._formats,
            id: this._id
        }).then(resolve, reject);
    }
}

export const Scanner = {
    scan: () => new PendingScan()
};

export { PendingScan };

// ============================================================================
// Microphone Functions
// ============================================================================

/**
 * PendingMicrophone - Fluent builder for microphone recording
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

    then(resolve, reject) {
        if (this._started) {
            return resolve();
        }

        this._started = true;

        const params = {};
        if (this._id) params.id = this._id;
        if (this._event) params.event = this._event;

        return BridgeCall('Microphone.Start', params).then(resolve, reject);
    }
}

export const Microphone = {
    record: () => new PendingMicrophone(),
    stop: () => BridgeCall('Microphone.Stop', {}),
    pause: () => BridgeCall('Microphone.Pause', {}),
    resume: () => BridgeCall('Microphone.Resume', {}),
    getStatus: () => BridgeCall('Microphone.GetStatus', {}),
    getRecording: () => BridgeCall('Microphone.GetRecording', {})
};

export { PendingMicrophone };

// ============================================================================
// Browser Functions
// ============================================================================

export async function BrowserOpen(url) {
    const result = await BridgeCall('Browser.Open', { url });
    return result?.success === true;
}

export async function BrowserInApp(url) {
    const result = await BridgeCall('Browser.OpenInApp', { url });
    return result?.success === true;
}

export async function BrowserAuth(url) {
    const result = await BridgeCall('Browser.OpenAuth', { url });
    return result?.success === true;
}

export const Browser = {
    open: BrowserOpen,
    inApp: BrowserInApp,
    auth: BrowserAuth
};

// ============================================================================
// Push Notifications Functions
// ============================================================================

export async function PushNotificationsCheckPermission() {
    const result = await BridgeCall('PushNotification.CheckPermission');
    return result?.status ?? null;
}

export async function PushNotificationsEnroll() {
    const result = await BridgeCall('PushNotification.RequestPermission');
    return result?.success === true;
}

export async function PushNotificationsGetToken() {
    const result = await BridgeCall('PushNotification.GetToken');
    return result?.token ?? null;
}

export const PushNotifications = {
    checkPermission: PushNotificationsCheckPermission,
    enroll: PushNotificationsEnroll,
    getToken: PushNotificationsGetToken
};

// ============================================================================
// Mobile Wallet Functions
// ============================================================================

export async function MobileWalletIsAvailable() {
    return BridgeCall('MobileWallet.IsAvailable');
}

export async function MobileWalletCreatePaymentIntent(options = {}) {
    return BridgeCall('MobileWallet.CreatePaymentIntent', {
        amount: options.amount,
        currency: options.currency || 'usd',
        metadata: options.metadata || {}
    });
}

export async function MobileWalletPresentPaymentSheet(options = {}) {
    if (!options.clientSecret) {
        throw new Error('clientSecret is required');
    }
    if (!options.merchantDisplayName) {
        throw new Error('merchantDisplayName is required');
    }
    if (!options.publishableKey) {
        throw new Error('publishableKey is required');
    }

    return BridgeCall('MobileWallet.PresentPaymentSheet', {
        clientSecret: options.clientSecret,
        merchantDisplayName: options.merchantDisplayName,
        publishableKey: options.publishableKey,
        options: options.additionalOptions || {}
    });
}

export async function MobileWalletConfirmPayment(paymentIntentId) {
    if (!paymentIntentId) {
        throw new Error('paymentIntentId is required');
    }

    return BridgeCall('MobileWallet.ConfirmPayment', { paymentIntentId });
}

export async function MobileWalletGetPaymentStatus(paymentIntentId) {
    if (!paymentIntentId) {
        throw new Error('paymentIntentId is required');
    }

    return BridgeCall('MobileWallet.GetPaymentStatus', { paymentIntentId });
}

export function MobileWalletFormatAmount(amountInCents, currency = 'usd') {
    const amount = amountInCents / 100;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency.toUpperCase()
    }).format(amount);
}

export const MobileWallet = {
    isAvailable: MobileWalletIsAvailable,
    createPaymentIntent: MobileWalletCreatePaymentIntent,
    presentPaymentSheet: MobileWalletPresentPaymentSheet,
    confirmPayment: MobileWalletConfirmPayment,
    getPaymentStatus: MobileWalletGetPaymentStatus,
    formatAmount: MobileWalletFormatAmount
};

// ============================================================================
// Native Event System
// ============================================================================

const _eventListeners = {};
let _nativeEventListenerSetup = false;

function setupNativeEventListener() {
    if (_nativeEventListenerSetup) {
        return;
    }

    document.addEventListener("native-event", function (e) {
        let eventName = e.detail.event.replace(/^(\\\\)+/, '');
        const payload = e.detail.payload;

        const cbs = _eventListeners[eventName] || [];
        cbs.forEach(cb => cb(payload, eventName));
    });

    _nativeEventListenerSetup = true;
}

/**
 * Listen for native events
 * @param {string} eventName - Event name to listen for
 * @param {function} callback - Callback function (payload, eventName) => void
 */
export function On(eventName, callback) {
    setupNativeEventListener();

    if (!_eventListeners[eventName]) {
        _eventListeners[eventName] = [];
    }
    _eventListeners[eventName].push(callback);
}

/**
 * Stop listening for native events
 * @param {string} eventName - Event name to stop listening for
 * @param {function} callback - Callback function to remove
 */
export function Off(eventName, callback) {
    if (_eventListeners[eventName]) {
        _eventListeners[eventName] = _eventListeners[eventName].filter(cb => cb !== callback);
    }
}

// ============================================================================
// Native Event Constants
// ============================================================================

/**
 * Native event class name constants for type-safe event listening
 * Usage: import { Events, On } from '@nativephp/mobile';
 *        On(Events.Camera.PhotoTaken, (event) => { ... });
 */
export const Events = {
    Alert: {
        ButtonPressed: 'Native\\Mobile\\Events\\Alert\\ButtonPressed',
    },
    App: {
        UpdateInstalled: 'Native\\Mobile\\Events\\App\\UpdateInstalled',
    },
    Camera: {
        PhotoTaken: 'Native\\Mobile\\Events\\Camera\\PhotoTaken',
        PhotoCancelled: 'Native\\Mobile\\Events\\Camera\\PhotoCancelled',
        VideoRecorded: 'Native\\Mobile\\Events\\Camera\\VideoRecorded',
        VideoCancelled: 'Native\\Mobile\\Events\\Camera\\VideoCancelled',
        PermissionDenied: 'Native\\Mobile\\Events\\Camera\\PermissionDenied',
    },
    Gallery: {
        MediaSelected: 'Native\\Mobile\\Events\\Gallery\\MediaSelected',
    },
    Biometrics: {
        Completed: 'Native\\Mobile\\Events\\Biometrics\\BiometricCompleted',
    },
    Geolocation: {
        LocationReceived: 'Native\\Mobile\\Events\\Geolocation\\LocationReceived',
        PermissionStatusReceived: 'Native\\Mobile\\Events\\Geolocation\\PermissionStatusReceived',
        PermissionRequestResult: 'Native\\Mobile\\Events\\Geolocation\\PermissionRequestResult',
    },
    Scanner: {
        CodeScanned: 'Native\\Mobile\\Events\\Scanner\\CodeScanned',
        Cancelled: 'Native\\Mobile\\Events\\Scanner\\ScannerCancelled',
    },
    Microphone: {
        Recorded: 'Native\\Mobile\\Events\\Microphone\\MicrophoneRecorded',
        Cancelled: 'Native\\Mobile\\Events\\Microphone\\MicrophoneCancelled',
    },
    PushNotification: {
        TokenGenerated: 'Native\\Mobile\\Events\\PushNotification\\TokenGenerated',
    },
    Wallet: {
        PaymentCompleted: 'Native\\Mobile\\Events\\Wallet\\PaymentCompleted',
        PaymentFailed: 'Native\\Mobile\\Events\\Wallet\\PaymentFailed',
        PaymentCancelled: 'Native\\Mobile\\Events\\Wallet\\PaymentCancelled',
    },
};

// Legacy export for backwards compatibility
export const CoreEvents = Events;
