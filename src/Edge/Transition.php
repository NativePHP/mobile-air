<?php

namespace Native\Mobile\Edge;

enum Transition: string
{
    case SlideFromRight = 'slide_from_right';
    case SlideFromLeft = 'slide_from_left';
    case SlideFromBottom = 'slide_from_bottom';
    case Fade = 'fade';
    case FadeFromBottom = 'fade_from_bottom';
    case ScaleFromCenter = 'scale_from_center';

    /**
     * iOS-style native push: the incoming screen slides in fully from the
     * right while the outgoing screen drifts partially left (~30%)
     * underneath, giving a layered depth cue rather than a flat slide.
     */
    case ParallaxPush = 'parallax_push';

    /**
     * Shared-element swap, the native analogue of the CSS View Transitions
     * API: the two screens cross-fade while every pair of elements sharing a
     * `ref` morphs between its outgoing and incoming frame.
     *
     * A cross-fade rather than a directional slide on purpose — a screen
     * sliding past an element that is simultaneously morphing across it reads
     * as two unrelated animations fighting each other. Holding the screens
     * still is what lets the morph carry the motion, which is the same reason
     * the web API cross-fades its root while named elements animate.
     *
     * Elements sharing a `ref` still morph best-effort under the
     * other transitions; this case is the one tuned for them.
     */
    case ViewTransition = 'view_transition';

    case None = 'none';
}
