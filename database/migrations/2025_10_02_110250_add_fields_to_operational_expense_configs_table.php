<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{    
    public function up(): void
    {
        Schema::table('operational_expense_configs', function (Blueprint $table) {
            $table->decimal('rate', 10, 2)->default(0)->after('security');
        });
    }

    public function down(): void
    {
        Schema::table('operational_expense_configs', function (Blueprint $table) {
            $table->dropColumn('rate');
        });
    }
};
