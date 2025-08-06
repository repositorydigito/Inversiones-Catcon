<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/login');
});

Route::get('/test-gre', [App\Http\Controllers\GreenterTestController::class, 'testGRE']);

// Factura (que ya funciona)
Route::get('/test-factura', [App\Http\Controllers\GreenterTestController::class, 'testFactura']);

// GRE Remitente
Route::get('/test-gre-remitente', [App\Http\Controllers\GreenterTestController::class, 'testGRERemitente']);

// GRE Transportista
Route::get('/test-gre-transportista', [App\Http\Controllers\GreenterTestController::class, 'testGRETransportista']);
