<?php

namespace App\Http\Requests\Api\V1\Voter;

use App\Models\Voter;
use App\Models\VoterProfile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Subida de una hoja de vida (Spec 0096).
 *
 * Dos filtros, y los dos **antes** de que nada toque el disco: el tipo real del
 * archivo y la autorización de tratamiento de datos.
 */
class StoreVoterResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El permiso `manage_voter_profiles` lo aplica la ruta.
        return true;
    }

    /**
     * `mimetypes` mira el contenido y `mimes` la extensión: hacen falta los dos.
     * Un `.exe` renombrado a `.pdf` pasa el segundo y no el primero, y un PDF
     * legítimo renombrado a `.exe` pasa el primero y no el segundo.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => [
                'required',
                'file',
                'mimetypes:application/pdf,application/msword,'
                    .'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    .'image/jpeg,image/png',
                'mimes:pdf,doc,docx,jpg,jpeg,png',
                'max:'.config('voter_resumes.max_upload_kb'),
            ],
        ];
    }

    /**
     * Ley 1581: sin autorización del titular no se guarda su hoja de vida.
     *
     * Vive aquí y no en el controlador para que el rechazo ocurra antes de
     * escribir: una validación posterior dejaría el archivo en disco y luego
     * diría que no.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $voter = $this->route('voter');

            if (! $voter instanceof Voter) {
                return;
            }

            $perfil = VoterProfile::where('voter_id', $voter->id)->first();

            if (! $perfil) {
                $validator->errors()->add(
                    'autoriza_tratamiento_datos',
                    'El elector no tiene perfil laboral registrado. Registra su perfil y marca la autorización de tratamiento de datos antes de adjuntar la hoja de vida.'
                );

                return;
            }

            if (! $perfil->autoriza_tratamiento_datos) {
                $validator->errors()->add(
                    'autoriza_tratamiento_datos',
                    'El elector no ha autorizado el tratamiento de sus datos (Ley 1581). Marca la autorización en su perfil laboral antes de adjuntar la hoja de vida.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Falta el archivo de la hoja de vida.',
            'archivo.file' => 'La hoja de vida debe ser un archivo.',
            'archivo.mimetypes' => 'La hoja de vida debe ser un PDF, un documento de Word o una imagen (JPG o PNG).',
            'archivo.mimes' => 'La hoja de vida debe ser un PDF, un documento de Word o una imagen (JPG o PNG).',
            'archivo.max' => 'La hoja de vida supera el tamaño máximo de 8 MB.',
        ];
    }
}
