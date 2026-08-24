<?php

namespace App\Http\Requests\Api\V1\E14;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Carga de un acta desde el panel (Spec 0071).
 *
 * **No lleva `tipo` desde la 0093.** En la 0071 lo elegía quien subía, porque el
 * dato no está en un sitio fiable del papel; el precio era que nada impedía
 * cargar un acta de otra elección y etiquetarla con el tipo equivocado, que es
 * la peor de las dos opciones: el acta entra al parser que no le toca y produce
 * o basura o un «no cuadró» que nadie sabe explicar. Ahora el tipo lo pone la
 * campaña —una campaña, una elección— y lo que el papel diga de verdad lo
 * comprueba el lector al leerlo (`eleccion_detectada`, capa 2).
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
            'archivo.required' => 'Falta el archivo del acta.',
            'archivo.mimetypes' => 'El acta debe ser un PDF.',
            'archivo.mimes' => 'El acta debe ser un PDF.',
            'archivo.max' => 'El archivo supera el tamaño máximo permitido.',
        ];
    }
}
