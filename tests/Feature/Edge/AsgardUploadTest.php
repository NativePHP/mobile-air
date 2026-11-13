<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\Asgard\Contracts\UploadTransport;
use Native\Mobile\Edge\Asgard\NullUploadTransport;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\Asgard\UploadRequest;
use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Events\Asgard\ServerActionFailed;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ServerMethodFailureScreen;
use Tests\Fixtures\Edge\ServerMethodScreen;

/**
 * uploadToServer() (protocol §10.6): a file goes to an #[Upload] method on
 * the server, and the answer is handled like a #[Server] call's. There is
 * no client body and no closure.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ServerMethods::flush();
    ServerRegion::flush();
    config(['app.debug' => false, 'nativephp.asgard.action_timeout' => 15, 'nativephp.asgard.upload_timeout' => 120]);
    Carbon::setTestNow(Carbon::now());

    $this->dir = sys_get_temp_dir().'/asgard-upload-test-'.getmypid();
    @mkdir($this->dir);
    $this->photo = $this->dir.'/IMG_0001.JPG';
    file_put_contents($this->photo, str_repeat('x', 2048));
});

afterEach(function () {
    ServerMethods::flush();
    ServerRegion::flush();
    Carbon::setTestNow();

    foreach (glob($this->dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

it('sends the file with the state as it is now', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);
    $screen->instance()->note = 'before upload';

    $id = $screen->instance()->uploadToServer('savePhoto', $this->photo, 'Sunset', 3);

    $request = $server->lastUpload();

    expect($id)->toBe($server->lastId())
        ->and(ServerMethods::isPending($id))->toBeTrue()
        ->and($server->sent())->toBe([])
        ->and($request)->toBeInstanceOf(UploadRequest::class)
        ->and($request->method)->toBe('savePhoto')
        ->and($request->args)->toBe(['Sunset', 3])
        ->and($request->state['note'])->toBe('before upload')
        ->and($request->path)->toBe(realpath($this->photo))
        ->and($request->filename)->toBe('IMG_0001.JPG')
        ->and($request->mime)->toBe('image/jpeg')
        ->and($request->size)->toBe(2048)
        ->and($request->toArray())->toHaveKeys(['component', 'method', 'args', 'state', 'platform', 'filename', 'mime', 'size'])
        ->and($request->toArray())->not->toHaveKey('path');

    $server->assertUploaded('savePhoto', fn (UploadRequest $r) => $r->size === 2048);
});

it('runs no client body, even when the component has a method of that name', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    $screen->instance()->uploadToServer('refreshChart', $this->photo);

    expect($screen->get('loading'))->toBeFalse()
        ->and($server->lastUpload()->method)->toBe('refreshChart');
});

it('applies the delta under compare-and-set, like a call', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    $screen->instance()->uploadToServer('savePhoto', $this->photo);

    // The user typed into the note while the file was going up.
    $screen->instance()->note = 'typed meanwhile';

    expect($server->resolve(delta: ['total' => '1 photo', 'note' => 'server note']))->toBeTrue();

    expect($screen->get('total'))->toBe('1 photo')
        ->and($screen->get('note'))->toBe('typed meanwhile')
        ->and(ServerMethods::pending())->toBe([]);
});

it('paints fragments the answer carries, like a call', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    $screen->instance()->uploadToServer('savePhoto', $this->photo);

    $screen->emitNative(ServerMethods::RESPONSE_EVENT, $server->payload(fragments: [
        'sales-summary' => ['fragment' => ['type' => 'text', 'key' => 'up', 'props' => ['text' => 'Uploaded at 12:00:00']]],
    ]));

    $screen->assertSee('Uploaded at 12:00:00')->assertDontSee('Loading sales…');
});

it('fires ServerActionFailed for a failed answer, with the server status', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class);

    $id = $screen->instance()->uploadToServer('savePhoto', $this->photo);
    $server->fail($id, 'unsupported_type', 415);

    $screen->wait();

    expect($screen->get('failures'))->toBe([[
        'action' => 'savePhoto', 'region' => null, 'reason' => 'server', 'status' => 415, 'requestId' => $id,
    ]])->and($screen->get('completions'))->toBe(0);
});

it('fires ServerActionFailed as network when nothing came back', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class);

    $screen->instance()->uploadToServer('savePhoto', $this->photo);
    $server->fail(error: 'Could not connect');

    $screen->wait();

    expect($screen->get('failures')[0]['reason'])->toBe('network');
});

it('times out on the upload timeout, not the action timeout', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class);

    $id = $screen->instance()->uploadToServer('savePhoto', $this->photo);

    $screen->wait(16);
    expect($screen->get('failures'))->toBe([])
        ->and(ServerMethods::isPending($id))->toBeTrue();

    $screen->wait(110);
    expect($screen->get('failures'))->toBe([[
        'action' => 'savePhoto', 'region' => null, 'reason' => 'timeout', 'status' => null, 'requestId' => $id,
    ]]);

    // Late: dropped.
    expect($server->resolve($id, ['total' => '£1']))->toBeFalse();
    expect($screen->get('total'))->toBe('—');
});

it('takes the upload timeout from config', function () {
    config(['nativephp.asgard.upload_timeout' => 5]);
    Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class);

    $screen->instance()->uploadToServer('savePhoto', $this->photo);
    $screen->wait(6);

    expect($screen->get('failures')[0]['reason'])->toBe('timeout');
});

it('refuses a file over upload_max before sending', function () {
    config(['nativephp.asgard.upload_max' => 1024]);
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class);

    expect($screen->instance()->uploadToServer('savePhoto', $this->photo))->toBeNull();

    $screen->wait();

    expect($server->uploads())->toBe([])
        ->and(ServerMethods::pending())->toBe([])
        ->and($screen->get('failures'))->toBe([[
            'action' => 'savePhoto', 'region' => null, 'reason' => 'too_large', 'status' => null, 'requestId' => '',
        ]]);
});

it('caps uploads at 20 MB by default', function () {
    expect(ServerMethods::uploadMax())->toBe(20 * 1024 * 1024);
});

it('refuses a path that is not a readable regular file', function (Closure $path) {
    Event::fake([ServerActionFailed::class]);
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    expect($screen->instance()->uploadToServer('savePhoto', $path($this)))->toBeNull()
        ->and($server->uploads())->toBe([]);

    Event::assertDispatched(ServerActionFailed::class, fn ($e) => $e->action === 'savePhoto' && $e->reason === 'unreadable' && $e->region === null);
})->with([
    'missing' => [fn ($test) => $test->dir.'/nope.jpg'],
    'empty' => [fn () => ''],
    'a directory' => [fn ($test) => $test->dir],
    'a symlink' => [function ($test) {
        symlink($test->photo, $test->dir.'/link.jpg');

        return $test->dir.'/link.jpg';
    }],
    'a stream wrapper' => [fn () => 'php://memory'],
    'a phar' => [fn ($test) => 'phar://'.$test->photo],
    'a URL' => [fn () => 'https://example.com/a.jpg'],
    'a NUL byte' => [fn ($test) => $test->photo."\0.png"],
    'unreadable' => [function ($test) {
        chmod($test->photo, 0);

        return $test->photo;
    }],
]);

it('resolves a path with dot segments to the real file', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    $screen->instance()->uploadToServer('savePhoto', $this->dir.'/../'.basename($this->dir).'/IMG_0001.JPG');

    expect($server->lastUpload()->path)->toBe(realpath($this->photo));
});

it('refuses a method name that cannot be one', function () {
    Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    $screen->instance()->uploadToServer('../savePhoto', $this->photo);
})->throws(InvalidArgumentException::class);

it('fails as network when the upload cannot start', function () {
    Event::fake([ServerActionFailed::class]);
    $server = Native::fakeServerTransport()->failSending('Offline.');
    $screen = Native::test(ServerMethodScreen::class);

    expect($screen->instance()->uploadToServer('savePhoto', $this->photo))->toBeNull()
        ->and(ServerMethods::pending())->toBe([]);

    Event::assertDispatched(ServerActionFailed::class, fn ($e) => $e->action === 'savePhoto' && $e->reason === 'network' && $e->message === 'Offline.');
});

it('fails as network with no upload transport bound', function () {
    Event::fake([ServerActionFailed::class]);
    app()->instance(UploadTransport::class, new NullUploadTransport);
    $screen = Native::test(ServerMethodScreen::class);

    expect($screen->instance()->uploadToServer('savePhoto', $this->photo))->toBeNull();

    Event::assertDispatched(ServerActionFailed::class, fn ($e) => $e->reason === 'network');
});

it('takes an answer that lands before upload() returns', function () {
    $transport = new class implements UploadTransport
    {
        public function upload(UploadRequest $request): string
        {
            ServerMethods::deliver(['id' => 'inline-1', 'ok' => true, 'delta' => ['total' => 'inline']]);

            return 'inline-1';
        }
    };
    app()->instance(UploadTransport::class, $transport);
    $screen = Native::test(ServerMethodScreen::class);

    $screen->instance()->uploadToServer('savePhoto', $this->photo);

    expect($screen->get('total'))->toBe('inline')
        ->and(ServerMethods::pending())->toBe([]);
});

it('refuses uploadToServer and serverUploadTimeout as tap targets', function (string $method) {
    $screen = Native::test(ServerMethodScreen::class);

    ComponentMethodInvoker::invoke($screen->instance(), $method, ['savePhoto', '/etc/passwd']);
})->with(['uploadToServer', 'serverUploadTimeout'])->throws(ComponentMethodNotFoundException::class);

it('guesses a mime type from the extension, as a hint only', function (string $name, string $mime) {
    expect(UploadRequest::guessMime('/a/'.$name))->toBe($mime);
})->with([
    ['photo.jpg', 'image/jpeg'],
    ['photo.JPEG', 'image/jpeg'],
    ['photo.png', 'image/png'],
    ['photo.heic', 'image/heic'],
    ['notes', 'application/octet-stream'],
]);
