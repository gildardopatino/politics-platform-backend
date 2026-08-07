<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Los filtros de la campaña no resolvieron a nadie (Spec 0040).
 *
 * No es un error del servidor ni un envío vacío que cobrar: se avisa y la
 * campaña se queda en borrador para que se corrijan los filtros.
 */
class CampaignHasNoRecipientsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Campaign has no recipients to send');
    }
}
