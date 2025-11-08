<?php

namespace App\Http\Requests\Api\V1\Meeting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template_id' => 'sometimes|exists:meeting_templates,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'lugar_nombre' => 'nullable|string',
            'direccion' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'commune_id' => 'nullable|exists:communes,id',
            'barrio_id' => 'nullable|exists:barrios,id',
            'corregimiento_id' => 'nullable|exists:corregimientos,id',
            'vereda_id' => 'nullable|exists:veredas,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'sometimes|in:scheduled,in_progress,completed,cancelled',
            'metadata' => 'nullable|array',
            
            // Reminder validation
            'reminder' => 'nullable|array',
            'reminder.datetime' => [
                'required_with:reminder',
                'date',
                'after:now',
                function ($attribute, $value, $fail) {
                    $startsAt = $this->input('starts_at') ?? $this->route('meeting')->starts_at;
                    if (!$startsAt) {
                        return;
                    }

                    $reminderTime = \Carbon\Carbon::parse($value);
                    $meetingTime = \Carbon\Carbon::parse($startsAt);

                    // Reminder must be before meeting
                    if ($reminderTime >= $meetingTime) {
                        $fail('El recordatorio debe ser antes de la reunión.');
                    }

                    // Reminder must be at least 5 hours before meeting
                    if ($reminderTime > $meetingTime->copy()->subHours(5)) {
                        $fail('El recordatorio debe ser al menos 5 horas antes de la reunión.');
                    }
                }
            ],
            'reminder.recipients' => 'required_with:reminder|array|min:1',
            'reminder.recipients.*.user_id' => 'required|exists:users,id',
            'reminder.message' => 'nullable|string|max:500',
            'reminder.metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'template_id.exists' => 'La plantilla seleccionada no existe.',
            'title.max' => 'El título no puede exceder 255 caracteres.',
            'starts_at.date' => 'La fecha de inicio no es válida.',
            'ends_at.date' => 'La fecha de finalización no es válida.',
            'ends_at.after' => 'La fecha de finalización debe ser posterior a la fecha de inicio.',
            'department_id.exists' => 'El departamento seleccionado no existe.',
            'municipality_id.exists' => 'El municipio seleccionado no existe.',
            'commune_id.exists' => 'La comuna seleccionada no existe.',
            'barrio_id.exists' => 'El barrio seleccionado no existe.',
            'corregimiento_id.exists' => 'El corregimiento seleccionado no existe.',
            'vereda_id.exists' => 'La vereda seleccionada no existe.',
            'latitude.numeric' => 'La latitud debe ser un número.',
            'latitude.between' => 'La latitud debe estar entre -90 y 90.',
            'longitude.numeric' => 'La longitud debe ser un número.',
            'longitude.between' => 'La longitud debe estar entre -180 y 180.',
            'status.in' => 'El estado no es válido.',
            'metadata.array' => 'Los metadatos deben ser un objeto válido.',
            
            // Reminder messages
            'reminder.array' => 'El recordatorio debe ser un objeto válido.',
            'reminder.datetime.required_with' => 'La fecha del recordatorio es obligatoria.',
            'reminder.datetime.date' => 'La fecha del recordatorio no es válida.',
            'reminder.datetime.after' => 'El recordatorio debe ser en el futuro.',
            'reminder.recipients.required_with' => 'Debe seleccionar al menos un destinatario.',
            'reminder.recipients.array' => 'Los destinatarios deben ser un arreglo válido.',
            'reminder.recipients.min' => 'Debe seleccionar al menos un destinatario.',
            'reminder.recipients.*.user_id.required' => 'El ID del usuario es obligatorio.',
            'reminder.recipients.*.user_id.exists' => 'El usuario seleccionado no existe.',
            'reminder.message.max' => 'El mensaje no puede exceder 500 caracteres.',
        ];
    }
}
