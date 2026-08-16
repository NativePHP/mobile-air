<?php

namespace Native\Mobile\Edge;

/** A component event whose destination can be fluently narrowed. */
class ComponentEvent
{
    protected bool $self = false;

    protected string|NativeComponent|null $component = null;

    public function __construct(
        protected string $name,
        protected array $params,
    ) {
        if (isset($params['self'])) {
            $this->self();
            unset($this->params['self']);
        }

        if (isset($params['component'])) {
            $this->component($params['component']);
            unset($this->params['component']);
        }

        if (isset($params['to'])) {
            $this->component($params['to']);
            unset($this->params['to']);
        }
    }

    public function self(): static
    {
        $this->self = true;

        return $this;
    }

    public function component(string|NativeComponent|null $component): static
    {
        $this->component = $component;

        return $this;
    }

    public function to(string|NativeComponent|null $component = null): static
    {
        return $this->component($component);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function params(): array
    {
        return $this->params;
    }

    public function isSelfOnly(): bool
    {
        return $this->self;
    }

    public function target(): string|NativeComponent|null
    {
        return $this->component;
    }

    public function serialize(): array
    {
        $event = [
            'name' => $this->name,
            'params' => $this->params,
        ];

        if ($this->self) {
            $event['self'] = true;
        }

        if ($this->component !== null) {
            $event['component'] = $this->component instanceof NativeComponent
                ? $this->component::class
                : $this->component;
        }

        return $event;
    }
}
