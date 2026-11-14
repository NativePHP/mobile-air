<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Skips re-rendering a nested child component whose props and state haven't changed since its
 * last render, reusing the elements it built then. The parent re-runs its own Blade view on every
 * frame, so without this each child in a long list rebuilds its whole subtree on every tap,
 * keystroke or event, even when only one row changed.
 *
 *     #[Memo]
 *     class TaskRow extends NativeComponent
 *     {
 *         public array $task = [];
 *
 *         public function render(): View
 *         {
 *             return view('native.task-row');
 *         }
 *     }
 *
 * A child re-renders when any of its public properties (its props and its own state), its
 * `@event` bindings, or any nested child's public properties change. Opt in only when render()
 * reads nothing else: a child that shows the time, reads the session or config, or calls out to
 * the database in its view would keep showing what it showed last. Streaming renders and a
 * child's first render are never memoised.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Memo {}
