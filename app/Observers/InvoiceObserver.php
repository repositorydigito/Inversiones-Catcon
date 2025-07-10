<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\AccountReceivable;

class InvoiceObserver
{    
    public function created(Invoice $invoice): void
    {
        // Crear automáticamente una cuenta por cobrar cuando se crea una factura
        AccountReceivable::create([
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'total_amount' => $invoice->total,
            'emission_date' => $invoice->emission_date,
            'due_date' => $invoice->due_date, // Puede ser null
            'days_elapsed' => 0, // Se calculará automáticamente
            'status' => 'pendiente',
        ]);
    }
   
    public function deleted(Invoice $invoice): void
    {
        // Si se elimina una factura, eliminar también su cuenta por cobrar
        $invoice->accountReceivables()->delete();
    }
}