<?php

namespace Tests\Feature\E14;

use App\Models\E14ServiceToken;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Tests\TestCase;

/**
 * La credencial del lector de actas (Spec 0061 · Parte A).
 *
 * Es el único camino a la API que no pasa por un navegador, así que lo que se
 * prueba aquí es lo que pasa cuando se pierde: que en la base no haya nada
 * reutilizable, que rotar invalide de verdad y que el usuario al que cuelga no
 * pueda hacer más que publicar actas.
 */
class E14ServiceTokenCommandTest extends TestCase
{
    public function test_genera_un_token_y_solo_guarda_su_hash(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-uno']);

        $this->artisan('e14:token', ['tenant' => $tenant->slug])->assertSuccessful();

        $token = E14ServiceToken::withoutGlobalScope(TenantScope::class)->first();

        $this->assertNotNull($token);
        $this->assertSame($tenant->id, $token->tenant_id);
        $this->assertSame(64, strlen($token->token_hash), 'Debe guardarse un SHA-256, no el valor.');
        $this->assertNull($token->revoked_at);
    }

    public function test_el_usuario_de_servicio_solo_puede_lo_del_escrutinio(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-dos']);

        $this->artisan('e14:token', ['tenant' => $tenant->slug])->assertSuccessful();

        $token = E14ServiceToken::withoutGlobalScope(TenantScope::class)->first();
        $usuario = User::withoutGlobalScope(TenantScope::class)->find($token->user_id);

        $this->assertNotNull($usuario);
        $this->assertSame($tenant->id, $usuario->tenant_id);
        $this->assertFalse((bool) $usuario->is_super_admin);
        $this->assertSame(
            ['manage_e14', 'view_e14'],
            $usuario->getPermissionNames()->sort()->values()->all(),
            'El usuario de servicio no debe tener más permisos que los del escrutinio.'
        );
        $this->assertEmpty($usuario->getRoleNames()->all(), 'No debe llevar rol: los permisos van sueltos.');
    }

    public function test_no_emite_un_segundo_token_sin_pedirlo_expresamente(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-tres']);

        $this->artisan('e14:token', ['tenant' => $tenant->slug])->assertSuccessful();
        $this->artisan('e14:token', ['tenant' => $tenant->slug])->assertFailed();

        $this->assertSame(
            1,
            E14ServiceToken::withoutGlobalScope(TenantScope::class)->count(),
            'Sin --rotate no debe crearse otro token.'
        );
    }

    public function test_rotar_revoca_el_anterior_y_deja_uno_solo_vigente(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-cuatro']);

        $this->artisan('e14:token', ['tenant' => $tenant->slug])->assertSuccessful();
        $anterior = E14ServiceToken::withoutGlobalScope(TenantScope::class)->first();

        $this->artisan('e14:token', ['tenant' => $tenant->slug, '--rotate' => true])->assertSuccessful();

        // El anterior se revoca en vez de borrarse: queda el rastro.
        $this->assertNotNull($anterior->fresh()->revoked_at);
        $this->assertSame(
            1,
            E14ServiceToken::withoutGlobalScope(TenantScope::class)->whereNull('revoked_at')->count()
        );
        $this->assertSame(
            2,
            E14ServiceToken::withoutGlobalScope(TenantScope::class)->count(),
            'El token viejo se conserva revocado, no se borra.'
        );
    }

    public function test_un_token_revocado_deja_de_resolver(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'campana-cinco']);
        $usuario = User::factory()->forTenant($tenant)->create();

        $valor = E14ServiceToken::PREFIJO.'0123456789abcdef';

        $token = E14ServiceToken::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'user_id' => $usuario->id,
            'token_hash' => E14ServiceToken::hashDe($valor),
        ]);

        $this->assertNotNull(E14ServiceToken::porValor($valor));

        $token->update(['revoked_at' => now()]);

        $this->assertNull(E14ServiceToken::porValor($valor));
    }

    public function test_un_tenant_que_no_existe_no_crea_nada(): void
    {
        $this->artisan('e14:token', ['tenant' => 'no-existe'])->assertFailed();

        $this->assertSame(0, E14ServiceToken::withoutGlobalScope(TenantScope::class)->count());
    }
}
