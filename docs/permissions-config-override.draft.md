## iOS Permission Strings

Plugins declare their own iOS `Info.plist` usage descriptions through their manifests (see
[Permissions & Dependencies](../plugins/permissions-dependencies)). When you install multiple plugins that
claim the same key — for example, `mobile-camera` and `mobile-scanner` both setting
`NSCameraUsageDescription` — the merged result is whichever plugin loaded last, which isn't always what you
want to show App Store reviewers.

The top-level `permissions` array in `config/nativephp.php` lets you override those merged values. Anything
you set here is applied **after** all plugin manifests are merged, so it always wins:

```php
'permissions' => [
    'NSCameraUsageDescription' => 'Used to take a profile photo.',
    'NSMicrophoneUsageDescription' => 'Used to record audio with your videos.',
    'NSPhotoLibraryUsageDescription' => 'Used to select photos for your post.',
],
```

Use this when you want a single, explicit usage string for App Store review, or when you need to tailor a
plugin-provided description to match how your app actually uses the permission.

<aside>

This block is iOS-only. Android doesn't declare permission rationale in its manifest — rationale strings
are shown at runtime by app code, so there's no equivalent override.

</aside>
