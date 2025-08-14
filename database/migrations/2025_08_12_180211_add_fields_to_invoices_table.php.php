<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{    
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('detraction_service_code', 3)->default('027')->after('detraction');
            $table->string('detraction_payment_method', 3)->default('001')->after('detraction_service_code');
            $table->decimal('detraction_percentage', 5, 2)->default(4.00)->after('detraction_payment_method');
            $table->string('detraction_bank_account', 15)->nullable()->after('detraction_percentage');
        });
    }
    
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([                
                'detraction_service_code',
                'detraction_payment_method',
                'detraction_percentage',
                'detraction_bank_account',
            ]);
        });
    }
};
