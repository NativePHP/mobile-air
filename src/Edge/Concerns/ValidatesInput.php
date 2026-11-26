<?php

namespace Native\Mobile\Edge\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\FieldErrors;

/**
 * Opt-in Laravel validation for a NativeComponent screen.
 *
 *     class Signup extends NativeComponent
 *     {
 *         use ValidatesInput;
 *
 *         public string $email = '';
 *
 *         protected function rules(): array
 *         {
 *             return ['email' => 'required|email'];
 *         }
 *
 *         public function save(): void
 *         {
 *             $this->validate();
 *             // ...
 *         }
 *     }
 *
 * Validates the component's public properties with Laravel's Validator.
 * A ValidationException thrown while one of the component's handlers runs
 * (a tap, a native:model sync and its updated*() hook, an #[On] listener, a
 * child's `@event` binding, a #[Poll] method, onBackPressed()) keeps its
 * messages in this component's error bag and the screen re-renders, instead
 * of reaching the error overlay.
 *
 * Views receive the bag as `$errors`, so Blade's `@error('email')` works.
 * Elements can read the message for their bound field while they hydrate
 * through {@see FieldErrors::for()}.
 */
trait ValidatesInput
{
    protected ?MessageBag $errorBag = null;

    /** The last exception validate() / validateOnly() already recorded. */
    private ?ValidationException $recordedValidationException = null;

    /**
     * @param  array<string, mixed>|null  $rules  Defaults to rules() or $rules.
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(?array $rules = null, array $messages = [], array $attributes = []): array
    {
        $rules ??= $this->validationRules();

        try {
            $validated = $this->makeValidator($rules, $messages, $attributes)->validate();
        } catch (ValidationException $exception) {
            $this->errorBag = $exception->validator->errors();
            $this->recordedValidationException = $exception;

            throw $exception;
        }

        $this->resetValidation(array_keys($rules));

        return $validated;
    }

    /**
     * Validate one field against its rule, replacing only that field's
     * messages. Other fields keep theirs.
     *
     * @param  array<string, mixed>|null  $rules  Defaults to rules() or $rules.
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateOnly(string $field, ?array $rules = null, array $messages = [], array $attributes = []): array
    {
        $fieldRules = [];

        // A wildcard key such as `items.*.name` covers `items.0.name`, one
        // segment per `*`, as it does when Laravel expands the rules.
        foreach ($rules ?? $this->validationRules() as $key => $rule) {
            $pattern = '#^'.str_replace('\*', '[^.]*', preg_quote((string) $key, '#')).'\z#u';

            if (preg_match($pattern, $field) === 1) {
                $fieldRules[$field] = [
                    ...$fieldRules[$field] ?? [],
                    ...(is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : [$rule])),
                ];
            }
        }

        try {
            $validated = $this->makeValidator($fieldRules, $messages, $attributes)->validate();
        } catch (ValidationException $exception) {
            $bag = $this->getErrorBag();
            $bag->forget($field);

            foreach ($exception->validator->errors()->get($field) as $message) {
                $bag->add($field, $message);
            }

            $this->recordedValidationException = $exception;

            throw $exception;
        }

        $this->resetValidation($field);

        return $validated;
    }

    public function addError(string $field, string $message): void
    {
        $this->getErrorBag()->add($field, $message);
    }

    /**
     * Clear every message, or only those for the given field(s).
     *
     * @param  string|array<int, string>|null  $field
     */
    public function resetValidation(string|array|null $field = null): void
    {
        if ($field === null) {
            $this->errorBag = new MessageBag;

            return;
        }

        foreach ((array) $field as $key) {
            $this->getErrorBag()->forget($key);
        }
    }

    public function getErrorBag(): MessageBag
    {
        return $this->errorBag ??= new MessageBag;
    }

    /**
     * @internal Wraps every handler of this component: taps, native:model
     *           syncs, listeners, polls and the system back. It runs the
     *           handler itself rather than through the parent, which marks
     *           the ValidationExceptions it lets through.
     */
    public function __runHandler(\Closure $handler): mixed
    {
        try {
            return $handler();
        } catch (ValidationException $exception) {
            // Thrown by a handler of a component without the trait that ran
            // inside this one, such as a parent listener of an emit(). It
            // goes to the overlay, as it would if this child had no trait.
            if (static::validationLeftUnkept($exception)) {
                throw $exception;
            }

            // validate() and validateOnly() have already updated the bag;
            // a hand-thrown ValidationException::withMessages() has not.
            if ($exception !== $this->recordedValidationException) {
                $this->errorBag = $exception->validator->errors();
            }

            $this->recordedValidationException = null;

            return null;
        }
    }

    protected function view(string $name, array $data = []): Element
    {
        return $this->withFieldErrors(
            fn () => parent::view($name, $data + ['errors' => $this->viewErrors()])
        );
    }

    protected function partial(string $name, array $data = []): Element
    {
        return $this->withFieldErrors(
            fn () => parent::partial($name, $data + ['errors' => $this->viewErrors()])
        );
    }

    protected function fromView(View $view): Element
    {
        return $this->withFieldErrors(fn () => parent::fromView($this->withViewErrors($view)));
    }

    protected function fromViewPartial(View $view): Element
    {
        return $this->withFieldErrors(fn () => parent::fromViewPartial($this->withViewErrors($view)));
    }

    /** @return array<string, mixed> */
    protected function validationRules(): array
    {
        if (method_exists($this, 'rules')) {
            return $this->rules();
        }

        return property_exists($this, 'rules') ? $this->rules : [];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    protected function makeValidator(array $rules, array $messages, array $attributes): \Illuminate\Validation\Validator
    {
        if ($messages === [] && method_exists($this, 'messages')) {
            $messages = $this->messages();
        }

        if ($attributes === [] && method_exists($this, 'validationAttributes')) {
            $attributes = $this->validationAttributes();
        }

        return Validator::make($this->validationData(), $rules, $messages, $attributes);
    }

    /** @return array<string, mixed> */
    protected function validationData(): array
    {
        $data = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic()) {
                $data[$property->getName()] = $property->isInitialized($this)
                    ? $property->getValue($this)
                    : null;
            }
        }

        return $data;
    }

    /**
     * @param  callable(): Element  $render
     */
    private function withFieldErrors(callable $render): Element
    {
        return FieldErrors::using($this->getErrorBag(), $render);
    }

    private function viewErrors(): ViewErrorBag
    {
        return (new ViewErrorBag)->put('default', $this->getErrorBag());
    }

    private function withViewErrors(View $view): View
    {
        return array_key_exists('errors', $view->getData())
            ? $view
            : $view->with('errors', $this->viewErrors());
    }
}
