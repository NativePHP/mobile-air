<?php

namespace Tests\Fixtures\Edge;

use Illuminate\Foundation\Http\FormRequest;

/** FormRequest fixture for validate(ValidationPostRequest::class) harvesting. */
class ValidationPostRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => 'required|min:3'];
    }

    public function messages(): array
    {
        return ['title.required' => 'The request fixture insists on a title.'];
    }
}
