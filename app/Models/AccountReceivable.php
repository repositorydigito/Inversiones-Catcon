<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon; 

class AccountReceivable extends Model
{
    protected $fillable = [
        'invoice_id',
        'client_id', 
        'total_amount',
        'emission_date',
        'due_date',
        'days_elapsed',
        'pay_date',
        'pay_mode',
        'status',
        'notes',
    ];

    protected $casts = [
        'emission_date' => 'date',
        'due_date' => 'date',
        'pay_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    // Relaciones
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    // Cálculo simple de días transcurridos
    public function getDaysElapsedAttribute(): int
    {
        return $this->emission_date->diffInDays(Carbon::now());
    }

    // Color del badge basado en pay_days del cliente
    public function getBadgeColorAttribute(): string
    {
        if ($this->status === 'pagado') {
            return 'success';
        }

        $clientPayDays = $this->client->days_to_pay ?? null;
        $daysElapsed = $this->days_elapsed;
        
        // Últimos 7 días antes de vencer = rojo
        if ($daysElapsed >= ($clientPayDays - 7)) {
            return 'danger';
        }
        
        return 'success';
    }

    public function getInvoiceNumberAttribute(): string
    {
        return $this->invoice ? "{$this->invoice->series}-{$this->invoice->number}" : 'N/A';
    }    
}
