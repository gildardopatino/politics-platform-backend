<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Municipality extends Model
{
    use HasFactory;

    protected $table = 'municipalities';

    protected $fillable = [
        'department_id',
        'codigo',
        'nombre',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    // Relationships
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function communes()
    {
        return $this->hasMany(Commune::class);
    }

    public function barrios()
    {
        return $this->hasMany(Barrio::class);
    }

    public function corregimientos()
    {
        return $this->hasMany(Corregimiento::class);
    }

    public function veredas()
    {
        return $this->hasMany(Vereda::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }
}
