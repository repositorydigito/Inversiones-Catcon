<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('reference_value', 12, 2)
                  ->default(0)
                  ->after('total')
                  ->comment('Valor de referencia para cálculo de detracción');

            $table->decimal('item_detraction_amount', 12, 2)
                  ->default(0)
                  ->after('reference_value')
                  ->comment('Monto de detracción del ítem (reference_value × detraction_percentage)');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'reference_value',
                'item_detraction_amount'
            ]);
        });
    }
};
