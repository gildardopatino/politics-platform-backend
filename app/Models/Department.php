<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'codigo',
        'nombre',
        'latitud',
        'longitud',
        'path',
        'metadata',
    ];

    protected $casts = [
        'latitud' => 'decimal:8',
        'longitud' => 'decimal:8',
        'metadata' => 'array',
    ];

    // Relationships
    public function municipalities()
    {
        return $this->hasMany(Municipality::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }
}
