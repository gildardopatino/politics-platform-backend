<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationAlias extends Model
{
    use HasFactory;

    protected $table = 'occupation_aliases';

    protected $fillable = ['occupation_id', 'alias'];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }
}
