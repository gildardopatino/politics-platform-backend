<?php

namespace Tests\Unit\Support;

use App\Support\DatabaseExpressions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El objetivo del helper es que la suite (SQLite) pueda ejercitar consultas que
 * en producción corren sobre PostgreSQL, **sin cambiar lo que se ejecuta en
 * PostgreSQL**. Estas pruebas fijan las dos ramas.
 *
 * Cambiar la conexión por defecto no abre ninguna: `getDriverName()` solo lee la
 * configuración.
 */
class DatabaseExpressionsTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');

        parent::tearDown();
    }

    public function test_en_postgresql_emite_el_mismo_sql_que_antes_del_cambio(): void
    {
        DB::setDefaultConnection('pgsql');

        $this->assertSame(
            'EXTRACT(YEAR FROM starts_at) as year',
            $this->sql(DatabaseExpressions::year('starts_at'))
        );
        $this->assertSame(
            'EXTRACT(MONTH FROM starts_at) as month',
            $this->sql(DatabaseExpressions::month('starts_at'))
        );
        $this->assertSame(
            "(new_values::jsonb->>'tenant_id')::int = ?",
            DatabaseExpressions::jsonInteger('new_values', 'tenant_id')
        );
        $this->assertSame('ILIKE', DatabaseExpressions::caseInsensitiveLike());
    }

    public function test_en_sqlite_usa_las_funciones_equivalentes(): void
    {
        DB::setDefaultConnection('sqlite');

        $this->assertSame(
            "CAST(strftime('%Y', starts_at) AS INTEGER) as year",
            $this->sql(DatabaseExpressions::year('starts_at'))
        );
        $this->assertSame(
            "CAST(strftime('%m', starts_at) AS INTEGER) as month",
            $this->sql(DatabaseExpressions::month('starts_at'))
        );
        $this->assertSame(
            "CAST(json_extract(new_values, '$.tenant_id') AS INTEGER) = ?",
            DatabaseExpressions::jsonInteger('new_values', 'tenant_id')
        );
        $this->assertSame('like', DatabaseExpressions::caseInsensitiveLike());
    }

    public function test_el_alias_es_configurable(): void
    {
        $this->assertStringEndsWith(' as anio', $this->sql(DatabaseExpressions::year('created_at', 'anio')));
    }

    /**
     * En Laravel 12 `Expression` ya no es convertible a string: hay que
     * resolverla con la gramática de la conexión.
     */
    private function sql(\Illuminate\Database\Query\Expression $expression): string
    {
        return (string) $expression->getValue(DB::connection()->getQueryGrammar());
    }
}
