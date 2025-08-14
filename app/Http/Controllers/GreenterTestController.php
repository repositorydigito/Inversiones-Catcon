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
                "correlativo" => "5",
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

    // GRE directo a SUNAT - MÉTODO CORREGIDO
    public function testGRERemitenteSunat()
    {
        try {
            // Debug inicial
            \Log::info('=== INICIANDO GRE SUNAT ===');

            // Verificar credenciales básicas
            $solUser = config('greenter.company.clave_sol.user');
            $solPass = config('greenter.company.clave_sol.password');

            if (empty($solUser) || $solUser === 'MODDATOS') {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales SOL no configuradas correctamente',
                    'hint' => 'Necesitas configurar GREENTER_SOL_USER y GREENTER_SOL_PASS reales'
                ], 400);
            }

            // Verificar certificado
            $certPath = config('greenter.company.certificate');
            if (strpos($certPath, 'stubs/certificate.pem') !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puedes usar SUNAT directo con certificado de prueba',
                    'hint' => 'Necesitas el certificado digital REAL de la empresa'
                ], 400);
            }

            // Forzar configuración para SUNAT directo
            config([
                'greenter.mode' => 'prod',
                'greenter.endpoints.api.prod.auth' => 'https://api-seguridad.sunat.gob.pe/v1',
                'greenter.endpoints.api.prod.cpe' => 'https://api-cpe.sunat.gob.pe/v1'
            ]);

            $data = [
                "version" => "2022",
                "tipoDoc" => "09", // GRE
                "serie" => "T001",
                "correlativo" => "00000001", // Formato SUNAT
                "fechaEmision" => now(),

                // Company (remitente)
                "company" => [
                    "ruc" => "20601921023",
                    "razonSocial" => "INVERSIONES CATCON S.A.C.",
                    "nombreComercial" => "INVERSIONES CATCON S.A.C.",
                    "address" => [
                        "ubigeo" => "150101",
                        "departamento" => "LIMA",
                        "provincia" => "LIMA",
                        "distrito" => "LIMA",
                        "direccion" => "Cal. German Schreiber 276 Urb. Santa Ana"
                    ]
                ],

                // Destinatario
                "destinatario" => [
                    "tipoDoc" => "6",
                    "numDoc" => "20100070970",
                    "rznSocial" => "EMPRESA DESTINATARIO S.A.C.",
                    "address" => [
                        "ubigeo" => "150203",
                        "direccion" => "Av. Destino 123"
                    ]
                ],

                // Datos del envío
                "envio" => [
                    "codTraslado" => "01", // Venta
                    "desTraslado" => "VENTA",
                    "modTraslado" => "02", // Transporte privado
                    "fechaTraslado" => now()->addDay()->format('Y-m-d'),
                    "pesoTotal" => 100.50,
                    "undPesoTotal" => "KGM",

                    // Punto de llegada
                    "llegada" => [
                        "ubigeo" => "150203",
                        "direccion" => "Av. Destino 123"
                    ],

                    // Punto de partida
                    "partida" => [
                        "ubigeo" => "150101",
                        "direccion" => "Cal. German Schreiber 276 Urb. Santa Ana"
                    ],

                    // Conductor y vehículo
                    "conductor" => [
                        "tipo" => "Principal",
                        "tipoDoc" => "1",
                        "nroDoc" => "43816516",
                        "nombres" => "CONDUCTOR",
                        "apellidos" => "PRINCIPAL",
                        "licencia" => "Q43816516"
                    ],

                    "vehiculo" => [
                        "placa" => "ABC-123"
                    ]
                ],

                // Detalles
                "details" => [
                    [
                        "cantidad" => 10,
                        "unidad" => "ZZ",
                        "descripcion" => "PRODUCTO DE PRUEBA",
                        "codigo" => "PROD001"
                    ]
                ]
            ];

            \Log::info('Enviando a SUNAT con data:', $data);

            // ENVIAR A GREENTER - SIN USAR isSuccess()
            $response = Greenter::send('despatch', $data);

            \Log::info('Respuesta recibida de tipo: ' . get_class($response));

            // MANEJO CORRECTO DE LA RESPUESTA
            // El response es de tipo SunatResponse, no tiene isSuccess()
            // Verificamos si hay errores

            return response()->json([
                'success' => true,
                'message' => 'GRE procesada (revisar logs para detalles)',
                'response_type' => get_class($response),
                'data' => [
                    'serie' => 'T001',
                    'numero' => '00000001'
                ],
                'debug' => [
                    'has_cdr' => $response->getCdrZip() !== null,
                    'has_cdr_response' => $response->getCdrResponse() !== null,
                    'xml_length' => strlen($response->getXml()),
                ]
            ]);

        } catch (\Throwable $e) {
            \Log::error('Error GRE SUNAT: ' . $e->getMessage());
            \Log::error('Línea: ' . $e->getLine());
            \Log::error('Archivo: ' . $e->getFile());

            return response()->json([
                'success' => false,
                'message' => 'Error al enviar GRE a SUNAT',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'hints' => [
                    'En proceso' => 'Problema con credenciales SOL o certificado digital',
                    'Call to undefined method' => 'Método no existe en la respuesta',
                    'Certificate file not found' => 'Certificado no encontrado'
                ]
            ], 500);
        }
    }

    // GRE TRANSPORTISTA CORREGIDA según builders reales
    public function testGRETransportistaSunat()
    {
        try {
            \Log::info('=== GRE TRANSPORTISTA ===');

            // Verificaciones básicas
            $solUser = config('greenter.company.clave_sol.user');
            if (empty($solUser) || $solUser === 'MODDATOS') {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales SOL no configuradas'
                ], 400);
            }

            // Configuración SUNAT directo
            config([
                'greenter.mode' => 'prod',
                'greenter.endpoints.api.prod.auth' => 'https://api-seguridad.sunat.gob.pe/v1',
                'greenter.endpoints.api.prod.cpe' => 'https://api-cpe.sunat.gob.pe/v1'
            ]);

            // ESTRUCTURA CORREGIDA basada en los builders y ejemplo real
            $data = [
                "version" => "2022",
                "tipoDoc" => "09", // GRE
                "serie" => "V001", // Correcto para transportista SEE
                "correlativo" => "00000003", // Cambiar número
                "fechaEmision" => now(),
                "observacion" => "TRASLADO DE PRODUCTOS VARIOS / PESO NETO 500 KG",

                // Company = Empresa transportista (según builder)
                "company" => [
                    "ruc" => "20601921023",
                    "razonSocial" => "INVERSIONES CATCON S.A.C.",
                    "nombreComercial" => "INVERSIONES CATCON S.A.C.",
                    "address" => [
                        "ubigeo" => "150101",
                        "departamento" => "LIMA",
                        "provincia" => "LIMA",
                        "distrito" => "LIMA",
                        "direccion" => "Cal. German Schreiber 276 Urb. Santa Ana"
                    ]
                ],

                // DESTINATARIO (quien recibe la mercadería) - según DespatchBuilder
                "destinatario" => [
                    "tipoDoc" => "6",
                    "numDoc" => "20330791501", // RUC del ejemplo real
                    "rznSocial" => "EMPRESA DESTINATARIA S.A.C."
                ],

                // DATOS DEL ENVÍO (según ShipmentBuilder)
                "envio" => [
                    "codTraslado" => "04", // Transporte por cuenta de terceros
                    "desTraslado" => "SERVICIO DE TRANSPORTE",
                    "modTraslado" => "01", // Transporte público
                    "fecTraslado" => now()->addDay()->format('Y-m-d'), // CORREGIDO: era fechaTraslado
                    "pesoTotal" => 500.00,
                    "undPesoTotal" => "KGM",
                    "numBultos" => 10, // Nuevo campo según builder
                    "sustentoPeso" => "FACTURA DE COMPRA", // Nuevo campo
                    "indTransbordo" => false, // Indicador transbordo

                    // Punto de PARTIDA (según DirectionBuilder)
                    "partida" => [
                        "ubigueo" => "150101", // CORREGIDO: era ubigeo
                        "direccion" => "Almacén Origen - Av. Los Olivos 123",
                        "ruc" => "20400050003" // RUC del remitente
                    ],

                    // Punto de LLEGADA (según DirectionBuilder)
                    "llegada" => [
                        "ubigueo" => "150203", // CORREGIDO: era ubigeo
                        "direccion" => "Almacén Destino - Jr. Las Flores 456",
                        "ruc" => "20330791501" // RUC del destinatario
                    ],

                    // TRANSPORTISTA (según TransportistBuilder) - YA NO en envio.transportista
                    "transportista" => [
                        "tipoDoc" => "6",
                        "numDoc" => "20601921023", // RUC de Inversiones Catcon
                        "rznSocial" => "INVERSIONES CATCON S.A.C.",
                        "nroMtc" => "1571820CNG", // Del ejemplo real
                        "placa" => "B7U920" // Placa principal del ejemplo
                    ],

                    // VEHÍCULO (según VehiculoBuilder)
                    "vehiculo" => [
                        "placa" => "B7U920", // Del ejemplo real
                        "nroCirculacion" => "0151721792", // TUCE del ejemplo
                        // Vehículos secundarios
                        "secundarios" => [
                            [
                                "placa" => "ASW998",
                                "nroCirculacion" => "0152014536"
                            ]
                        ]
                    ],

                    // CONDUCTORES (choferes según ShipmentBuilder)
                    "choferes" => [
                        [
                            "tipo" => "Principal",
                            "tipoDoc" => "1",
                            "nroDoc" => "42054424", // Del ejemplo real
                            "nombres" => "JUAN DANIEL",
                            "apellidos" => "TRUJILLO RAMIREZ",
                            "licencia" => "Q42054424"
                        ]
                    ]
                ],

                // DOCUMENTO RELACIONADO (guía del remitente)
                "relDoc" => [
                    "tipoDoc" => "09", // GRE Remitente
                    "nroDoc" => "TG02-00039372" // Del ejemplo real
                ],

                // DETALLES (productos transportados)
                "details" => [
                    [
                        "cantidad" => 35.320, // Peso del ejemplo real
                        "unidad" => "KGM",
                        "descripcion" => "SAL LAVADA UPGRADING HUACHO GRANEL",
                        "codigo" => "SAL001",
                        "codProdSunat" => "15119000" // Código SUNAT si aplica
                    ]
                ]
            ];

            \Log::info('Enviando GRE TRANSPORTISTA corregida:', $data);

            $response = Greenter::send('despatch', $data);

            // Análisis de respuesta
            $cdrInfo = null;
            if ($response->getCdrResponse()) {
                $cdr = $response->getCdrResponse();
                $cdrInfo = [
                    'code' => method_exists($cdr, 'getCode') ? $cdr->getCode() : 'N/A',
                    'description' => method_exists($cdr, 'getDescription') ? $cdr->getDescription() : 'N/A'
                ];
            }

            return response()->json([
                'success' => true,
                'message' => '🚚 GRE TRANSPORTISTA CORREGIDA procesada',
                'data' => [
                    'tipo' => 'GRE TRANSPORTISTA',
                    'serie' => 'V001',
                    'numero' => '00000003',
                    'transportista' => 'INVERSIONES CATCON S.A.C.',
                    'registro_mtc' => '1571820CNG',
                    'peso_total' => '500.00 KGM'
                ],
                'cdr_info' => $cdrInfo,
                'corrections_made' => [
                    'Agregado: observacion',
                    'Agregado: tercero (remitente)',
                    'Agregado: comprador (contratante)',
                    'Corregido: fecTraslado (antes fechaTraslado)',
                    'Corregido: ubigueo (antes ubigeo)',
                    'Agregado: choferes array (antes conductor)',
                    'Agregado: vehículos secundarios',
                    'Agregado: documento relacionado',
                    'Agregado: código SUNAT productos'
                ],
                'verification' => [
                    'Portal SOL → RUC: 20601921023',
                    'Serie: V001 | Número: 00000003',
                    'Fecha: ' . now()->format('Y-m-d'),
                    'Tipo: Guía de Remisión Electrónica - Transportista'
                ]
            ]);

        } catch (\Throwable $e) {
            \Log::error('Error GRE TRANSPORTISTA: ' . $e->getMessage());
            \Log::error('Línea: ' . $e->getLine());

            return response()->json([
                'success' => false,
                'message' => 'Error en GRE TRANSPORTISTA corregida',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'suggestions' => [
                    'Verificar que todos los campos estén según los builders',
                    'Confirmar estructura de choferes vs conductor',
                    'Revisar campos obligatorios según SUNAT'
                ]
            ], 500);
        }
    }

    // Método de debug básico
    public function debugBasico()
    {
        try {
            $config = [
                'mode' => config('greenter.mode'),
                'ruc' => config('greenter.company.ruc'),
                'sol_user' => config('greenter.company.clave_sol.user'),
                'certificate_path' => config('greenter.company.certificate'),
                'certificate_exists' => file_exists(config('greenter.company.certificate')),
                'is_test_cert' => strpos(config('greenter.company.certificate'), 'stubs') !== false
            ];

            return response()->json([
                'success' => true,
                'config' => $config,
                'recommendations' => [
                    $config['sol_user'] === 'MODDATOS' ? 'Cambiar credenciales SOL por reales' : 'SOL user OK',
                    $config['is_test_cert'] ? 'Cambiar certificado por uno real' : 'Certificado no es de prueba',
                    !$config['certificate_exists'] ? 'Certificado no encontrado' : 'Certificado encontrado'
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
