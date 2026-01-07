<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_expense_configs', function (Blueprint $table) {
            $table->decimal('rate', 10, 5)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('operational_expense_configs', function (Blueprint $table) {
            $table->decimal('rate', 10, 3)->default(0)->change();
        });
    }
};
