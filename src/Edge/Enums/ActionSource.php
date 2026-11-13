<?php

namespace Native\Mobile\Edge\Enums;

/**
 * Why the framework is invoking a developer's action method.
 *
 * Passed to `NativeComponent::callAction()` (through `runAction()`) so anything wrapping the call can
 * tell a user's tap apart from a background poll tick. That distinction is the
 * point: a hook that sees only "some method ran" cannot rate-limit taps, skip
 * polls, or treat a return-value-carrying kind differently from a
 * fire-and-forget one.
 *
 * Values are stable strings so they can be logged or put on a wire. The three
 * dispatch kinds keep the spelling `NativeComponent::dispatch()` already uses
 * for its callback kinds, so the two vocabularies line up.
 */
enum ActionSource: string
{
    /**
     * A native/system event (`#[On]` / `#[OnNative]`) delivered from the
     * platform, e.g. a push notification or a lifecycle event.
     */
    case NativeEvent = 'native_event';

    /** A `#[Poll]` method fired by the runloop's idle timer. */
    case Poll = 'poll';

    /** An ancestor's `#[On]` listener for a component `emit()`. */
    case ComponentEvent = 'component_event';

    /**
     * A tag-level `@event-name="method(...)"` binding — the emitting child's
     * mounting tag invoking a method on its direct parent.
     */
    case ParentBinding = 'parent_binding';

    /**
     * A UI interaction: tap, long press, text change, toggle, slider, radio,
     * select, tab change, sheet dismiss. The general `dispatch()` path.
     */
    case Interaction = 'interaction';

    /** A search bar's query callback. Its `array` return is captured. */
    case SearchQuery = 'search_query';

    /** A virtual window's visible-range callback. */
    case VirtualWindow = 'virtual_window';

    /** A text input's `@selectionChange` callback. */
    case TextSelection = 'text_selection';

    /** A gesture area's `@dragEnd` callback, given the final pan translation. */
    case DragEnd = 'drag_end';

    /**
     * A tap inside a server-rendered fragment whose callback targets the
     * client (docs/ASGARD-PROTOCOL.md §10). A server chose the name, so on
     * top of the guard the method must carry `#[NativeAction]` and take
     * only JSON-shaped parameters, and its arguments are passed as they
     * came, with no container injection or model / enum binding.
     */
    case FragmentClient = 'fragment_client';

    /**
     * Whether the method name must pass ComponentMethodInvoker's callable
     * guard: public, not static, not a lifecycle hook, not declared on
     * NativeComponent itself.
     *
     * The rule is who chose the name. A template expression (`@tap`,
     * `@event` on a child's tag, a search or window callback) or the device
     * (a native event matched to a listener) is guarded, so a name that
     * arrives from outside the class can only reach the methods a developer
     * deliberately made public. A name the developer chose by writing an
     * attribute on the method itself (#[Poll], a component #[On]) is not, so
     * those methods may be protected.
     *
     * NativeEvent stays guarded although #[On] names it, matching what the
     * framework already did for native listeners before this enum existed.
     *
     * A new case has to be added to this match, or it will not compile.
     * Choose false only when nothing outside the class can pick the name.
     */
    public function guarded(): bool
    {
        return match ($this) {
            self::Interaction,
            self::SearchQuery,
            self::VirtualWindow,
            self::TextSelection,
            self::DragEnd,
            self::FragmentClient,
            self::ParentBinding,
            self::NativeEvent => true,

            self::Poll,
            self::ComponentEvent => false,
        };
    }

    /**
     * Whether the name came from a server-rendered fragment, so the method
     * must also pass ComponentMethodInvoker::assertFragmentCallable().
     */
    public function fromFragment(): bool
    {
        return $this === self::FragmentClient;
    }
}
