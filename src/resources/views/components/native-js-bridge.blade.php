<script>
  /**
   * NativePHP JavaScript Bridge
   * Direct JavaScript → Native communication without PHP middleware
   */
  (function() {
    'use strict';
    const pendingCalls = new Map();
    let callIdCounter = 0;
    function hasAndroidBridge() {
      return !!window.AndroidNativeBridge;
    }
    function hasIOSBridge() {
      return !!window.webkit?.messageHandlers?.nativeBridge;
    }
    async function call(method, parameters = {}) {
      if (hasAndroidBridge()) {
        return callAndroid(method, parameters);
      } else if (hasIOSBridge()) {
        return callIOS(method, parameters);
      }
      console.error(`[NativePHP Bridge] Unavailable`);
    }
    function callAndroid(method, parameters) {
      try {
        const parametersJSON = JSON.stringify(parameters);
        const resultJSON = window.AndroidNativeBridge.call(method, parametersJSON);
        if (resultJSON === null) {
          throw new Error(`Function '${method}' not found in native bridge`);
        }
        const result = JSON.parse(resultJSON);
        if (result.status === 'error') {
          throw new Error(`${result.code}: ${result.message}`);
        }
        return Promise.resolve(result);
      } catch (error) {
        console.error(`[NativePHP Bridge] ${method} failed:`, error);
        throw error;
      }
    }
    function callIOS(method, parameters) {
      return new Promise((resolve, reject) => {
        const callId = callIdCounter++;
        pendingCalls.set(callId, { resolve, reject, method });
        window.webkit.messageHandlers.nativeBridge.postMessage({
          callId,
          method,
          parameters
        });
        setTimeout(() => {
          if (pendingCalls.has(callId)) {
            pendingCalls.delete(callId);
            reject(new Error(`Native call '${method}' timed out after 30s`));
          }
        }, 30000);
      });
    }
    function _resolveCall(callId, result) {
      const pending = pendingCalls.get(callId);
      if (!pending) {
        console.warn(`[NativePHP Bridge] No pending call found for ID ${callId}`);
        return;
      }
      pendingCalls.delete(callId);
      if (result.status === 'error') {
        console.error(`[NativePHP Bridge] ${pending.method} failed:`, result.message);
        pending.reject(new Error(`${result.code}: ${result.message}`));
      } else {
        pending.resolve(result);
      }
    }
    async function can(method) {
      if (hasAndroidBridge()) {
        return window.AndroidNativeBridge.can(method) === 1;
      } else if (hasIOSBridge()) {
        try {
          await call(method, {});
          return true;
        } catch (error) {
          return !error.message.includes('not found');
        }
      }
      return false;
    }
    function getPlatform() {
      if (hasIOSBridge()) return 'ios';
      if (hasAndroidBridge()) return 'android';
      return 'web';
    }
    window.NativePHP = {
      call,
      can,
      _resolveCall,
      get platform() { return getPlatform(); }
    };
  })();
</script>
