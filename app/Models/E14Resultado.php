<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Los votos de un candidato en una mesa (Spec 0061).
 */
class E14Resultado extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    protected $table = 'e14_resultados';

    protected $fillable = [
        'tenant_id',
        'e14_acta_id',
        'e14_candidate_id',
        'numero',
        'nombre',
        'votos',
    ];

    protected $casts = [
        'numero' => 'integer',
        'votos' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function acta(): BelongsTo
    {
        return $this->belongsTo(E14Acta::class, 'e14_acta_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(E14Candidate::class, 'e14_candidate_id');
    }
}
