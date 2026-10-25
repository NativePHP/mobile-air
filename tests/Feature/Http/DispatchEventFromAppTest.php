<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

it('dispatches a core device event from a positional payload', function () {
    Event::fake([PhotoTaken::class]);

    // 3.x splats the payload positionally: `new $event(...$payload)`.
    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['/tmp/captured.png', 'image/png', 'capture-1'],
    ])->assertOk()->assertJson(['success' => true]);

    Event::assertDispatched(PhotoTaken::class, function (PhotoTaken $event) {
        return $event->path === '/tmp/captured.png'
            && $event->mimeType === 'image/png'
            && $event->id === 'capture-1';
    });
});

it('dispatches a core device event from an associative payload', function () {
    Event::fake([PhotoTaken::class]);

    // `...$payload` unpacks string keys as named arguments (PHP 8.1+), which is
    // how the native side names the event's fields.
    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['path' => '/tmp/captured.jpg', 'id' => 'capture-2'],
    ])->assertOk()->assertJson(['success' => true]);

    Event::assertDispatched(PhotoTaken::class, function (PhotoTaken $event) {
        return $event->path === '/tmp/captured.jpg'
            && $event->mimeType === 'image/jpeg'
            && $event->id === 'capture-2';
    });
});

it('reports failure for an unknown event class', function () {
    $this->postJson('/_native/api/events', [
        'event' => 'App\\Events\\DoesNotExist',
        'payload' => ['foo' => 'bar'],
    ])->assertOk()->assertJson(['success' => false]);
});

it('refuses a class that does not implement DispatchableFromNative', function () {
    // The endpoint constructs the named class. A page could otherwise name any
    // autoloadable class and hand it constructor arguments — an object-injection
    // gadget. SplFileObject in write mode would create/truncate a file; the gate
    // must stop it before the constructor runs.
    $target = sys_get_temp_dir().'/native-events-gadget-'.uniqid().'.txt';
    file_put_contents($target, 'untouched');

    $this->postJson('/_native/api/events', [
        'event' => SplFileObject::class,
        'payload' => [$target, 'w'],
    ])->assertOk()->assertJson(['success' => false]);

    // The constructor never ran, so the file is intact.
    expect(file_get_contents($target))->toBe('untouched');

    @unlink($target);
});

it('refuses a non-string event parameter', function () {
    $this->postJson('/_native/api/events', [
        'event' => ['Not', 'A', 'Class'],
        'payload' => [],
    ])->assertOk()->assertJson(['success' => false]);
});

it('marks every core event class as dispatchable from native', function () {
    $dir = dirname(__DIR__, 3).'/src/Events';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    $missing = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace([$dir, '/', '.php'], ['', '\\', ''], $file->getPathname());

        // Skip the marker/contract interfaces themselves.
        if (str_contains($relative, '\\Contracts\\')) {
            continue;
        }

        $class = 'Native\\Mobile\\Events'.$relative;

        if (! is_subclass_of($class, DispatchableFromNative::class)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([]);
});
