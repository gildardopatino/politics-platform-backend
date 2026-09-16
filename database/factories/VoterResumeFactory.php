<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Voter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoterResume>
 */
class VoterResumeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'voter_id' => Voter::factory(),
            'archivo_key' => 'hojas-vida/1/1/'.Str::ulid().'.pdf',
            'nombre_original' => 'hoja-de-vida.pdf',
            'mime' => 'application/pdf',
            'tamano_bytes' => 1024,
        ];
    }

    /**
     * Hoja del elector dado, con la clave en la carpeta de su tenant.
     */
    public function paraVotante(Voter $voter): static
    {
        return $this->state(fn () => [
            'voter_id' => $voter->id,
            'tenant_id' => $voter->tenant_id,
            'archivo_key' => "hojas-vida/{$voter->tenant_id}/{$voter->id}/".Str::ulid().'.pdf',
        ]);
    }
}
