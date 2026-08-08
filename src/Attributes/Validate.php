<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Declares validation rules for a public component property.
 *
 *     #[Validate('required|email')]
 *     public string $email = '';
 *
 *     #[Validate(['required', 'min:8'])]
 *     public string $password = '';
 *
 * Attribute rules are EAGER: they run automatically whenever the
 * property syncs through `native:model` (validateOnly inside
 * __syncProperty), and they're included in every $this->validate()
 * call. Rules that should only run on demand belong in the component's
 * rules() method instead — that's the opt-out lever, mirroring the
 * attribute-vs-method split Livewire settled on.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Validate
{
    public function __construct(
        public string|array $rule,
    ) {}
}
