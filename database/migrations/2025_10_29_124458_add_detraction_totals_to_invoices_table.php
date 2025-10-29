<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('total_detraction', 12, 2)
                  ->default(0)
                  ->after('total')
                  ->comment('Suma de todas las detracciones de los ítems');

            $table->decimal('net_payable_amount', 12, 2)
                  ->default(0)
                  ->after('total_detraction')
                  ->comment('Monto neto a pagar = Total - Total Detracción');

            $table->boolean('detraction')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'total_detraction',
                'net_payable_amount'
            ]);

            $table->boolean('detraction')->default(false)->change();
        });
    }
};
