<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use CodersFree\LaravelGreenter\Facades\Greenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreditNoteController extends Controller
{
    /**
     * Crear nota de crédito desde una factura existente en BD
     *
     * Ruta: Route::get('/crear-nota-credito/{invoiceId}', [CreditNoteController::class, 'createFromInvoice']);
     */
    public function createFromInvoice($invoiceId)
    {
        try {
            $invoice = Invoice::with('items')->findOrFail($invoiceId);

            if (!$invoice->sunat_accepted) {
                return response()->json([
                    'success' => false,
                    'message' => 'La factura no está aceptada en SUNAT'
                ], 400);
            }

            // Generar datos de la nota de crédito
            $data = [
                'ublVersion' => '2.1',
                'tipoDoc' => '07', // Nota de Crédito
                'serie' => 'FC01', // Cambia según tu serie de notas de crédito
                'correlativo' => (string)$this->getNextCorrelativo('FC01'),
                'fechaEmision' => now(),
                'tipDocAfectado' => $invoice->invoice_type, // '01' = Factura
                'numDocfectado' => $invoice->series . '-' . $invoice->number,
                'codMotivo' => '01', // 01 = Anulación de la operación
                'desMotivo' => 'ANULACIÓN DE LA OPERACIÓN',
                'tipoMoneda' => $invoice->currency,

                'company' => [
                    'ruc' => config('greenter.company.ruc'),
                    'razonSocial' => config('greenter.company.razonSocial'),
                    'nombreComercial' => config('greenter.company.nombreComercial'),
                    'address' => config('greenter.company.address'),
                ],

                'client' => [
                    'tipoDoc' => $invoice->client->document_type ?? '6',
                    'numDoc' => $invoice->client->document_number ?? '00000000',
                    'rznSocial' => $invoice->client->name ?? 'CLIENTE',
                ],

                'mtoOperGravadas' => (float)$invoice->total_taxable,
                'mtoIGV' => (float)$invoice->total_igv,
                'totalImpuestos' => (float)$invoice->total_igv,
                'mtoImpVenta' => (float)$invoice->total,

                'details' => $this->buildDetails($invoice),

                'legends' => [
                    [
                        'code' => '1000',
                        'value' => $this->numberToWords($invoice->total)
                    ]
                ]
            ];

            Log::info("Enviando nota de crédito para factura {$invoice->id}", $data);

            // Configurar credenciales de NubeFact OSE temporalmente
            $originalUser = config('greenter.company.clave_sol.user');
            $originalPass = config('greenter.company.clave_sol.password');

            config([
                'greenter.company.clave_sol.user' => config('greenter.company.nubefact_ose.user'),
                'greenter.company.clave_sol.password' => config('greenter.company.nubefact_ose.password'),
            ]);

            try {
                // Enviar usando Greenter
                $response = Greenter::send('note', $data);

                Log::info("Respuesta de Greenter", [
                    'has_cdr' => property_exists($response, 'cdrResponse') && $response->cdrResponse !== null,
                    'response_type' => get_class($response)
                ]);

                // Verificar si tiene CDR (respuesta de SUNAT)
                if (property_exists($response, 'cdrResponse') && $response->cdrResponse) {
                    Log::info("Nota de crédito enviada correctamente", [
                        'code' => $response->cdrResponse->code,
                        'description' => $response->cdrResponse->description
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => '✅ Nota de crédito generada y enviada correctamente',
                        'serie' => 'FC01',
                        'correlativo' => $data['correlativo'],
                        'factura_anulada' => $invoice->series . '-' . $invoice->number,
                        'sunat_code' => $response->cdrResponse->code,
                        'sunat_description' => $response->cdrResponse->description
                    ]);
                }

                // Si no hay CDR, verificar el contenido de la respuesta
                Log::error("Respuesta sin CDR", [
                    'response_vars' => get_object_vars($response)
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se recibió confirmación de SUNAT',
                    'debug' => get_object_vars($response)
                ], 500);

            } finally {
                // Restaurar credenciales originales
                config([
                    'greenter.company.clave_sol.user' => $originalUser,
                    'greenter.company.clave_sol.password' => $originalPass,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Error al crear nota de crédito: " . $e->getMessage());
            Log::error("Línea: " . $e->getLine());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la nota de crédito',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Crear nota de crédito manualmente (sin BD)
     *
     * Ruta: Route::get('/crear-nota-credito-manual', [CreditNoteController::class, 'createManual']);
     *
     * Parámetros:
     * - factura_tipo: 01
     * - factura_serie: F001
     * - factura_numero: 9901
     * - total: 1538.72
     * - motivo: ANULACIÓN DE LA OPERACIÓN (opcional)
     */
    public function createManual(Request $request)
    {
        try {
            $request->validate([
                'factura_tipo' => 'required|in:01,03',
                'factura_serie' => 'required|string',
                'factura_numero' => 'required|string',
                'total' => 'required|numeric',
                'motivo' => 'nullable|string'
            ]);

            $facturaTipo = $request->get('factura_tipo');
            $facturaSerie = $request->get('factura_serie');
            $facturaNumero = $request->get('factura_numero');
            $total = (float)$request->get('total');
            $motivo = $request->get('motivo', 'ANULACIÓN DE LA OPERACIÓN');

            // Calcular IGV (18%)
            $totalSinIgv = round($total / 1.18, 2);
            $igv = round($total - $totalSinIgv, 2);

            // Generar datos de la nota de crédito
            $correlativo = $this->getNextCorrelativo('FC01');

            $data = [
                'ublVersion' => '2.1',
                'tipoDoc' => '07',
                'serie' => 'FC01',
                'correlativo' => (string)$correlativo,
                'fechaEmision' => now(),
                'tipDocAfectado' => $facturaTipo,
                'numDocfectado' => $facturaSerie . '-' . $facturaNumero,
                'codMotivo' => '01', // 01 = Anulación de la operación
                'desMotivo' => $motivo,
                'tipoMoneda' => 'PEN',

                'company' => [
                    'ruc' => config('greenter.company.ruc'),
                    'razonSocial' => config('greenter.company.razonSocial'),
                    'nombreComercial' => config('greenter.company.nombreComercial'),
                    'address' => config('greenter.company.address'),
                ],

                'client' => [
                    'tipoDoc' => '6',
                    'numDoc' => '20330791501',
                    'rznSocial' => 'QUIMPAC SA',
                ],

                'mtoOperGravadas' => $totalSinIgv,
                'mtoIGV' => $igv,
                'totalImpuestos' => $igv,
                'mtoImpVenta' => $total,

                'details' => [
                    [
                        'codProducto' => 'SERV001',
                        'unidad' => 'ZZ',
                        'cantidad' => 1,
                        'descripcion' => 'ANULACIÓN DE FACTURA ' . $facturaSerie . '-' . $facturaNumero,
                        'mtoBaseIgv' => $totalSinIgv,
                        'porcentajeIgv' => 18.00,
                        'igv' => $igv,
                        'tipAfeIgv' => '10',
                        'totalImpuestos' => $igv,
                        'mtoValorVenta' => $totalSinIgv,
                        'mtoValorUnitario' => $totalSinIgv,
                        'mtoPrecioUnitario' => $total,
                    ]
                ],

                'legends' => [
                    [
                        'code' => '1000',
                        'value' => $this->numberToWords($total)
                    ]
                ]
            ];

            Log::info("Enviando nota de crédito manual", [
                'factura' => $facturaSerie . '-' . $facturaNumero,
                'correlativo' => $correlativo
            ]);

            // Configurar credenciales de NubeFact OSE temporalmente
            $originalUser = config('greenter.company.clave_sol.user');
            $originalPass = config('greenter.company.clave_sol.password');

            config([
                'greenter.company.clave_sol.user' => config('greenter.company.nubefact_ose.user'),
                'greenter.company.clave_sol.password' => config('greenter.company.nubefact_ose.password'),
            ]);

            try {
                // Enviar usando Greenter
                $response = Greenter::send('note', $data);

                Log::info("Respuesta de Greenter recibida", [
                    'class' => get_class($response),
                    'methods' => get_class_methods($response)
                ]);

                // Intentar obtener CDR usando diferentes métodos posibles
                $cdr = null;
                if (method_exists($response, 'getCdrResponse')) {
                    $cdr = $response->getCdrResponse();
                } elseif (method_exists($response, 'cdrResponse')) {
                    $cdr = $response->cdrResponse();
                }

                if ($cdr) {
                    Log::info("Nota de crédito manual enviada correctamente", [
                        'code' => $cdr->code ?? $cdr->getCode(),
                        'description' => $cdr->description ?? $cdr->getDescription()
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => '✅ Nota de crédito generada y enviada correctamente',
                        'nota_credito' => 'FC01-' . $correlativo,
                        'factura_anulada' => $facturaSerie . '-' . $facturaNumero,
                        'total' => $total,
                        'sunat_code' => $cdr->code ?? $cdr->getCode(),
                        'sunat_description' => $cdr->description ?? $cdr->getDescription()
                    ]);
                }

                // Si no hay CDR
                Log::error("No se pudo obtener CDR de la respuesta");

                return response()->json([
                    'success' => false,
                    'message' => 'No se recibió confirmación de SUNAT',
                    'available_methods' => get_class_methods($response)
                ], 500);

            } finally {
                // Restaurar credenciales originales
                config([
                    'greenter.company.clave_sol.user' => $originalUser,
                    'greenter.company.clave_sol.password' => $originalPass,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Error al crear nota de crédito manual: " . $e->getMessage());
            Log::error("Línea: " . $e->getLine());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la nota de crédito',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Construir detalles desde los items de la factura
     */
    private function buildDetails($invoice)
    {
        $details = [];

        foreach ($invoice->items as $item) {
            $details[] = [
                'codProducto' => $item->code ?? 'PROD001',
                'unidad' => $item->unit ?? 'NIU',
                'cantidad' => (float)$item->quantity,
                'descripcion' => $item->description,
                'mtoBaseIgv' => (float)$item->subtotal,
                'porcentajeIgv' => 18.00,
                'igv' => (float)$item->igv,
                'tipAfeIgv' => '10',
                'totalImpuestos' => (float)$item->igv,
                'mtoValorVenta' => (float)$item->subtotal,
                'mtoValorUnitario' => (float)$item->unit_price,
                'mtoPrecioUnitario' => (float)($item->unit_price * 1.18),
            ];
        }

        return $details;
    }

    /**
     * Obtener el siguiente correlativo para la serie
     */
    private function getNextCorrelativo($serie)
    {
        // Aquí deberías implementar tu lógica para obtener el siguiente número
        // Por ahora retorno un número aleatorio
        return rand(1, 9999);
    }

    /**
     * Convertir número a palabras (simplificado)
     */
    private function numberToWords($number)
    {
        $formatter = new \NumberFormatter('es', \NumberFormatter::SPELLOUT);
        $words = $formatter->format(floor($number));
        $decimals = round(($number - floor($number)) * 100);

        return strtoupper($words) . ' CON ' . str_pad($decimals, 2, '0', STR_PAD_LEFT) . '/100 SOLES';
    }
}
