<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie's roles table ships with a unique(name, guard_name) constraint and the
 * package's "teams" feature is disabled. Because this app scopes roles per tenant
 * (tenant_id column + TenantScope), two tenants must be able to own roles with the
 * same name (e.g. each tenant has its own "admin"). This migration replaces the
 * unique key with (tenant_id, name, guard_name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            try {
                $table->dropUnique('roles_name_guard_name_unique');
            } catch (\Throwable $e) {
                // Index may already be gone (fresh installs / re-runs).
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_name_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            try {
                $table->dropUnique('roles_tenant_name_guard_unique');
            } catch (\Throwable $e) {
                // ignore
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
        });
    }
};
