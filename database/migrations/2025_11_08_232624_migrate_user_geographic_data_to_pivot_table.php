<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Department;
use App\Models\Municipality;
use App\Models\Commune;
use App\Models\Barrio;
use App\Models\Corregimiento;
use App\Models\Vereda;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrate existing single geographic assignments to the new many-to-many pivot table
     */
    public function up(): void
    {
        // Migrate Department assignments
        DB::statement("
            INSERT INTO user_geographic_assignments (user_id, tenant_id, assignable_type, assignable_id, created_at, updated_at)
            SELECT id, tenant_id, 'App\\Models\\Department', department_id, NOW(), NOW()
            FROM users
            WHERE department_id IS NOT NULL
        ");

        // Migrate Municipality assignments
        DB::statement("
            INSERT INTO user_geographic_assignments (user_id, tenant_id, assignable_type, assignable_id, created_at, updated_at)
            SELECT id, tenant_id, 'App\\Models\\Municipality', municipality_id, NOW(), NOW()
            FROM users
            WHERE municipality_id IS NOT NULL
        ");

        // Migrate Commune assignments
        DB::statement("
            INSERT INTO user_geographic_assignments (user_id, tenant_id, assignable_type, assignable_id, created_at, updated_at)
            SELECT id, tenant_id, 'App\\Models\\Commune', commune_id, NOW(), NOW()
            FROM users
            WHERE commune_id IS NOT NULL
        ");

        // Migrate Barrio assignments
        DB::statement("
            INSERT INTO user_geographic_assignments (user_id, tenant_id, assignable_type, assignable_id, created_at, updated_at)
            SELECT id, tenant_id, 'App\\Models\\Barrio', barrio_id, NOW(), NOW()
            FROM users
            WHERE barrio_id IS NOT NULL
        ");

        // Migrate Corregimiento assignments
        DB::statement("
            INSERT INTO user_geographic_assignments (user_id, tenant_id, assignable_type, assignable_id, created_at, updated_at)
            SELECT id, tenant_id, 'App\\Models\\Corregimiento', corregimiento_id, NOW(), NOW()
            FROM users
            WHERE corregimiento_id IS NOT NULL
        ");

        // Migrate Vereda assignments
        DB::statement("
            INSERT INTO user_geographic_assignments (user_id, tenant_id, assignable_type, assignable_id, created_at, updated_at)
            SELECT id, tenant_id, 'App\\Models\\Vereda', vereda_id, NOW(), NOW()
            FROM users
            WHERE vereda_id IS NOT NULL
        ");

        echo "✓ Geographic data migrated successfully to user_geographic_assignments table\n";
    }

    /**
     * Reverse the migrations.
     * This will clear the pivot table data (data loss warning!)
     */
    public function down(): void
    {
        DB::table('user_geographic_assignments')->truncate();
        echo "✓ user_geographic_assignments table cleared\n";
    }
};
