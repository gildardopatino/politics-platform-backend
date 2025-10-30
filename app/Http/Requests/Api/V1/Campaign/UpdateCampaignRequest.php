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
            'title' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
            'channel' => 'sometimes|in:whatsapp,email,both',
            'filter_json' => 'nullable|array',
            'filter_json.target' => 'nullable|in:all_users,meeting_attendees,custom_list',
            'filter_json.meeting_ids' => 'nullable|array',
            'filter_json.meeting_ids.*' => 'exists:meetings,id',
            'filter_json.custom_recipients' => 'nullable|array',
            'filter_json.custom_recipients.*.type' => 'required_with:filter_json.custom_recipients|in:email,phone',
            'filter_json.custom_recipients.*.value' => 'required_with:filter_json.custom_recipients|string',
            'scheduled_at' => 'nullable|date|after:now',
        ];
    }
}
