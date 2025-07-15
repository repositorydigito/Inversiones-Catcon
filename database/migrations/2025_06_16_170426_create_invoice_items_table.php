<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{    
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');; 
            $table->foreignId('unit_of_measure_id')->constrained('measure_units')->onDelete('cascade');; 
            $table->string('code', 50)->nullable(); 
            $table->string('description', 255); 
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_value', 10, 2); 
            $table->decimal('unit_price', 10, 2); 
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('subtotal', 10, 2); 
            $table->string('igv_type', 2); 
            $table->decimal('igv', 10, 2); 
            $table->decimal('total', 10, 2);
            $table->boolean('advance_regularization')->default(false);
            $table->string('advance_document_series', 4)->nullable();
            $table->integer('advance_document_number')->nullable();
            $table->timestamps();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
