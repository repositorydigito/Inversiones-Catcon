<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despatches', function (Blueprint $table) {
            $table->id();
            $table->string('operation')->default('generar_guia'); // Operación por defecto según nubefact
            $table->integer('document_type');
            $table->string('series');
            $table->integer('number');

            // Para los campos client (En GRE Transportista se refiere al Remitente)
            $table->foreignId('company_id')->constrained('companies');

            $table->date('emission_date');
            $table->text('observations')->nullable();
            $table->decimal('total_gross_weight', 10, 2);
            $table->string('total_gross_weight_unit_of_measure', 3);
            $table->decimal('net_weight', 10, 2)->nullable();
            $table->date('transfer_start_date');

            // Main Transporter/Driver Information
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $table->foreignId('driver_id')->nullable()->constrained('drivers');

            // Para los campos destinatario
            $table->foreignId('client_id')->constrained('clients');

            // Departure Point Information (Punto de Partida)
            $table->string('departure_ubigeo', 6);
            $table->string('departure_address');
            $table->string('departure_sunat_establishment_code', 4)->nullable();
            $table->string('departure_departamento', 2)->nullable();
            $table->string('departure_provincia', 2)->nullable();
            $table->string('departure_distrito', 2)->nullable();

            // Arrival Point Information (Punto de Llegada)
            $table->string('arrival_ubigeo', 6);
            $table->string('arrival_address');
            $table->string('arrival_sunat_establishment_code', 4)->nullable();
            $table->string('arrival_departamento', 2)->nullable();
            $table->string('arrival_provincia', 2)->nullable();
            $table->string('arrival_distrito', 2)->nullable();

            // Nuevos campos opcionales/condicionales
            $table->string('sunat_envio_indicador', 2)->nullable(); // Código que indica el tipo de envío (01, 02, 03, 04, 05, 06)

            // Campos para subcontratador (si sunat_envio_indicador es '02')
            $table->integer('subcontractor_document_type')->nullable(); // Solo 6 = RUC
            $table->string('subcontractor_document_number')->nullable();
            $table->string('subcontractor_denomination')->nullable();

            // Campos para pagador del servicio (si sunat_envio_indicador es '03')
            $table->integer('service_payer_document_type')->nullable(); // 6, 1, 4, 7, A, 0
            $table->string('service_payer_document_number')->nullable();
            $table->string('service_payer_denomination')->nullable();

            $table->boolean('send_automatically_to_client')->default(false);
            $table->string('pdf_format')->nullable();

            // SUNAT Response Fields
            $table->boolean('accepted_by_sunat')->nullable();
            $table->text('sunat_description')->nullable();
            $table->text('sunat_note')->nullable();
            $table->string('sunat_response_code')->nullable();
            $table->text('sunat_soap_error')->nullable();
            $table->text('qr_code_string')->nullable();
            $table->string('enlace_del_pdf')->nullable();
            $table->string('enlace_del_xml')->nullable();
            $table->string('enlace_del_cdr')->nullable();

            // Gastos operativos
            $table->string('loading_point')->nullable(); // Punto 1
            $table->string('unloading_point')->nullable(); // Punto 4
            $table->string('departure_location')->nullable();
            $table->string('arrival_location')->nullable();
            $table->string('product')->nullable();
            $table->string('supplier')->nullable();
            $table->decimal('tolls', 10, 2)->default(0); // Peajes
            $table->decimal('loading_expenses', 10, 2)->default(0); // Gastos de carga
            $table->decimal('travel_allowances', 10, 2)->default(0); // Viáticos
            $table->decimal('variable_salary', 10, 2)->default(0); // Sueldo variable
            $table->decimal('operations_manager', 10, 2)->default(0); // Jefe de operaciones
            $table->decimal('security', 10, 2)->default(0); // Seguridad

            $table->timestamps();
        });

        Schema::create('despatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despatch_id')->constrained('despatches')->onDelete('cascade');
            $table->foreignId('unit_of_measure_id')->constrained('measure_units');
            $table->string('code')->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->timestamps();
        });

        Schema::create('despatch_related_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despatch_id')->constrained('despatches')->onDelete('cascade');
            $table->string('document_type', 2);
            $table->string('series', 4);
            $table->integer('number');
            $table->timestamps();
        });

        // Tabla pivote para vehículos secundarios
        Schema::create('despatch_secondary_vehicle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despatch_id')->constrained('despatches')->onDelete('cascade');
            $table->foreignId('vehicle_id')->constrained('vehicles')->onDelete('cascade');
            $table->timestamps();
        });

        // Tabla pivote para conductores secundarios
        Schema::create('despatch_secondary_driver', function (Blueprint $table) {
            $table->id();
            $table->foreignId('despatch_id')->constrained('despatches')->onDelete('cascade');
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despatch_secondary_drivers');
        Schema::dropIfExists('despatch_secondary_vehicles');
        Schema::dropIfExists('despatch_related_documents');
        Schema::dropIfExists('despatch_items');
        Schema::dropIfExists('despatches');
    }
};
