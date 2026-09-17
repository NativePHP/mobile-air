<?php

namespace Native\Mobile\Edge;

enum Transition: string
{
    case SlideFromRight = 'slide_from_right';
    case SlideFromLeft = 'slide_from_left';
    case SlideFromBottom = 'slide_from_bottom';

    /**
     * Modal dismissal: the OUTGOING screen slides down off the bottom edge,
     * revealing the incoming screen already sitting beneath it — the inverse
     * of SlideFromBottom. Pair them for sheet-style present/dismiss.
     */
    case SlideToBottom = 'slide_to_bottom';
    case Fade = 'fade';
    case FadeFromBottom = 'fade_from_bottom';
    case ScaleFromCenter = 'scale_from_center';

    /**
     * iOS-style native push: the incoming screen slides in fully from the
     * right while the outgoing screen drifts partially left (~30%)
     * underneath, giving a layered depth cue rather than a flat slide.
     */
    case ParallaxPush = 'parallax_push';

    case None = 'none';
}
