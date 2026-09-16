<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo votante↔oficio (Spec 0094).
 *
 * Es un modelo y no un `attach()` pelado porque el pivote lleva `tenant_id`:
 * `HasTenant` lo rellena y lo acota, y el Artículo III no admite una tabla con
 * datos de campaña que se escriba por fuera del trait.
 */
class VoterOccupation extends Model
{
    use HasTenant;

    public const BUSCA = 'busca';

    public const EXPERIENCIA = 'experiencia';

    protected $table = 'voter_occupations';

    protected $fillable = ['tenant_id', 'voter_id', 'occupation_id', 'relacion'];

    public function voter(): BelongsTo
    {
        return $this->belongsTo(Voter::class);
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    /**
     * @return array<int, string>
     */
    public static function relaciones(): array
    {
        return [self::BUSCA, self::EXPERIENCIA];
    }
}
