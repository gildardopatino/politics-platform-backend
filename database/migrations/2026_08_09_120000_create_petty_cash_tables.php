<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caja menor (Spec 0057).
 *
 * Hasta ahora entregar dinero era «entregar y ya»: un monto, una persona y un
 * texto con el propósito. No había fondo, ni saldo, ni forma de cerrar el
 * anticipo, así que nadie podía responder cuánto se entregó, cuánto se legalizó
 * y cuánto quedó sin comprobar.
 *
 * Tres tablas, una por concepto:
 * - el **fondo** tiene saldo y es de donde sale el dinero;
 * - el **anticipo** es lo que se le entrega a una persona para una reunión, y
 *   vive hasta que se cierra;
 * - las **líneas de gasto** son el detalle de la legalización, con o sin recibo.
 *
 * `balance` se guarda en la tabla pero **no se escribe a mano**: lo mantiene el
 * servicio dentro de una transacción, con la fila bloqueada, para que dos
 * anticipos simultáneos no gasten el mismo saldo dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('COP');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('petty_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('petty_cash_fund_id')->constrained('petty_cash_funds')->onDelete('cascade');
            $table->foreignId('petty_cash_advance_id')->nullable();
            $table->string('type', 20); // reposicion | anticipo | reintegro
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'petty_cash_fund_id']);
        });

        Schema::create('petty_cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('petty_cash_fund_id')->constrained('petty_cash_funds')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // quien recibe
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->decimal('amount_spent', 15, 2)->default(0);
            $table->decimal('amount_returned', 15, 2)->default(0);
            $table->decimal('amount_charged_off', 15, 2)->default(0);

            // pendiente_legalizar | legalizado | castigado
            $table->string('status', 30)->default('pendiente_legalizar');
            $table->text('purpose')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('meeting_id');
        });

        Schema::create('petty_cash_expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_advance_id')->constrained('petty_cash_advances')->onDelete('cascade');
            $table->string('description');
            $table->decimal('amount', 15, 2);
            // Se permite el gasto sin comprobante a propósito: exigir recibos que
            // no van a existir solo consigue que nadie legalice nada.
            $table->boolean('has_receipt')->default(false);
            $table->string('receipt_ref')->nullable();
            $table->timestamps();

            $table->index('petty_cash_advance_id');
        });

        Schema::table('petty_cash_movements', function (Blueprint $table) {
            $table->foreign('petty_cash_advance_id')->references('id')->on('petty_cash_advances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_expense_lines');
        Schema::dropIfExists('petty_cash_movements');
        Schema::dropIfExists('petty_cash_advances');
        Schema::dropIfExists('petty_cash_funds');
    }
};
