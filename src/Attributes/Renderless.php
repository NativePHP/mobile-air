<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Prevent the render cycle that would normally follow this component action.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Renderless {}
