<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceInstallment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'installment_number',
        'amount',
        'due_date',
        'order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
        'order' => 'integer',
    ];

    /**
     * Relación con la factura
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Scope para ordenar por número de cuota
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
