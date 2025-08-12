<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DespatchRelatedDocument extends Model
{
    protected $fillable = [
        'despatch_id',
        'document_type',
        'series',
        'number',
    ];

    // Relación inversa con la guía de remisión
    public function despatch()
    {
        return $this->belongsTo(Despatch::class);
    }

    /**
     * Obtiene el tipo de documento en formato legible
     */
    public function getDocumentTypeDescriptionAttribute(): string
    {
        return match ($this->document_type) {
            '09' => 'Guía de Remisión Remitente',
            '31' => 'Guía de Remisión Transportista',
            '01' => 'Factura',
            '03' => 'Boleta de Venta',
            '07' => 'Nota de Crédito',
            '08' => 'Nota de Débito',
            default => 'Documento ' . $this->document_type,
        };
    }

    /**
     * Obtiene el número completo del documento (serie-número)
     */
    public function getFullDocumentNumberAttribute(): string
    {
        return $this->series . '-' . str_pad($this->number, 8, '0', STR_PAD_LEFT);
    }
}
