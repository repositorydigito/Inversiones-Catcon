<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{    
    public function up(): void
    {
        Schema::create('traffic_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('image_path')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->onDelete('cascade');;
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('cascade');;
            $table->timestamps();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('traffic_tickets');
    }
};
