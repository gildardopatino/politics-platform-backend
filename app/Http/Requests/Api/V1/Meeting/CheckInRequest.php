<?php

namespace App\Http\Requests\Api\V1\Meeting;

use App\Models\Meeting;
use App\Rules\CamposDeLaPlantilla;
use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
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
            'cedula' => 'required|string|max:20',
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'barrio_id' => 'nullable|exists:barrios,id',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email',
            // Los campos dinámicos se validan contra la plantilla de la reunión
            // (Spec 0023). Misma regla que el alta autenticada.
            'extra_fields' => ['nullable', 'array', CamposDeLaPlantilla::paraLaReunion($this->reunionDelQr())],
        ];
    }

    /**
     * La reunión del código QR, que es lo único que fija el ámbito en una ruta
     * pública (Spec 0026). Si el código no existe, la regla no exige nada y el
     * controlador responde el 404 de siempre.
     */
    private function reunionDelQr(): ?Meeting
    {
        return Meeting::where('qr_code', $this->route('qr_code'))->first();
    }
}
