<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despatches', function (Blueprint $table) {
            $table->decimal('net_sale', 10, 2)->nullable()->after('security');
        });
    }

    public function down(): void
    {
        Schema::table('despatches', function (Blueprint $table) {
            $table->dropColumn('net_sale');
        });
    }
};
