<?php

namespace Native\Mobile\Exceptions;

use RuntimeException;

/**
 * Thrown when the user backs out of one of `native:watch`'s prompts with
 * Ctrl+C. Laravel Prompts would otherwise exit the process, taking the whole
 * watcher with it — cancelling a screen you didn't mean to open should just
 * put you back in front of the footer.
 *
 * Raised via Prompt::cancelUsing() rather than returned, because the prompt
 * helpers declare non-nullable return types.
 */
class WatchPromptCancelled extends RuntimeException {}
