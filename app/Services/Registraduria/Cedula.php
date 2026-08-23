<?php

namespace App\Services\Registraduria;

/**
 * Utilidades de cédula para el flujo de Registraduría (Spec 0091).
 *
 * La cédula es PII (Constitución, Art. VII): no se escribe entera en logs ni en
 * mensajes de error. Todo lo que salga de este flujo pasa por `enmascarar()`.
 */
final class Cedula
{
    /**
     * Deja solo dígitos: «1.439.873-7» → «14398737».
     */
    public static function normalizar(?string $cedula): string
    {
        return preg_replace('/\D/', '', (string) $cedula) ?? '';
    }

    /**
     * Versión loggeable: «14398737» → «14****37».
     */
    public static function enmascarar(?string $cedula): string
    {
        $cedula = (string) $cedula;

        if (mb_strlen($cedula) <= 4) {
            return str_repeat('*', mb_strlen($cedula));
        }

        return mb_substr($cedula, 0, 2)
            .str_repeat('*', mb_strlen($cedula) - 4)
            .mb_substr($cedula, -2);
    }

    /**
     * Quita la cédula de un texto ajeno (mensaje de excepción, cuerpo de error)
     * antes de dejarlo salir a un log o a un resultado.
     */
    public static function sanear(string $texto, ?string $cedula): string
    {
        $cedula = (string) $cedula;

        if ($cedula === '') {
            return $texto;
        }

        return str_replace($cedula, self::enmascarar($cedula), $texto);
    }
}
