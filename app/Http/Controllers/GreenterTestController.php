<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use CodersFree\LaravelGreenter\Facades\Greenter;
use Illuminate\Support\Facades\Storage;

class GreenterTestController extends Controller
{
    // Factura que ya funciona
    public function testFactura()
    {
        try {
            $data = [
                "ublVersion" => "2.1",
                "tipoOperacion" => "0101",
                "tipoDoc" => "01", // FACTURA
                "serie" => "F001",
                "correlativo" => "2",
                "fechaEmision" => now(),
                "formaPago" => ['tipo' => 'Contado'],
                "tipoMoneda" => "PEN",
                "company" => [
                    "ruc" => "20601921023",
                    "razonSocial" => "INVERSIONES CATCON S.A.C.",
                    "nombreComercial" => "INVERSIONES CATCON S.A.C.",
                    "address" => [
                        "ubigueo" => "150101",
                        "departamento" => "LIMA",
                        "provincia" => "LIMA",
                        "distrito" => "LIMA",
                        "direccion" => "Cal. German Schreiber 276 Urb. Santa Ana"
                    ]
                ],
                "client" => [
                    "tipoDoc" => "6",
                    "numDoc" => "20000000001",
                    "rznSocial" => "EMPRESA CLIENTE S.A.C.",
                ],
                "mtoOperGravadas" => 100.00,
                "mtoIGV" => 18.00,
                "totalImpuestos" => 18.00,
                "valorVenta" => 100.00,
                "subTotal" => 118.00,
                "mtoImpVenta" => 118.00,
                "details" => [
                    [
                        "codProducto" => "P001",
                        "unidad" => "NIU",
                        "cantidad" => 2,
                        "mtoValorUnitario" => 50.00,
                        "descripcion" => "PRODUCTO DE PRUEBA",
                        "mtoBaseIgv" => 100,
                        "porcentajeIgv" => 18.00,
                        "igv" => 18.00,
                        "tipAfeIgv" => "10",
                        "totalImpuestos" => 18.00,
                        "mtoValorVenta" => 100.00,
                        "mtoPrecioUnitario" => 59.00,
                    ],
                ],
                "legends" => [
                    [
                        "code" => "1000",
                        "value" => "SON CIENTO DIECIOCHO CON 00/100 SOLES",
                    ],
                ],
            ];

            $response = Greenter::send('invoice', $data);

            return response()->json([
                'success' => true,
                'message' => 'Factura generada exitosamente',
                'data' => ['serie' => 'F001', 'numero' => 1]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // GRE REMITENTE - VERSION ULTRA SIMPLE
    public function testGRERemitente()
    {
        try {
            // Estructura mínima basada en la factura que funciona
            $data = [
                "version" => "2022",
                "tipoDoc" => "09",
                "serie" => "T001",
                "correlativo" => "2",
                "fechaEmision" => now(),
                "company" => [
                    "ruc" => "20601921023",
                    "razonSocial" => "INVERSIONES CATCON S.A.C.",
                    "nombreComercial" => "INVERSIONES CATCON S.A.C.",
                    "address" => [
                        "ubigueo" => "150101",
                        "departamento" => "LIMA",
                        "provincia" => "LIMA",
                        "distrito" => "LIMA",
                        "direccion" => "Cal. German Schreiber 276 Urb. Santa Ana"
                    ]
                ],
                "destinatario" => [
                    "tipoDoc" => "6",
                    "numDoc" => "20200030002",
                    "rznSocial" => "EMPRESA DESTINATARIO S.A.C."
                ],
                "envio" => [
                    "codTraslado" => "01",
                    "modTraslado" => "01",
                    "fechaTraslado" => now()->addDay(),
                    "pesoTotal" => 100.00,
                    "undPesoTotal" => 'KGM',
                    "llegada" => [
                        "ubigueo" => "150203",
                        "direccion" => "Av. Calle Falsa 221",
                    ],
                    "partida" => [
                        "ubigueo" => "150101",
                        "direccion" => "Av. Calle Falsa 145",
                    ],
                    "transportista" => [
                        "tipoDoc" => "6",
                        "numDoc" => 20200030002,
                        "rznSocial" => "TRANSPORTISTA S.A.C.",
                        "nroMtc" => "0001",
                    ]
                ],
                "details" => [
                    [
                        "cantidad" => 10,
                        "unidad" => "ZZ",
                        "descripcion" => "SERVICIO DE PRUEBA REMITENTE",
                        "codigo" => "R001"
                    ]
                ]
            ];

            $response = Greenter::send('despatch', $data);

            return response()->json([
                'success' => true,
                'message' => 'GRE REMITENTE ultra simple generada',
            ]);

        } catch (\Throwable $e) {
            $extra = [];
            // Si la excepción tiene un método getResponse(), lo incluimos
            if (method_exists($e, 'getResponse')) {
                $extra['greenter_response'] = $e->getResponse();
            } elseif (property_exists($e, 'response')) {
                $extra['greenter_response'] = $e->response;
            }
            return response()->json([
                'success' => false,
                'message' => 'Error en GRE Remitente ultra simple',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTrace(),
            ] + $extra, 500);
        }
    }

    // GRE TRANSPORTISTA - VERSION ULTRA SIMPLE
    public function testGRETransportista()
    {
        try {
            // Estructura mínima basada en la factura que funciona
            $data = [
                "ublVersion" => "2.1",
                "tipoDoc" => "8", // GRE TRANSPORTISTA (Según doc Nubefact)
                "serie" => "V001",
                "correlativo" => "1",
                "fechaEmision" => now(),
                "company" => [
                    "ruc" => "20601921023",
                    "razonSocial" => "INVERSIONES CATCON S.A.C.",
                    "nombreComercial" => "INVERSIONES CATCON S.A.C.",
                ],
                "client" => [ // Usar 'client' como en factura
                    "tipoDoc" => "6",
                    "numDoc" => 20200030002,
                    "rznSocial" => "EMPRESA CLIENTE S.A.C."
                ],
                "details" => [
                    [
                        "cantidad" => 15,
                        "unidad" => "ZZ",
                        "descripcion" => "SERVICIO DE PRUEBA TRANSPORTISTA"
                    ]
                ]
            ];

            $response = Greenter::send('despatch', $data);

            return response()->json([
                'success' => true,
                'message' => 'GRE TRANSPORTISTA ultra simple generada',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en GRE Transportista ultra simple',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }
}
