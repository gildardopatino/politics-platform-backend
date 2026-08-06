<?php

namespace App\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Expresiones SQL equivalentes por driver (Spec 0019).
 *
 * Runtime es PostgreSQL y las pruebas corren sobre SQLite in-memory. Varios
 * controllers escribían SQL crudo solo-PostgreSQL (`EXTRACT(... FROM ...)`,
 * `::jsonb->>'...'`, `ILIKE`) y devolvían 500 en la suite, así que no había forma
 * de cubrirlos con pruebas Feature.
 *
 * **La rama de pgsql emite exactamente el mismo SQL que antes**: en producción no
 * cambia nada. Es la misma solución que la Spec 0001 aplicó a las migraciones.
 */
final class DatabaseExpressions
{
    /**
     * Año de una columna de fecha, como entero.
     */
    public static function year(string $column, string $alias = 'year'): Expression
    {
        return DB::raw(self::datePart('YEAR', '%Y', $column).' as '.$alias);
    }

    /**
     * Mes de una columna de fecha, como entero.
     */
    public static function month(string $column, string $alias = 'month'): Expression
    {
        return DB::raw(self::datePart('MONTH', '%m', $column).' as '.$alias);
    }

    /**
     * Condición `<columna JSON>-><clave> = <entero>` para un `whereRaw`.
     *
     * `new_values`/`old_values` de la auditoría son texto con JSON dentro, no
     * columnas JSON declaradas, así que se leen con la función JSON del driver.
     *
     * @return string SQL con un placeholder `?` para el valor
     */
    public static function jsonInteger(string $column, string $key): string
    {
        return match (self::driver()) {
            'pgsql' => "({$column}::jsonb->>'{$key}')::int = ?",
            'mysql', 'mariadb' => "CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}')) AS UNSIGNED) = ?",
            default => "CAST(json_extract({$column}, '$.{$key}') AS INTEGER) = ?",
        };
    }

    /**
     * Operador de comparación de texto insensible a mayúsculas.
     *
     * SQLite y MySQL ya comparan `LIKE` sin distinguir mayúsculas en ASCII;
     * PostgreSQL necesita `ILIKE`.
     */
    public static function caseInsensitiveLike(): string
    {
        return self::driver() === 'pgsql' ? 'ILIKE' : 'like';
    }

    private static function datePart(string $sqlPart, string $strftime, string $column): string
    {
        return match (self::driver()) {
            'pgsql' => "EXTRACT({$sqlPart} FROM {$column})",
            'mysql', 'mariadb' => strtoupper($sqlPart)."({$column})",
            default => "CAST(strftime('{$strftime}', {$column}) AS INTEGER)",
        };
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
