<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{    
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id');
            $table->string('series', 4); 
            $table->integer('number'); 
            $table->string('invoice_type', 2); 
            $table->string('transaction_type', 2);            
            $table->date('emission_date');
            $table->date('due_date')->nullable(); 
            $table->string('currency', 2); 
            $table->decimal('exchange_rate', 10, 3)->nullable(); 
            $table->decimal('igv_percentage', 5, 2); 
            $table->decimal('global_discount', 10, 2)->nullable();
            $table->decimal('total_discount', 10, 2)->nullable();
            $table->decimal('total_advance', 10, 2)->nullable();
            $table->decimal('total_taxable', 10, 2); 
            $table->decimal('total_unaffected', 10, 2)->nullable();
            $table->decimal('total_exonerated', 10, 2)->nullable();
            $table->decimal('total_igv', 10, 2); 
            $table->decimal('total_gratuitous', 10, 2)->nullable();
            $table->decimal('total_other_charges', 10, 2)->nullable();
            $table->decimal('total', 10, 2); 

            // Campos relacionados con percepciones y detracciones
            $table->string('perception_type', 2)->nullable();
            $table->decimal('perception_taxable_base', 10, 2)->nullable();
            $table->decimal('total_perception', 10, 2)->nullable();
            $table->decimal('total_included_perception', 10, 2)->nullable();
            $table->boolean('detraction')->default(false);

            $table->text('observations')->nullable();
            $table->string('document_to_modify_type', 2)->nullable();
            $table->string('document_to_modify_series', 4)->nullable();
            $table->integer('document_to_modify_number')->nullable();
            $table->string('credit_note_type', 2)->nullable();
            $table->string('debit_note_type', 2)->nullable();

            $table->boolean('send_automatically_to_sunat')->default(true); 
            $table->boolean('send_automatically_to_client')->default(false); 
            $table->string('unique_code', 255)->nullable();
            $table->string('payment_conditions', 255)->nullable();
            $table->string('payment_method', 255)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->string('purchase_order_service', 255)->nullable();
            $table->string('custom_table_code', 255)->nullable();
            $table->string('pdf_format', 50)->nullable();

            // Campos para almacenar la respuesta de SUNAT
            $table->boolean('sunat_accepted')->nullable();
            $table->text('sunat_description')->nullable();
            $table->text('sunat_note')->nullable();
            $table->string('sunat_response_code', 10)->nullable();
            $table->text('sunat_soap_error')->nullable();
            $table->string('sunat_link')->nullable();
            $table->string('pdf_link')->nullable();
            $table->string('xml_link')->nullable();
            $table->string('cdr_link')->nullable();
            $table->string('nubefact_key')->nullable();            
            $table->longText('pdf_zip_base64')->nullable(); 
            $table->longText('xml_zip_base64')->nullable(); 
            $table->longText('cdr_zip_base64')->nullable(); 
            $table->text('qr_code_string')->nullable(); 
            $table->string('hash_code')->nullable(); 
            $table->string('barcode_string')->nullable(); 
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
