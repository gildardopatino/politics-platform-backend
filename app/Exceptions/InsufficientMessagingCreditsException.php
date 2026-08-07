<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El tenant no tiene saldo para enviar la campaña entera (Spec 0040).
 *
 * El cobro es **todo o nada**: si a un canal le falta un solo crédito no sale
 * ningún mensaje. La excepción viaja con el detalle por canal para que la API
 * pueda decir exactamente qué falta y el panel ofrezca recargar.
 */
class InsufficientMessagingCreditsException extends RuntimeException
{
    /**
     * @param  array<string, array{needed: int, available: int, missing: int}>  $detalle
     */
    public function __construct(private array $detalle)
    {
        parent::__construct('Insufficient messaging credits to send this campaign');
    }

    /**
     * Los dos canales siempre, aunque uno no se use: una forma estable es más
     * fácil de pintar que una que aparece y desaparece.
     *
     * @return array<string, array{needed: int, available: int, missing: int}>
     */
    public function detalle(): array
    {
        return $this->detalle;
    }
}
