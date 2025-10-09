<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('despatches', function (Blueprint $table) {
            $table->decimal('rate', 10, 2)->default(0)->after('security');
            $table->decimal('gross_sale', 10, 2)->default(0)->after('rate');
        });
    }

    public function down()
    {
        Schema::table('despatches', function (Blueprint $table) {
            $table->dropColumn(['rate', 'gross_sale']);
        });
    }
};
