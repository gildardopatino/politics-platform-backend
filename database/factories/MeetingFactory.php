<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Meeting>
 */
class MeetingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // El planificador pertenece al mismo tenant que la reunión.
            'planner_user_id' => fn (array $attributes) => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])->id,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'lugar_nombre' => fake()->company(),
            'direccion' => fake()->address(),
            'status' => 'scheduled',
            // Toda reunión sembrada nace con QR, y con él con formulario público
            // (Spec 0087). El panel pinta el QR y el link de check-in solo si la
            // reunión tiene `qr_code` —el recurso deriva el SVG de ahí bajo
            // demanda—, y ese código lo generaba **únicamente** el `store()` de
            // la API: todo lo sembrado nacía sin él, así que tras un
            // `migrate:fresh --seed` no había QR que imprimir aunque el check-in
            // funcionara.
            //
            // Solo el **código**, que es lo que faltaba; el SVG lo sigue
            // derivando el recurso. Llamar al servicio de QR desde aquí le
            // cobraría a cada prueba un archivo en disco sin necesitarlo.
            //
            // Va en `definition()` y no en un `afterCreating` para que lo que se
            // pase a mano mande: media suite fija el código para hacer check-in
            // con él, y alguna prueba pide `qr_code => null` a propósito para
            // ejercitar la reunión que todavía no lo tiene.
            'qr_code' => Str::random(32),
        ];
    }

    /**
     * Reunión perteneciente a un tenant concreto.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHours(2),
        ]);
    }
}
