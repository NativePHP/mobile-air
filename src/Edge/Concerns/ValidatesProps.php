<?php

namespace Native\Mobile\Edge\Concerns;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Native\Mobile\Attributes\Validate;

/**
 * Laravel-style validation for EDGE components, identical on every
 * render target (device run loop and web update cycle both route event
 * dispatch through runGuarded()).
 *
 * Rule sources, merged in order (later wins per key):
 *   1. #[Validate('...')] attributes on public props (EAGER — they also
 *      auto-run on native:model sync, see NativeComponent::__syncProperty)
 *   2. the component's rules() method (on-demand only)
 *   3. the $rules argument passed to validate() (validates ONLY those)
 *
 * Failure semantics: validate()/validateOnly() update the error bag and
 * throw Laravel's own ValidationException. The dispatch-cycle guard
 * (runGuarded) swallows it — the handler aborts at the validate() line,
 * component state stays, and the frame renders with $errors set.
 * Author-thrown ValidationException::withMessages(...) is also honored:
 * the guard folds its messages into the bag per key.
 *
 * The bag is INTERNAL state, not a public prop — it never rides the
 * generic snapshot path. The web target carries it explicitly in the
 * sealed snapshot; on device the persistent instance simply keeps it.
 */
trait ValidatesProps
{
    protected ?MessageBag $nativeErrorBag = null;

    /**
     * Exceptions whose messages have already been recorded in SOME
     * component's bag. Keyed on the exception INSTANCE (not an instance
     * flag) because guards nest across components: a child's guard can
     * catch an exception a parent's validate() already recorded (emit()
     * listeners, nested dispatch), and an instance flag on the catcher
     * would wrongly fold the parent's errors into the child's bag — or,
     * left stale, wrongly swallow a fresh author-thrown exception.
     *
     * @var \WeakMap<ValidationException, true>
     */
    private static ?\WeakMap $recordedValidationExceptions = null;

    private static function recordedExceptions(): \WeakMap
    {
        return self::$recordedValidationExceptions ??= new \WeakMap;
    }

    // ── Bag access ──────────────────────────────────

    public function getErrorBag(): MessageBag
    {
        return $this->nativeErrorBag ??= new MessageBag;
    }

    /** Replace the whole bag — used by targets that rehydrate state (web snapshot). */
    public function setErrorBag(array $messages): void
    {
        $this->nativeErrorBag = new MessageBag($messages);
    }

    public function addError(string $key, string $message): void
    {
        $this->getErrorBag()->add($key, $message);
    }

    /** Clear one key's errors, or the whole bag when no key is given. */
    public function resetValidation(?string $key = null): void
    {
        if ($key === null) {
            $this->nativeErrorBag = new MessageBag;

            return;
        }

        $this->forgetErrors([$key]);
    }

    /** The bag as Blade sees it: a ViewErrorBag so @error / $errors->first() work. */
    protected function errorBagForViews(): ViewErrorBag
    {
        return (new ViewErrorBag)->put('default', $this->getErrorBag());
    }

    // ── Validation ──────────────────────────────────

    /**
     * Validate the component's public properties. With no arguments,
     * every declared rule runs (#[Validate] attributes + rules()).
     * Passing $rules validates only those rules. Returns the validated
     * data; throws ValidationException on failure (after recording the
     * errors — the dispatch guard turns the throw into "abort handler,
     * render with errors").
     *
     * $rules also accepts a FormRequest class-string — its rules(),
     * messages() and attributes() are harvested so HTTP controllers and
     * screens can share one definition:
     *
     *     $this->validate(StorePostRequest::class);
     *
     * Harvesting only: the request is instantiated bare (no HTTP
     * context), so authorize() is NOT called and rules() must not read
     * $this->input()/route()/user(). Something Livewire doesn't offer.
     */
    public function validate(array|string|null $rules = null, array $messages = [], array $attributes = []): array
    {
        if (is_string($rules)) {
            [$rules, $messages, $attributes] = $this->harvestFormRequest($rules, $messages, $attributes);
        }

        $rules ??= $this->allValidationRules();

        if ($rules === []) {
            return [];
        }

        $validator = $this->makePropValidator($rules, $messages, $attributes);

        if ($validator->fails()) {
            $this->nativeErrorBag = new MessageBag($validator->errors()->messages());

            $exception = new ValidationException($validator);
            self::recordedExceptions()[$exception] = true;

            throw $exception;
        }

        // A full pass supersedes everything previously recorded,
        // including addError() entries — same as Livewire.
        $this->nativeErrorBag = new MessageBag;

        return $validator->validated();
    }

    /**
     * Validate a single property (wildcard-aware: validateOnly('tags.0')
     * matches a 'tags.*' rule). Only THAT property's errors are replaced
     * or cleared — a wildcard rule may validate sibling entries as a
     * side effect of running, but their bag entries are never touched
     * (editing tags.1 must not conjure an error onto untouched tags.0).
     * Throws on failure like validate().
     *
     * $rules narrows the rule source (defaults to every declared rule);
     * the eager sync path passes attributeValidationRules() so
     * rules()-tier rules never run per keystroke — even when the two
     * tiers declare the same key.
     */
    public function validateOnly(string $prop, array $messages = [], array $attributes = [], ?array $rules = null): array
    {
        $matched = [];

        foreach ($rules ?? $this->allValidationRules() as $key => $rule) {
            if ($key === $prop || $this->wildcardCovers($key, $prop)) {
                $matched[$key] = $rule;
            }
        }

        if ($matched === []) {
            return [];
        }

        $covers = fn (string $key): bool => $key === $prop || $this->wildcardCovers($prop, $key);

        $validator = $this->makePropValidator($matched, $messages, $attributes);

        if ($validator->fails()) {
            // A wildcard rule validates sibling entries as a side effect;
            // only failures on the TARGET prop count here. Sibling-only
            // failures mean the edited prop is fine: no throw, no bag
            // changes for entries the author didn't touch.
            $covered = array_filter(
                $validator->errors()->messages(),
                $covers,
                ARRAY_FILTER_USE_KEY,
            );

            if ($covered !== []) {
                $this->forgetErrors([$prop]);
                foreach ($covered as $key => $keyMessages) {
                    foreach ($keyMessages as $message) {
                        $this->getErrorBag()->add($key, $message);
                    }
                }

                $exception = new ValidationException($validator);
                self::recordedExceptions()[$exception] = true;

                throw $exception;
            }
        }

        $this->forgetErrors([$prop]);

        return $validator->fails() ? [] : $validator->validated();
    }

    // ── Dispatch guard ──────────────────────────────

    /**
     * Run an event handler with validation-abort semantics. Every event
     * entry point (device bridge events, web update dispatch) routes
     * through here so a ValidationException means the same thing
     * everywhere: stop the handler, keep state, render with errors.
     */
    protected function runGuarded(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            if (! isset(self::recordedExceptions()[$e])) {
                // Author-thrown (ValidationException::withMessages or a
                // bare Validator) — fold into THIS component's bag, and
                // mark the exception so an enclosing guard on another
                // component doesn't fold it into its bag too.
                self::recordedExceptions()[$e] = true;

                foreach ($e->errors() as $key => $messages) {
                    $this->forgetErrors([$key]);
                    foreach ($messages as $message) {
                        $this->getErrorBag()->add($key, $message);
                    }
                }
            }

            return null;
        }
    }

    /**
     * Harvest rules/messages/attributes from a FormRequest class so an
     * HTTP controller and a screen can share one definition. Livewire
     * deliberately rejected full FormRequest integration (authorize()
     * and input sources are HTTP-coupled); this is the uncontroversial
     * subset — the request is never "handled", just read. Caller-passed
     * messages/attributes win over the request's own.
     *
     * Scope caveat: the DATA under validation is the component's public
     * props, not an HTTP payload. Rules for request-only fields behave
     * as they would for an absent field (implicit rules like `required`
     * fail; non-implicit ones pass) and never appear in validated() —
     * share a FormRequest only when its fields map onto props.
     *
     * @return array{0: array, 1: array, 2: array} [rules, messages, attributes]
     */
    protected function harvestFormRequest(string $class, array $messages, array $attributes): array
    {
        if (! is_subclass_of($class, FormRequest::class)) {
            throw new \InvalidArgumentException(
                "validate({$class}) expects a FormRequest class-string or an array of rules."
            );
        }

        // Bare instantiation on purpose: resolving a FormRequest THROUGH
        // the container triggers Laravel's ValidatesWhenResolved hook,
        // which would attempt full HTTP-style validation with no request.
        // rules() itself goes through the container so method-injected
        // dependencies (rules(SomeService $svc)) resolve like they do in
        // a controller-bound request.
        $request = new $class;

        if (! method_exists($request, 'rules')) {
            throw new \InvalidArgumentException("{$class} declares no rules() method.");
        }

        return [
            (array) app()->call([$request, 'rules']),
            array_merge((array) $request->messages(), $messages),
            array_merge((array) $request->attributes(), $attributes),
        ];
    }

    // ── Rule sources ────────────────────────────────

    /** #[Validate] attribute rules merged under rules() (method wins per key). */
    protected function allValidationRules(): array
    {
        $methodRules = method_exists($this, 'rules') ? (array) $this->rules() : [];

        return array_merge($this->attributeValidationRules(), $methodRules);
    }

    /** Rules declared via #[Validate] on public props, cached per class. */
    protected function attributeValidationRules(): array
    {
        static $cache = [];

        return $cache[static::class] ??= (function () {
            $rules = [];

            foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }

                foreach ($prop->getAttributes(Validate::class) as $attribute) {
                    $rules[$prop->getName()] = $attribute->newInstance()->rule;
                }
            }

            return $rules;
        })();
    }

    /** Whether a #[Validate]-declared (eager) rule exists for this prop. */
    protected function hasEagerValidationRule(string $prop): bool
    {
        $rules = $this->attributeValidationRules();

        if (isset($rules[$prop])) {
            return true;
        }

        foreach (array_keys($rules) as $key) {
            if ($this->wildcardCovers($key, $prop)) {
                return true;
            }
        }

        return false;
    }

    // ── Internals ───────────────────────────────────

    protected function makePropValidator(array $rules, array $messages, array $attributes): ValidatorContract
    {
        return Validator::make(
            $this->getPublicProperties(),
            $rules,
            array_merge(method_exists($this, 'messages') ? (array) $this->messages() : [], $messages),
            array_merge(method_exists($this, 'validationAttributes') ? (array) $this->validationAttributes() : [], $attributes),
        );
    }

    /** Drop bag entries for the given rule keys (wildcards clear their matches). */
    private function forgetErrors(array $ruleKeys): void
    {
        $bag = $this->getErrorBag();
        $kept = [];

        foreach ($bag->messages() as $key => $messages) {
            $covered = false;
            foreach ($ruleKeys as $ruleKey) {
                if ($key === $ruleKey || $this->wildcardCovers($ruleKey, $key)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $kept[$key] = $messages;
            }
        }

        $this->nativeErrorBag = new MessageBag($kept);
    }

    /** Whether a wildcard rule key ('tags.*') covers a concrete key ('tags.0'). */
    private function wildcardCovers(string $ruleKey, string $concrete): bool
    {
        if (! str_contains($ruleKey, '*')) {
            return false;
        }

        $pattern = '/^'.str_replace('\*', '[^.]+', preg_quote($ruleKey, '/')).'$/';

        return preg_match($pattern, $concrete) === 1;
    }
}
