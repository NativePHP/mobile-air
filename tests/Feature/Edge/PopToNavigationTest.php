<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Events\Screen\ScreenMounted;
use Native\Mobile\Events\Screen\ScreenResumed;
use Native\Mobile\Events\Screen\ScreenUnmounted;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\FallbackProbeScreen;
use Tests\Fixtures\Edge\LogFeedScreen;
use Tests\Fixtures\Edge\PlainBackScreen;
use Tests\Fixtures\Edge\PopToCaptureScreen;
use Tests\Fixtures\Edge\PopToFlowFeedScreen;
use Tests\Fixtures\Edge\PopToFlowReplyScreen;
use Tests\Fixtures\Edge\PopToFlowThreadScreen;
use Tests\Fixtures\Edge\PopToSelfScreen;
use Tests\Fixtures\Edge\PopToTargetScreen;
use Tests\Fixtures\Edge\PopToTrace;
use Tests\Fixtures\Edge\ThreadScreen;

/**
 * Integration coverage for NativeComponent::popTo() against the REAL
 * NativeRouter loop (preloadStack + start), not the harness's flow
 * simulation: a popTo must unwind the stack to the EXISTING entry for
 * its URI and resume the SAME component instance — state, cursor, list
 * key intact — unmounting each popped child exactly once and never
 * re-mounting the target.
 */
beforeEach(function () {
    NativeRouter::clearRoutes();
    PopToTrace::resetAll();
    Native::fakeBridge();

    NativeRouter::register('/log', LogFeedScreen::class);
    NativeRouter::register('/log/thread', ThreadScreen::class);
    NativeRouter::register('/fallback', FallbackProbeScreen::class);

    foreach ([ScreenMounted::class, ScreenResumed::class, ScreenUnmounted::class] as $event) {
        Event::listen($event, [PopToTrace::class, 'recordEvent']);
    }
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

it('popTo rides the BACK intent carrying the target uri, while plain back stays uri-less', function () {
    // One harness each: popTo()/back() stop the runloop, so a second
    // call() on the same screen would be refused as "navigated away".
    Native::test(PopToCaptureScreen::class)->call('popLog');
    Native::test(PopToCaptureScreen::class)->call('backOnce');

    expect(PopToCaptureScreen::$popToIntent->type)->toBe(NavigationIntent::BACK)
        ->and(PopToCaptureScreen::$popToIntent->uri)->toBe('/log')
        ->and(PopToCaptureScreen::$backIntent->type)->toBe(NavigationIntent::BACK)
        ->and(PopToCaptureScreen::$backIntent->uri)->toBeNull();
});

it('pops multiple screens down to the existing target entry and resumes the same instance', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
        ['uri' => '/log/thread', 'params' => []],
    ]);

    // Prove instance identity: a mutation made while the feed is buried
    // must be visible in the resumed screen — a remount would reset it.
    LogFeedScreen::$instance->cursor = 99;

    $exit = $router->start(PopToTargetScreen::class, ['target' => '/log'], '/log/profile');

    // The feed's onResume terminal intent ends the runloop.
    expect($exit)->toBe('/feed-done');

    // Same instance resumed, exactly once; never re-mounted or re-built.
    expect(LogFeedScreen::$resumes)->toBe(1)
        ->and(LogFeedScreen::$mounts)->toBe(1)
        ->and(LogFeedScreen::$constructed)->toBe(1)
        ->and(LogFeedScreen::$resumeSnapshot)->toBe([
            'cursor' => 99,
            'listKey' => 'log-feed-v1',
            'uri' => '/log',
            'depth' => 1,
        ]);

    // Every screen above the target unmounted exactly once.
    expect(PopToTargetScreen::$unmounts)->toBe(1)
        ->and(ThreadScreen::$unmounts)->toBe(1)
        ->and(LogFeedScreen::$unmounts)->toBe(1); // the terminal EXIT_WEB

    // Order: unwind finished BEFORE the feed resumed.
    $sequence = PopToTrace::$sequence;
    expect($sequence)->toContain('pop-to-target.unmount')
        ->and($sequence)->toContain('thread.unmount');
    expect(array_search('thread.unmount', $sequence))->toBeLessThan(array_search('feed.resume', $sequence));

    // Lifecycle events: the target resumed with its URI; each popped
    // child unmounted once. No second mount for the resumed target.
    expect(PopToTrace::$events)->toContain('ScreenResumed|'.LogFeedScreen::class.'|/log')
        ->and(PopToTrace::$events)->toContain('ScreenUnmounted|'.PopToTargetScreen::class.'|/log/profile')
        ->and(PopToTrace::$events)->toContain('ScreenUnmounted|'.ThreadScreen::class.'|/log/thread');
    $resumedForFeed = count(array_filter(
        PopToTrace::$events,
        fn (string $e) => str_starts_with($e, 'ScreenResumed|'.LogFeedScreen::class)
    ));
    $mountedForFeed = count(array_filter(
        PopToTrace::$events,
        fn (string $e) => str_starts_with($e, 'ScreenMounted|'.LogFeedScreen::class)
    ));
    expect($resumedForFeed)->toBe(1)
        ->and($mountedForFeed)->toBe(0);
});

it('popTo to the uri already on top is a no-op: no unmount, no resume', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
    ]);

    $exit = $router->start(PopToSelfScreen::class, [], '/log/thread');

    expect($exit)->toBe('/feed-done');

    // The no-op left the tapped screen live: its Poll finisher ran
    // (only possible while still the top), and only THEN did it leave.
    $sequence = PopToTrace::$sequence;
    expect($sequence)->toContain('self.poll-back')
        ->and($sequence)->toContain('self.unmount');
    expect(array_search('self.poll-back', $sequence))->toBeLessThan(array_search('feed.resume', $sequence));

    // The feed below was resumed exactly once — after the finisher —
    // and never re-mounted.
    expect(LogFeedScreen::$resumes)->toBe(1)
        ->and(LogFeedScreen::$mounts)->toBe(1)
        ->and(LogFeedScreen::$resumeSnapshot['cursor'])->toBe(42);
});

it('popTo to a missing stack entry falls back to a fresh mount of the registered route', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
    ]);

    $exit = $router->start(PopToTargetScreen::class, ['target' => '/fallback'], '/log/thread');

    expect($exit)->toBe('/feed-done');

    // The fallback was mounted fresh, once, with its own URI — and the
    // feed underneath resumed intact after it left.
    expect(FallbackProbeScreen::$constructed)->toBe(1)
        ->and(FallbackProbeScreen::$mounts)->toBe(1)
        ->and(FallbackProbeScreen::$mountUri)->toBe('/fallback')
        ->and(PopToTargetScreen::$unmounts)->toBe(1)
        ->and(LogFeedScreen::$resumes)->toBe(1)
        ->and(LogFeedScreen::$mounts)->toBe(1)
        ->and(LogFeedScreen::$resumeSnapshot['cursor'])->toBe(42);

    $sequence = PopToTrace::$sequence;
    expect(array_search('pop-to-target.unmount', $sequence))->toBeLessThan(array_search('fallback.mount:/fallback', $sequence))
        ->and(array_search('fallback.mount:/fallback', $sequence))->toBeLessThan(array_search('feed.resume', $sequence));
});

it('popTo to an unregistered uri falls back to the web exit, like replace', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
    ]);

    $exit = $router->start(PopToTargetScreen::class, ['target' => '/nowhere'], '/log/thread');

    expect($exit)->toBe('/nowhere')
        ->and(LogFeedScreen::$resumes)->toBe(0)
        // Existing EXIT_WEB semantics: the stack is dropped without
        // unmounting the buried screens — same as REPLACE's exit path.
        ->and(LogFeedScreen::$unmounts)->toBe(0)
        ->and(PopToTargetScreen::$unmounts)->toBe(1);
});

it('plain back still pops exactly one screen even when a deeper uri matches nothing', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
        ['uri' => '/log/thread', 'params' => []],
    ]);

    $exit = $router->start(PlainBackScreen::class, [], '/log/profile');

    expect($exit)->toBe('/feed-done');

    // Single back: the thread (not the feed) became top again — its
    // explicit resume finisher ran before the feed ever resumed.
    $sequence = PopToTrace::$sequence;
    expect(array_search('plain-back.unmount', $sequence))->toBeLessThan(array_search('thread.resume', $sequence))
        ->and(array_search('thread.resume', $sequence))->toBeLessThan(array_search('feed.resume', $sequence));

    expect(ThreadScreen::$unmounts)->toBe(1)
        ->and(LogFeedScreen::$resumes)->toBe(1)
        ->and(LogFeedScreen::$mounts)->toBe(1);
});

// ── Flow stack: the harness follows a popTo like the router ──

it('followNavigation returns from two screens deep to the existing ancestor harness', function () {
    NativeRouter::register('/log', PopToFlowFeedScreen::class);
    NativeRouter::register('/log/thread', PopToFlowThreadScreen::class);
    NativeRouter::register('/log/thread/reply', PopToFlowReplyScreen::class);

    $feed = Native::visit('/log');
    $feed->call('scrollDeep');
    $instance = spl_object_id($feed->instance());
    $listKey = $feed->get('listKey');

    $thread = $feed->tap('Open thread')->assertNavigatedTo('/log/thread')->follow();
    $reply = $thread->tap('Open reply')->assertNavigatedTo('/log/thread/reply')->follow();

    $returned = $reply->tap('Return to log')->assertPoppedTo('/log')->follow();

    // The SAME live component — never remounted, its state intact.
    expect(spl_object_id($returned->instance()))->toBe($instance)
        ->and($returned->get('cursor'))->toBe(231)
        ->and($returned->get('listKey'))->toBe($listKey)
        ->and(PopToFlowFeedScreen::$mounts)->toBe(1)
        ->and(PopToFlowFeedScreen::$resumes)->toBe(1)
        ->and(PopToFlowThreadScreen::$unmounts)->toBe(1)
        ->and(PopToFlowReplyScreen::$unmounts)->toBe(1);
});

it('followNavigation returns from one screen deep to the existing ancestor harness', function () {
    NativeRouter::register('/log', PopToFlowFeedScreen::class);
    NativeRouter::register('/log/thread', PopToFlowThreadScreen::class);

    $feed = Native::visit('/log');
    $instance = spl_object_id($feed->instance());

    $thread = $feed->tap('Open thread')->assertNavigatedTo('/log/thread')->follow();
    $returned = $thread->tap('Return to log')->assertPoppedTo('/log')->follow();

    expect(spl_object_id($returned->instance()))->toBe($instance)
        ->and(PopToFlowFeedScreen::$mounts)->toBe(1)
        ->and(PopToFlowFeedScreen::$resumes)->toBe(1)
        ->and(PopToFlowThreadScreen::$unmounts)->toBe(1)
        ->and($returned->get('cursor'))->toBe(0);
});

it('followNavigation falls back to a fresh mount when the popTo target is not on the flow', function () {
    NativeRouter::register('/log', PopToFlowFeedScreen::class);
    NativeRouter::register('/log/thread', PopToFlowThreadScreen::class);

    $feed = Native::visit('/log');
    $instance = spl_object_id($feed->instance());

    $thread = $feed->tap('Open thread')->follow();
    $returned = $thread->call('popToMissing')->assertPoppedTo('/fallback')->follow();

    // Fresh mount of the registered fallback — the buried feed stays
    // buried, never resumed (the documented replace fallback).
    expect(get_class($returned->instance()))->toBe(FallbackProbeScreen::class)
        ->and(spl_object_id($returned->instance()))->not->toBe($instance)
        ->and(PopToFlowFeedScreen::$resumes)->toBe(0)
        ->and(PopToFlowThreadScreen::$unmounts)->toBe(1);
});
