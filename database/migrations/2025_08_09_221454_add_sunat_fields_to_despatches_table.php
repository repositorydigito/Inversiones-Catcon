<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{   
    public function up(): void
    {
        Schema::table('despatches', function (Blueprint $table) {
            $table->string('sunat_ticket')->nullable();
            $table->string('xml_file_name')->nullable();
            $table->string('zip_hash')->nullable();           
        });
    }
    
    public function down(): void
    {
        Schema::table('despatches', function (Blueprint $table) {                        
            $table->dropColumn(['sunat_ticket', 'xml_file_name', 'zip_hash']);
        });
    }
};
