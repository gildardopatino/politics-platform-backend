<?php

namespace App\Http\Requests\Api\V1\Campaign;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignRequest extends FormRequest
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
            'titulo' => 'sometimes|string|max:255',
            'mensaje' => 'sometimes|string',
            'channel' => 'sometimes|in:sms,email,both',
            'filter_json' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
        ];
    }
}
