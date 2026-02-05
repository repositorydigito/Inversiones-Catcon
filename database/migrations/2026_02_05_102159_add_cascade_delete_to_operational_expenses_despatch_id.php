<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('operational_expenses', function (Blueprint $table) {
            // Eliminar la foreign key existente
            $table->dropForeign(['despatch_id']);

            // Recrearla con cascade delete
            $table->foreign('despatch_id')
                  ->references('id')
                  ->on('despatches')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_expenses', function (Blueprint $table) {
            // Revertir: eliminar cascade y dejar como estaba
            $table->dropForeign(['despatch_id']);

            $table->foreign('despatch_id')
                  ->references('id')
                  ->on('despatches');
        });
    }
};
