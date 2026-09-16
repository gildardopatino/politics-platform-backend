<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una hoja de vida adjunta a un elector (Spec 0096).
 *
 * `archivo_key` es la ruta en el disco privado. No se expone en los Resources ni
 * se registra en logs: quien puede descargarla lo hace por la ruta firmada.
 */
class VoterResume extends Model
{
    use HasFactory, HasTenant;

    protected $table = 'voter_resumes';

    protected $fillable = [
        'tenant_id',
        'voter_id',
        'archivo_key',
        'nombre_original',
        'mime',
        'tamano_bytes',
        'subido_por',
    ];

    protected $casts = [
        'tamano_bytes' => 'integer',
    ];

    /**
     * Fuera de `toArray()` y, con ello, de cualquier volcado accidental a logs.
     *
     * @var array<int, string>
     */
    protected $hidden = ['archivo_key'];

    public function voter(): BelongsTo
    {
        return $this->belongsTo(Voter::class);
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
