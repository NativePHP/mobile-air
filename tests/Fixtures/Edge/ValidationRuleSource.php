<?php

namespace Tests\Fixtures\Edge;

/** Injected into ValidationPostRequest::rules() to prove container method-injection works. */
class ValidationRuleSource
{
    public function titleRules(): string
    {
        return 'required|min:3';
    }
}
