<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{    
    public function up(): void
    {
        Schema::table('despatches', function (Blueprint $table) {
            // Campos del CDR (los campos sunat_ticket, xml_file_name, zip_hash ya existen)
            $table->text('cdr_pdf_url')->nullable()
                  ->comment('URL del QR/PDF extraída del CDR de SUNAT');
            
            $table->string('cdr_status', 20)->nullable()
                  ->comment('Estado del CDR: ACEPTADO, OBSERVADO, RECHAZADO');
            
            $table->text('cdr_description')->nullable()
                  ->comment('Descripción del estado del CDR desde SUNAT');
            
            $table->integer('cdr_notes_count')->default(0)
                  ->comment('Cantidad de observaciones en el CDR');
            
            $table->boolean('cdr_has_errors')->default(false)
                  ->comment('Indica si el CDR tiene errores críticos');
                  
            $table->boolean('cdr_has_warnings')->default(false)
                  ->comment('Indica si el CDR tiene advertencias');
            
            $table->datetime('cdr_issue_datetime')->nullable()
                  ->comment('Fecha y hora de emisión del CDR por SUNAT');
            
            $table->text('cdr_error_codes')->nullable()
                  ->comment('Códigos de error específicos separados por comas');
            
            // Campo opcional para guardar CDR completo (para reprocesamiento)
            $table->longText('cdr_base64_content')->nullable()
                  ->comment('Contenido completo del CDR en Base64 (opcional)');
            
            // Metadatos adicionales en JSON
            $table->json('cdr_metadata')->nullable()
                  ->comment('Metadatos adicionales del procesamiento del CDR');
        });
    }
    
    public function down(): void
    {
        Schema::table('despatches', function (Blueprint $table) {
            $table->dropColumn([
                'cdr_pdf_url',
                'cdr_status',
                'cdr_description',
                'cdr_notes_count',
                'cdr_has_errors',
                'cdr_has_warnings',
                'cdr_issue_datetime',
                'cdr_error_codes',
                'cdr_base64_content',
                'cdr_metadata'
            ]);
        });
    }
};
