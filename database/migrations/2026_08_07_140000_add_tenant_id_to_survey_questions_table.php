<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las preguntas heredan el tenant de su encuesta (Spec 0031). Sin la
     * columna, `SurveyQuestion` no podía usar `HasTenant` y las rutas
     * `shallow()` resolvían el binding contra toda la tabla.
     */
    public function up(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->foreignId('tenant_id')->after('id')->nullable()->constrained('tenants')->onDelete('cascade');
        });

        // Backfill desde la encuesta madre. `surveys` borra en blando, así que
        // la fila sigue ahí incluso para encuestas eliminadas.
        DB::statement('
            UPDATE survey_questions
            SET tenant_id = (
                SELECT tenant_id
                FROM surveys
                WHERE surveys.id = survey_questions.survey_id
            )
        ');

        Schema::table('survey_questions', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
            $table->index(['tenant_id', 'survey_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'survey_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
