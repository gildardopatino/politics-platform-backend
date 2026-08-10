<?php

namespace App\Http\Requests\Api\V1\E14;

use App\Models\E14Acta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Carga de un acta desde el panel (Spec 0071).
 *
 * El tipo de elección lo elige la persona que sube: no está en un sitio fiable
 * del papel y equivocarlo manda el acta al parser equivocado.
 */
class UploadE14ActaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El permiso lo aplica `permission:manage_e14` en la ruta.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(E14Acta::TIPOS)],
            'archivo' => [
                'required', 'file', 'mimetypes:application/pdf', 'mimes:pdf',
                'max:'.config('e14.max_upload_kb'),
            ],
            'electoral_event_id' => 'nullable|integer',
            // Lo manda el cliente para agrupar los PDFs de una misma tanda. Si
            // no viene, el servidor abre uno y lo devuelve.
            'upload_batch_id' => 'nullable|uuid',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Elige el tipo de elección del acta.',
            'tipo.in' => 'Ese tipo de elección no existe.',
            'archivo.required' => 'Falta el archivo del acta.',
            'archivo.mimetypes' => 'El acta debe ser un PDF.',
            'archivo.mimes' => 'El acta debe ser un PDF.',
            'archivo.max' => 'El archivo supera el tamaño máximo permitido.',
        ];
    }
}
