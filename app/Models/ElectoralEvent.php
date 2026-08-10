<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * La elección a la que pertenecen unas actas (Spec 0061).
 *
 * Existe para agrupar, nada más: sin ella, las actas de 2027 y las de 2031 de la
 * misma mesa colisionarían en el índice único y una pisaría a la otra.
 */
class ElectoralEvent extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'electoral_events';

    protected $fillable = [
        'tenant_id',
        'nombre',
        'fecha',
        'tipo',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(E14Candidate::class);
    }

    public function actas(): HasMany
    {
        return $this->hasMany(E14Acta::class);
    }
}
