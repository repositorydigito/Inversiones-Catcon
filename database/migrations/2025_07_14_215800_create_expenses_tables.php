<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{    
    public function up(): void
    {
        Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Lavado, Combustible, Mantenimiento, etc.
            $table->enum('category', ['fixed', 'variable'])->default('variable'); // Fijo o Variable
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('operational_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $table->foreignId('expense_type_id')->constrained('expense_types');
            $table->foreignId('despatch_id')->nullable()->constrained('despatches'); // Relación con guía
            $table->foreignId('client_id')->nullable()->constrained('clients');
            
            $table->date('expense_date');
            $table->decimal('amount', 10, 2);
            $table->string('document_number')->nullable(); // Número de boleta/factura
            $table->text('description')->nullable();
            $table->text('observations')->nullable();
            
            // Campos específicos según el tipo de gasto
            $table->string('supplier')->nullable(); // Proveedor (para combustible, lavado, etc.)
            $table->string('location')->nullable(); // Ubicación del gasto
            $table->string('receipt_image_path')->nullable(); // Foto del comprobante
            
            $table->enum('status', ['pending', 'paid'])->default('pending');            
            
            $table->timestamps();
        });

        Schema::create('driver_expense_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $table->date('period_start');
            $table->date('period_end');
            
            // Gastos fijos (desde guías de remisión)
            $table->decimal('total_tolls', 10, 2)->default(0);
            $table->decimal('total_loading_expenses', 10, 2)->default(0);
            $table->decimal('total_travel_allowances', 10, 2)->default(0);
            $table->decimal('total_variable_salary', 10, 2)->default(0);
            
            // Gastos variables
            $table->decimal('total_fuel', 10, 2)->default(0);
            $table->decimal('total_washing', 10, 2)->default(0);
            $table->decimal('total_maintenance', 10, 2)->default(0);
            $table->decimal('total_other_expenses', 10, 2)->default(0);
            
            // Totales
            $table->decimal('total_fixed_expenses', 10, 2)->default(0);
            $table->decimal('total_variable_expenses', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);
            
            $table->integer('total_trips')->default(0); // Total de viajes
            $table->timestamps();
            
            $table->unique(['driver_id', 'period_start', 'period_end'], 'driver_expense_unique');
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('expense_types');
        Schema::dropIfExists('operational_expenses');
        Schema::dropIfExists('driver_expense_summaries');
    }
};
