<?php

namespace App\Http\Requests\Api\V1\Campaign;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class StoreCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
    
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // If scheduled_at is provided, ensure it's treated as Colombia time
        if ($this->has('scheduled_at') && $this->scheduled_at) {
            $scheduledDate = Carbon::parse($this->scheduled_at, config('app.timezone'));
            $this->merge([
                'scheduled_at_for_validation' => $scheduledDate->setTimezone('UTC')->toDateTimeString()
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'channel' => 'required|in:whatsapp,email,both',
            'filter_json' => 'nullable|array',
            'filter_json.target' => 'nullable|in:all_users,meeting_attendees,custom_list',
            'filter_json.meeting_ids' => 'nullable|array',
            'filter_json.meeting_ids.*' => 'exists:meetings,id',
            'filter_json.custom_recipients' => 'nullable|array',
            'filter_json.custom_recipients.*.type' => 'required_with:filter_json.custom_recipients|in:email,phone',
            'filter_json.custom_recipients.*.value' => 'required_with:filter_json.custom_recipients|string',
            'scheduled_at' => 'nullable|date',
        ];
    }
    
    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('scheduled_at') && $this->scheduled_at) {
                // Parse scheduled date in Colombia timezone
                $scheduledDate = Carbon::parse($this->scheduled_at, config('app.timezone'));
                $now = Carbon::now(config('app.timezone'));
                
                // Validate that scheduled date is in the future
                if ($scheduledDate->lte($now)) {
                    $validator->errors()->add('scheduled_at', 'La fecha programada debe ser posterior a la fecha actual.');
                }
            }
        });
    }
}
