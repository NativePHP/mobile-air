<?php

it('stores and migrates iOS APP_KEY with after-first-unlock accessibility and waits before booting when locked', function () {
    $appSource = file_get_contents(dirname(__DIR__, 3).'/resources/xcode/NativePHP/NativePHPApp.swift');
    $runtimeSource = file_get_contents(dirname(__DIR__, 3).'/resources/xcode/NativePHP/Bridge/PersistentPHPRuntime.swift');

    expect($appSource)
        ->toContain('kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly')
        ->not->toContain('kSecAttrAccessibleWhenUnlockedThisDeviceOnly')
        ->toContain('kSecReturnAttributes as String: true')
        ->toContain('if currentAccessibility != targetAccessibility')
        ->toContain('SecItemUpdate(')
        ->toContain('status == errSecInteractionNotAllowed')
        ->toContain('addStatus == errSecInteractionNotAllowed')
        ->toContain('guard status == errSecItemNotFound else')
        ->toContain('UIApplication.shared.isProtectedDataAvailable')
        ->toContain('UIApplication.protectedDataDidBecomeAvailableNotification')
        ->not->toContain('PersistentPHPRuntime.shared.reboot()');

    expect($runtimeSource)
        ->toContain('NativePHPApp.getOrGenerateAppKey()')
        ->not->toContain('SecItemCopyMatching(');
});
