<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Tenant extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'slug',
        'nombre',
        'tipo_cargo',
        'identificacion',
        'metadata',
        'biografia_data',
        's3_bucket',
        'logo',
        'sidebar_bg_color',
        'sidebar_text_color',
        'header_bg_color',
        'header_text_color',
        'content_bg_color',
        'content_text_color',
        'hierarchy_mode',
        'auto_assign_hierarchy',
        'hierarchy_conflict_resolution',
        'require_hierarchy_config',
    ];

    protected $casts = [
        'metadata' => 'array',
        'biografia_data' => 'array',
        'auto_assign_hierarchy' => 'boolean',
        'require_hierarchy_config' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'nombre', 'tipo_cargo', 'identificacion'])
            ->logOnlyDirty();
    }

    // Relationships
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }

    public function resourceAllocations()
    {
        return $this->hasMany(ResourceAllocation::class);
    }
}
