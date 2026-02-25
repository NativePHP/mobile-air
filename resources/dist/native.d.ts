/**
 * NativePHP Mobile Bridge Library TypeScript Declarations
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
 * import { BridgeCall } from '@nativephp/mobile';
 *
 * // Call a custom registered function
 * const result = await BridgeCall('MyPlugin.CustomAction', { foo: 'bar' });
 */
export function BridgeCall(method: string, params?: Record<string, any>): Promise<any>;

export interface EdgeComponent {
    type: string;
    data: Record<string, any>;
}

// ============================================================================
// Dialog Functions
// ============================================================================

export class PendingDialog implements PromiseLike<void> {
    constructor();
    title(title: string): PendingDialog;
    message(message: string): PendingDialog;
    buttons(buttons: string[]): PendingDialog;
    id(id: string): PendingDialog;
    event(event: string): PendingDialog;
    confirm(title: string, message: string): PendingDialog;
    confirmDelete(title: string, message: string): PendingDialog;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export const Dialog: {
    alert: {
        (): PendingDialog;
        (title: string, message: string, buttons?: string[], id?: string, event?: string): Promise<void>;
    };
    toast(message: string, duration?: string): Promise<{ success: boolean }>;
};

// ============================================================================
// Device Functions
// ============================================================================

export function DeviceVibrate(): Promise<{ success: boolean }>;
export function Flashlight(): Promise<{ success: boolean; state: boolean }>;
export function GetId(): Promise<{ id: string }>;
export function GetInfo(): Promise<{ info: string }>;
export function GetBatteryInfo(): Promise<{ info: string }>;

export const Device: {
    vibrate(): Promise<{ success: boolean }>;
    flashlight(): Promise<{ success: boolean; state: boolean }>;
    getId(): Promise<{ id: string }>;
    getInfo(): Promise<{ info: string }>;
    getBatteryInfo(): Promise<{ info: string }>;
};

// ============================================================================
// Haptics Functions (legacy - use Device.vibrate() instead)
// ============================================================================

export const Haptics: {
    vibrate(): Promise<{ success: boolean }>;
};

// ============================================================================
// System Functions
// ============================================================================

export function IsIos(): Promise<boolean>;
export function IsAndroid(): Promise<boolean>;
export function IsMobile(): Promise<boolean>;

export const System: {
    isIos(): Promise<boolean>;
    isAndroid(): Promise<boolean>;
    isMobile(): Promise<boolean>;
    flashlight(): Promise<{ success: boolean; state: boolean }>;
};

// ============================================================================
// Network Functions
// ============================================================================

export function NetworkStatus(): Promise<{ connected: boolean; type?: string }>;

export const Network: {
    status(): Promise<{ connected: boolean; type?: string }>;
};

// ============================================================================
// Share Functions
// ============================================================================

export function ShareFile(title: string, message: string, path: string): Promise<any>;
export function ShareUrl(title: string, text: string, url: string): Promise<any>;

export const Share: {
    file: typeof ShareFile;
    url: typeof ShareUrl;
};

// ============================================================================
// SecureStorage Functions
// ============================================================================

export function SecureStorageSet(key: string, value: string | null): Promise<{ success: boolean }>;
export function SecureStorageGet(key: string): Promise<{ value: string | null }>;
export function SecureStorageDelete(key: string): Promise<{ success: boolean }>;

export const SecureStorage: {
    set: typeof SecureStorageSet;
    get: typeof SecureStorageGet;
    delete: typeof SecureStorageDelete;
};

// ============================================================================
// File Functions
// ============================================================================

export function MoveFile(from: string, to: string): Promise<any>;
export function CopyFile(from: string, to: string): Promise<any>;

export const File: {
    move: typeof MoveFile;
    copy: typeof CopyFile;
};

// ============================================================================
// Edge Functions
// ============================================================================

export function SetEdge(components: Record<string, any> | EdgeComponent[]): Promise<any>;
export function SetEdgeSync(components: Record<string, any> | EdgeComponent[]): void;
export function ClearEdge(): Promise<any>;
export function ClearEdgeSync(): void;

export const Edge: {
    set: typeof SetEdge;
    setSync: typeof SetEdgeSync;
    clear: typeof ClearEdge;
    clearSync: typeof ClearEdgeSync;
};

// ============================================================================
// Camera Functions
// ============================================================================

export class PendingGalleryPick implements PromiseLike<void> {
    constructor();
    images(): PendingGalleryPick;
    videos(): PendingGalleryPick;
    all(): PendingGalleryPick;
    multiple(enabled?: boolean): PendingGalleryPick;
    maxItems(max: number): PendingGalleryPick;
    id(id: string): PendingGalleryPick;
    event(event: string): PendingGalleryPick;
    getId(): string | null;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export class PendingPhotoCapture implements PromiseLike<void> {
    constructor();
    id(id: string): PendingPhotoCapture;
    event(event: string): PendingPhotoCapture;
    getId(): string | null;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export class PendingVideoRecorder implements PromiseLike<void> {
    constructor();
    id(id: string): PendingVideoRecorder;
    event(event: string): PendingVideoRecorder;
    maxDuration(seconds: number): PendingVideoRecorder;
    getId(): string | null;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export function Gallery(): PendingGalleryPick;
export function PickImage(options?: Record<string, any>): Promise<void>;
export function PickImages(options?: Record<string, any>): Promise<void>;
export function PickVideo(options?: Record<string, any>): Promise<void>;
export function PickVideos(options?: Record<string, any>): Promise<void>;
export function PickMedia(options?: Record<string, any>): Promise<void>;

export const Camera: {
    getPhoto(): PendingPhotoCapture;
    recordVideo(): PendingVideoRecorder;
    pickImages(): PendingGalleryPick;
};

// ============================================================================
// Biometric Functions
// ============================================================================

export class PendingBiometric implements PromiseLike<void> {
    constructor();
    id(id: string): PendingBiometric;
    event(event: string): PendingBiometric;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export const Biometrics: {
    prompt(): PendingBiometric;
};

// Alias for backwards compatibility
export const Biometric: typeof Biometrics;

// ============================================================================
// Geolocation Functions
// ============================================================================

export class PendingGeolocation implements PromiseLike<void> {
    constructor(action: 'getCurrentPosition' | 'checkPermissions' | 'requestPermissions');
    fineAccuracy(enabled?: boolean): PendingGeolocation;
    id(id: string): PendingGeolocation;
    event(event: string): PendingGeolocation;
    remember(enabled?: boolean): PendingGeolocation;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export const Geolocation: {
    getCurrentPosition(): PendingGeolocation;
    checkPermissions(): PendingGeolocation;
    requestPermissions(): PendingGeolocation;
};

// ============================================================================
// Scanner Functions
// ============================================================================

export class PendingScan implements PromiseLike<void> {
    constructor();
    prompt(text: string): PendingScan;
    continuous(enabled?: boolean): PendingScan;
    formats(formats: string[]): PendingScan;
    id(id: string): PendingScan;
    getId(): string | null;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export const Scanner: {
    scan(): PendingScan;
};

// ============================================================================
// Microphone Functions
// ============================================================================

export class PendingMicrophone implements PromiseLike<void> {
    constructor();
    id(id: string): PendingMicrophone;
    event(event: string): PendingMicrophone;
    then<TResult1 = void, TResult2 = never>(
        onfulfilled?: ((value: void) => TResult1 | PromiseLike<TResult1>) | null,
        onrejected?: ((reason: any) => TResult2 | PromiseLike<TResult2>) | null
    ): PromiseLike<TResult1 | TResult2>;
}

export const Microphone: {
    record(): PendingMicrophone;
    stop(): Promise<any>;
    pause(): Promise<any>;
    resume(): Promise<any>;
    getStatus(): Promise<any>;
    getRecording(): Promise<any>;
};

// ============================================================================
// Browser Functions
// ============================================================================

export function BrowserOpen(url: string): Promise<boolean>;
export function BrowserInApp(url: string): Promise<boolean>;
export function BrowserAuth(url: string): Promise<boolean>;

export const Browser: {
    open(url: string): Promise<boolean>;
    inApp(url: string): Promise<boolean>;
    auth(url: string): Promise<boolean>;
};

// ============================================================================
// Push Notifications Functions
// ============================================================================

export function PushNotificationsCheckPermission(): Promise<string | null>;
export function PushNotificationsEnroll(): Promise<boolean>;
export function PushNotificationsGetToken(): Promise<string | null>;

export const PushNotifications: {
    checkPermission(): Promise<string | null>;
    enroll(): Promise<boolean>;
    getToken(): Promise<string | null>;
};

// ============================================================================
// Mobile Wallet Functions
// ============================================================================

export function MobileWalletIsAvailable(): Promise<any>;
export function MobileWalletCreatePaymentIntent(options?: {
    amount?: number;
    currency?: string;
    metadata?: Record<string, any>;
}): Promise<any>;
export function MobileWalletPresentPaymentSheet(options: {
    clientSecret: string;
    merchantDisplayName: string;
    publishableKey: string;
    additionalOptions?: Record<string, any>;
}): Promise<any>;
export function MobileWalletConfirmPayment(paymentIntentId: string): Promise<any>;
export function MobileWalletGetPaymentStatus(paymentIntentId: string): Promise<any>;
export function MobileWalletFormatAmount(amountInCents: number, currency?: string): string;

export const MobileWallet: {
    isAvailable: typeof MobileWalletIsAvailable;
    createPaymentIntent: typeof MobileWalletCreatePaymentIntent;
    presentPaymentSheet: typeof MobileWalletPresentPaymentSheet;
    confirmPayment: typeof MobileWalletConfirmPayment;
    getPaymentStatus: typeof MobileWalletGetPaymentStatus;
    formatAmount: typeof MobileWalletFormatAmount;
};

// ============================================================================
// Native Event System
// ============================================================================

export function On(eventName: string, callback: (payload: any, eventName: string) => void): void;
export function Off(eventName: string, callback: (payload: any, eventName: string) => void): void;

// ============================================================================
// Native Event Constants
// ============================================================================

export const Events: {
    Alert: {
        ButtonPressed: string;
    };
    App: {
        UpdateInstalled: string;
    };
    Camera: {
        PhotoTaken: string;
        PhotoCancelled: string;
        VideoRecorded: string;
        VideoCancelled: string;
        PermissionDenied: string;
    };
    Gallery: {
        MediaSelected: string;
    };
    Biometrics: {
        Completed: string;
    };
    Geolocation: {
        LocationReceived: string;
        PermissionStatusReceived: string;
        PermissionRequestResult: string;
    };
    Scanner: {
        CodeScanned: string;
        Cancelled: string;
    };
    Microphone: {
        Recorded: string;
        Cancelled: string;
    };
    PushNotification: {
        TokenGenerated: string;
    };
    Wallet: {
        PaymentCompleted: string;
        PaymentFailed: string;
        PaymentCancelled: string;
    };
};

// Legacy export for backwards compatibility
export const CoreEvents: typeof Events;