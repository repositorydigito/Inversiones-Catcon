<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            // Sección Datos de Empresa
            $table->string('ruc')->default('20601921023');
            $table->string('name')->default('Inversiones Catcon S.A.C.');
            $table->string('commercial_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('mtc_registration_number')->nullable();

            // Sección de campos para guias de remisión
            $table->string('document_type')->default('6');
            $table->string('address')->default('Cal. German Schreiber 276 Urb. Santa Ana');
            $table->string('email')->nullable();

            // Sección Entorno del Sistema
            $table->enum('soap_type', ['demo', 'production'])->default('demo');
            $table->enum('soap_delivery_method', ['sunat', 'ose'])->default('ose');

            // Sección Consulta CPE
            $table->string('cpe_client_id')->nullable();
            $table->string('cpe_client_secret')->nullable();

            // Sección Guías Electrónicas
            $table->string('electronic_guides_soap_username')->nullable();
            $table->string('electronic_guides_soap_password')->nullable();
            $table->string('electronic_guides_client_id')->nullable();
            $table->string('electronic_guides_client_secret')->nullable();

            // Sección Certificado Digital
            $table->string('certificate_path')->nullable();
            $table->string('certificate_pass')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
