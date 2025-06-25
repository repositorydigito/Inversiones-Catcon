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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number');
            $table->string('brand');
            $table->string('model');
            $table->string('vehicle_certificate')->nullable();
            $table->foreignId('driver_id')->nullable();
            $table->date('soat_expiration_date')->nullable();
            $table->date('technical_review_expiration_date');
            $table->date('tuce_expiration_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
