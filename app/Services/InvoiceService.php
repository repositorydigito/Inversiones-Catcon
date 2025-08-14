<?php

namespace App\Services;

use CodersFree\LaravelGreenter\Facades\Greenter;
use App\Models\Invoice;

class InvoiceService
{
    public function sendToNubefact(Invoice $invoice): array
    {
        // Construye el array $data con los datos del modelo Invoice
        $data = [
            "ublVersion" => "2.1",
            "tipoOperacion" => "0101",
            "tipoDoc" => $invoice->invoice_type ?? "01",
            "serie" => $invoice->series,
            "correlativo" => $invoice->number,
            "fechaEmision" => $invoice->emission_date,
            "formaPago" => ['tipo' => $invoice->is_credit_payment ? 'Credito' : 'Contado'],
            "tipoMoneda" => $invoice->currency == '2' ? 'USD' : 'PEN',
            "company" => [
                "ruc" => config('greenter.company.ruc'),
                "razonSocial" => config('greenter.company.razonSocial'),
                "nombreComercial" => config('greenter.company.nombreComercial'),
                "address" => config('greenter.company.address'),
            ],
            "client" => [
                "tipoDoc" => $invoice->client_document_type,
                "numDoc" => $invoice->client_document_number,
                "rznSocial" => $invoice->client_name,
            ],
            "mtoOperGravadas" => $invoice->total_taxable,
            "mtoIGV" => $invoice->total_igv,
            "totalImpuestos" => $invoice->total_igv,
            "valorVenta" => $invoice->total_taxable,
            "subTotal" => $invoice->total,
            "mtoImpVenta" => $invoice->total,
            "details" => collect($invoice->items)->map(function ($item) {
                return [
                    "codProducto" => $item['code'] ?? '',
                    "unidad" => $item['unit_of_measure_id'] ?? 'NIU',
                    "cantidad" => $item['quantity'],
                    "mtoValorUnitario" => $item['unit_value'],
                    "descripcion" => $item['description'],
                    "mtoBaseIgv" => $item['subtotal'],
                    "porcentajeIgv" => 18.00,
                    "igv" => $item['igv'],
                    "tipAfeIgv" => "10",
                    "totalImpuestos" => $item['igv'],
                    "mtoValorVenta" => $item['subtotal'],
                    "mtoPrecioUnitario" => $item['unit_price'],
                ];
            })->toArray(),
            "legends" => [
                [
                    "code" => "1000",
                    "value" => "SON CIENTO DIECIOCHO CON 00/100 SOLES",
                ],
            ],
        ];

        // Enviar a Nubefact/Greenter
        $response = Greenter::send('invoice', $data);

        return [
            'success' => true,
            'response' => $response,
        ];
    }
}