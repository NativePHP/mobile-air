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
    case None = 'none';
}