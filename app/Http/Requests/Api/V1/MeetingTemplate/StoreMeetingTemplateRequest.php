<?php

namespace App\Http\Requests\Api\V1\MeetingTemplate;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fields' => 'nullable|array',
            'fields.*.label' => 'required|string',
            'fields.*.type' => 'required|in:text,textarea,number,date,datetime,select,checkbox,radio',
            'fields.*.required' => 'boolean',
            'fields.*.options' => 'array',
            'fields.*.default_value' => 'nullable',
            'is_active' => 'boolean',
        ];
    }
}
