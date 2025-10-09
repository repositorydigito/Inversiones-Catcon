<?php

use Illuminate\Support\Facades\Route;
use App\Services\InvoicePdfService;
use App\Models\Invoice;
use App\Http\Controllers\VoidInvoiceController;
use App\Http\Controllers\CreditNoteController;

Route::get('/', function () {
    return redirect('/admin/login');
});

// Nueva ruta para generar PDF de facturas
Route::get('/invoice/{invoice}/pdf', function (Invoice $invoice) {
    return app(InvoicePdfService::class)->generateInvoicePdf($invoice);
})->name('invoice.pdf');

Route::get('/test-gre', [App\Http\Controllers\GreenterTestController::class, 'testGRE']);

// Factura (que ya funciona)
Route::get('/test-factura', [App\Http\Controllers\GreenterTestController::class, 'testFactura']);

// GRE Remitente
Route::get('/test-gre-remitente', [App\Http\Controllers\GreenterTestController::class, 'testGRERemitente']);

// GRE Transportista
Route::get('/test-gre-transportista', [App\Http\Controllers\GreenterTestController::class, 'testGRETransportista']);

Route::get('/test-gre-sunat', [App\Http\Controllers\GreenterTestController::class, 'testGRERemitenteSunat']);

Route::get('/debug-basico', [App\Http\Controllers\GreenterTestController::class, 'debugBasico']);

Route::get('/test-gre-transportista-sunat', [App\Http\Controllers\GreenterTestController::class, 'testGRETransportistaSunat']);

// Anular factura específica
Route::get('/anular-factura/{id}', [VoidInvoiceController::class, 'voidInvoice']);

// Consultar estado del ticket de baja
Route::get('/consultar-ticket-baja/{ticket}', [VoidInvoiceController::class, 'checkTicketStatus']);

// Anular factura manualmente (sin BD local)
Route::get('/anular-factura-manual', [VoidInvoiceController::class, 'voidInvoiceManual']);

// Crear nota de crédito desde factura en BD
Route::get('/crear-nota-credito/{invoiceId}', [CreditNoteController::class, 'createFromInvoice']);

// Crear nota de crédito manualmente
Route::get('/crear-nota-credito-manual', [CreditNoteController::class, 'createManual']);
