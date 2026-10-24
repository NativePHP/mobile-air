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
 * A ValidationException thrown while handling an interaction (a tap, a
 * native:model sync, an updated*() hook) keeps its messages in the error
 * bag and the screen re-renders, instead of reaching the error overlay.
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
        $rules ??= $this->validationRules();
        $fieldRules = array_key_exists($field, $rules) ? [$field => $rules[$field]] : [];

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
     * @internal Wraps the interaction entry point shared by the device
     *           runloop and the test harness.
     */
    public function __invokeInteraction(string $method, array $parameters): mixed
    {
        try {
            return parent::__invokeInteraction($method, $parameters);
        } catch (ValidationException $exception) {
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
