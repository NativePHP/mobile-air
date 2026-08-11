<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Foundation\Http\FormRequest;

/** FormRequest fixture for validate(ValidationPostRequest::class) harvesting. */
class ValidationPostRequest extends FormRequest
{
    /** Method injection like a controller-bound request (finding 9). */
    public function rules(ValidationRuleSource $source): array
    {
        return ['title' => $source->titleRules()];
    }

    public function messages(): array
    {
        return ['title.required' => 'The request fixture insists on a title.'];
    }
}
