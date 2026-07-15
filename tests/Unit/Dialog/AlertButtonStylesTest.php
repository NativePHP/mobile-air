<?php

use Native\Mobile\PendingAlert;
use Native\Mobile\Testing\FakeBridge;

/**
 * Alert button styles: buttons may be plain strings (unchanged wire format)
 * or ['label' => ..., 'style' => ...] arrays where style is one of
 * default / cancel / destructive.
 */

beforeEach(fn () => FakeBridge::enable());
afterEach(fn () => FakeBridge::disable());

it('sends plain string buttons unchanged', function () {
    (new PendingAlert('Title', 'Message', ['OK', 'Later']))->show();

    FakeBridge::current()->assertCalled('Dialog.Alert', fn ($params) => $params['buttons'] === ['OK', 'Later']);
});

it('sends styled buttons as label/style objects', function () {
    (new PendingAlert('Delete post?', 'This cannot be undone.', [
        ['label' => 'Cancel', 'style' => 'cancel'],
        ['label' => 'Delete', 'style' => 'destructive'],
    ]))->show();

    FakeBridge::current()->assertCalled('Dialog.Alert', fn ($params) => $params['buttons'] === [
        ['label' => 'Cancel', 'style' => 'cancel'],
        ['label' => 'Delete', 'style' => 'destructive'],
    ]);
});

it('fills in the default style when omitted', function () {
    (new PendingAlert('Title', 'Message', [['label' => 'OK']]))->show();

    FakeBridge::current()->assertCalled('Dialog.Alert', fn ($params) => $params['buttons'] === [
        ['label' => 'OK', 'style' => 'default'],
    ]);
});

it('mixes string and styled buttons', function () {
    (new PendingAlert('Title', 'Message', [
        'Cancel',
        ['label' => 'Delete', 'style' => 'destructive'],
    ]))->show();

    FakeBridge::current()->assertCalled('Dialog.Alert', fn ($params) => $params['buttons'] === [
        'Cancel',
        ['label' => 'Delete', 'style' => 'destructive'],
    ]);
});

it('rejects an unknown button style', function () {
    new PendingAlert('Title', 'Message', [['label' => 'OK', 'style' => 'danger']]);
})->throws(InvalidArgumentException::class);

it('rejects a button without a label', function () {
    new PendingAlert('Title', 'Message', [['style' => 'destructive']]);
})->throws(InvalidArgumentException::class);
