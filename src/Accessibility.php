<?php

namespace Native\Mobile;

use InvalidArgumentException;

class Accessibility
{
    /**
     * Move the screen reader to the element with this `ref`, such as the
     * first error after a failed submit. It moves once your handler's
     * changes are on screen, so the element is read with the error you just
     * gave it; call it last, since it waits only half a second for them.
     * Use it for moves the user caused; the screen reader decides
     * focus otherwise. Does nothing while no screen reader is running, and
     * leaves keyboard focus alone.
     */
    public function focus(string $ref): void
    {
        if ($ref === '') {
            throw new InvalidArgumentException('Accessibility::focus() needs the ref of an element on screen.');
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('Accessibility.Focus', json_encode(['ref' => $ref]));
        }
    }

    /**
     * Ask the screen reader to speak a message. A last resort: describe the
     * change on the element instead where one fits, with `a11y-state`,
     * `a11y-error`, `a11y-pane-title` or `a11y-live`. Android 16 deprecated
     * bare announcements, and screen readers may treat them differently.
     */
    public function announce(string $message, bool $assertive = false): void
    {
        if ($message === '') {
            return;
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('Accessibility.Announce', json_encode([
                'message' => $message,
                'assertive' => $assertive,
            ]));
        }
    }
}
