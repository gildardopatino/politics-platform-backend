<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica los webhooks de Registraduría y fija el tenant sobre el que operan
 * (Spec 0030).
 *
 * Eran rutas públicas que consultaban con `withoutGlobalScope(TenantScope)`:
 * cualquiera con la URL sacaba cédulas de todas las campañas y escribía en sus
 * votantes. Ahora cada tenant tiene **su propio secreto**, que viaja en
 * `X-Registraduria-Secret`.
 *
 * El secreto autentica **e identifica**: no hay campo de tenant en el payload
 * que un atacante pueda elegir, y una fuga compromete una campaña, no la
 * plataforma. Al enlazar `current_tenant_id`, `TenantScope` filtra el resto de
 * la petición igual que en las rutas con sesión.
 */
class VerifyRegistraduriaWebhook
{
    public const CABECERA = 'X-Registraduria-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $secreto = $request->header(self::CABECERA);

        if (blank($secreto)) {
            return $this->rechazar('Falta la cabecera de autenticación del webhook.', 'WEBHOOK_SECRET_MISSING');
        }

        $tenant = Tenant::porSecretoRegistraduria($secreto);

        if (! $tenant) {
            // No se registra el secreto recibido: iría a los logs en claro.
            Log::warning('Webhook de Registraduría con secreto no reconocido', [
                'ip' => $request->ip(),
            ]);

            return $this->rechazar('Secreto de webhook no reconocido.', 'WEBHOOK_SECRET_INVALID');
        }

        // Misma regla que para las sesiones (`CheckTenantExpiration`): una
        // campaña fuera de vigencia no sincroniza.
        if ($tenant->isExpired()) {
            return $this->rechazar('La vigencia de la campaña ha expirado.', 'TENANT_EXPIRED', 403);
        }

        if ($tenant->isNotStarted()) {
            return $this->rechazar('La vigencia de la campaña aún no ha comenzado.', 'TENANT_NOT_STARTED', 403);
        }

        // A partir de aquí `TenantScope` filtra: el controlador ya no necesita
        // —ni debe— saltarse el scope.
        app()->instance('tenant', $tenant);
        app()->instance('current_tenant_id', $tenant->id);

        return $next($request);
    }

    private function rechazar(string $mensaje, string $codigo, int $estado = 401): Response
    {
        return response()->json([
            'success' => false,
            'message' => $mensaje,
            'error' => $codigo,
        ], $estado);
    }
}
