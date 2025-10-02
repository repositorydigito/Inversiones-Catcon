<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_expense_configs', function (Blueprint $table) {
            $table->decimal('travel_allowances', 10, 2)->default(30.00)->after('loading_expenses');
        });
    }

    public function down(): void
    {
        Schema::table('operational_expense_configs', function (Blueprint $table) {
            $table->dropColumn('travel_allowances');
        });
    }
};
