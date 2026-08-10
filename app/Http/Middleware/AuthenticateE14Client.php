<?php

namespace App\Http\Middleware;

use App\Models\E14ServiceToken;
use App\Models\User;
use App\Scopes\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Autentica las rutas de E-14, con sesión o sin ella (Spec 0061 · Parte A).
 *
 * El módulo tiene dos clientes con formas de vida distintas: el panel, que trae
 * un JWT de una hora, y el lector de actas, que corre sin navegador en la
 * máquina de alguien y no puede renovar nada. Para el segundo hay un token de
 * servicio de larga vida (`e14_…`) que cuelga de un usuario de servicio del
 * tenant.
 *
 * Un solo middleware para los dos porque el camino sin sesión es el que hay que
 * vigilar: si viviera en un grupo de rutas aparte, sería el que se olvida de
 * pasar por `tenant`, por permisos o por auditoría. Aquí ambos terminan con un
 * `User` autenticado y el resto de la cadena —`EnsureTenant`, `permission:`,
 * `TenantScope`— se aplica igual, sin excepciones que revisar.
 *
 * El tenant sale siempre de la credencial. No hay ninguna cabecera ni campo del
 * cuerpo con el que un cliente pueda elegir a nombre de quién escribe.
 */
class AuthenticateE14Client
{
    public function handle(Request $request, Closure $next): Response
    {
        $credencial = $request->bearerToken();

        if (blank($credencial)) {
            return $this->rechazar('Falta la credencial de autenticación.');
        }

        $usuario = str_starts_with($credencial, E14ServiceToken::PREFIJO)
            ? $this->porTokenDeServicio($credencial, $request)
            : $this->porJwt($request);

        if (! $usuario) {
            return $this->rechazar('Credencial no válida.');
        }

        Auth::guard('api')->setUser($usuario);

        return $next($request);
    }

    /**
     * Resuelve el usuario de servicio dueño del token.
     *
     * Sin `TenantScope`: en este punto no hay tenant enlazado todavía, y el
     * token es precisamente lo que lo determina.
     */
    private function porTokenDeServicio(string $credencial, Request $request): ?User
    {
        $token = E14ServiceToken::porValor($credencial);

        if (! $token) {
            // El valor recibido no se registra: acabaría en los logs en claro.
            Log::warning('Token de servicio E-14 no reconocido', ['ip' => $request->ip()]);

            return null;
        }

        $usuario = User::withoutGlobalScope(TenantScope::class)->find($token->user_id);

        if (! $usuario) {
            return null;
        }

        // `saveQuietly` para no disparar auditoría en cada acta publicada: el
        // dato interesante es cuándo se usó por última vez, no un registro por
        // petición.
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $usuario;
    }

    /**
     * Resuelve el usuario del JWT.
     *
     * Se carga a mano en vez de con `authenticate()` del paquete por la misma
     * razón que en el token de servicio: la búsqueda del propio paquete pasa por
     * el proveedor Eloquent y, con él, por `TenantScope`. Autenticar no puede
     * depender de un tenant que todavía no se ha establecido —es el usuario
     * quien lo determina—, así que el scope se salta explícitamente.
     *
     * `getPayload()` valida firma y vigencia; si el token no sirve, lanza.
     */
    private function porJwt(Request $request): ?User
    {
        try {
            // El parser del paquete guarda la petición con la que se construyó;
            // sin esto, en un proceso de vida larga se leería la cabecera de
            // otra petición.
            JWTAuth::parser()->setRequest($request);

            $id = JWTAuth::parseToken()->getPayload()->get('sub');
        } catch (\Throwable) {
            return null;
        }

        return User::withoutGlobalScope(TenantScope::class)->find($id);
    }

    private function rechazar(string $mensaje): Response
    {
        return response()->json([
            'message' => $mensaje,
            'error' => 'UNAUTHENTICATED',
        ], 401);
    }
}
