<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {

            $table->enum('tipo_carga', ['contenedor_lleno', 'carga_general_liquidos'])
                  ->default('carga_general_liquidos')
                  ->after('detraction_bank_account');
            
            $table->decimal('distancia_km', 8, 2)
                  ->default(0)
                  ->after('tipo_carga');
            
            $table->decimal('peso_toneladas', 8, 2)
                  ->nullable()
                  ->after('distancia_km')
                  ->comment('Solo requerido para carga_general_liquidos');
            
            $table->boolean('retorno_vacio')
                  ->default(false)
                  ->after('peso_toneladas')
                  ->comment('Factor 1.4 si distancia > 200km');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_carga',
                'distancia_km', 
                'peso_toneladas',
                'retorno_vacio'
            ]);
        });
    }
};