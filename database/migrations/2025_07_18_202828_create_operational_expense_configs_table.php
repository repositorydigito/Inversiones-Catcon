<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{   
    public function up(): void
    {
        Schema::create('operational_expense_configs', function (Blueprint $table) {
            $table->id();
            $table->string('departure_point',50);
            $table->string('departure_location',50);
            $table->string('arrival_location',50);
            $table->string('destination_point',50);
            $table->decimal('tolls', 8, 2)->default(0);
            $table->decimal('loading_expenses', 8, 2)->default(0);
            $table->decimal('variable_salary', 8, 2)->default(0);
            $table->decimal('operations_manager', 8, 2)->default(0);
            $table->decimal('security', 8, 2)->default(0);
            $table->timestamps();
                        
            $table->unique(['departure_point', 'departure_location', 'arrival_location', 'destination_point'], 'unique_operational_expense_configs_combination');
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('operational_expense_configs');
    }
};
