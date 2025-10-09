<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use CodersFree\LaravelGreenter\Facades\Greenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VoidInvoiceController extends Controller
{
    /**
     * Anular una factura específica desde la BD local
     */
    public function voidInvoice($invoiceId)
    {
        try {
            $invoice = Invoice::findOrFail($invoiceId);

            if (!$invoice->sunat_accepted) {
                return response()->json([
                    'success' => false,
                    'message' => 'La factura no está aceptada en SUNAT'
                ], 400);
            }

            if (!$invoice->series || !$invoice->number) {
                return response()->json([
                    'success' => false,
                    'message' => 'La factura no tiene serie o número'
                ], 400);
            }

            // Generar correlativo único
            $correlativo = str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // Crear detalle de baja
            $detail = new VoidedDetail();
            $detail->setTipoDoc($invoice->invoice_type)
                ->setSerie($invoice->series)
                ->setCorrelativo((string)$invoice->number)
                ->setDesMotivoBaja('ERROR EN EMISIÓN');

            // Crear comunicación de baja
            $voided = new Voided();
            $voided->setCorrelativo($correlativo)
                ->setFecGeneracion(new \DateTime($invoice->emission_date->format('Y-m-d')))
                ->setFecComunicacion(new \DateTime())
                ->setCompany($this->getCompany())
                ->setDetails([$detail]);

            Log::info("Enviando baja de factura {$invoice->id}", [
                'serie' => $invoice->series,
                'numero' => $invoice->number,
                'correlativo' => $correlativo
            ]);

            // Obtener servicios configurados
            $sender = $this->getSummarySender();

            // Enviar a SUNAT
            $result = $sender->send($voided);

            if (!$result->isSuccess()) {
                $error = $result->getError();
                Log::error("Error al enviar baja", [
                    'code' => $error->getCode(),
                    'message' => $error->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error al enviar la baja',
                    'error_code' => $error->getCode(),
                    'error_message' => $error->getMessage()
                ], 500);
            }

            // Obtener ticket
            $ticket = $result->getTicket();

            $invoice->update([
                'sunat_note' => 'Baja enviada - Ticket: ' . $ticket,
                'sunat_description' => 'Proceso de anulación iniciado'
            ]);

            Log::info("Baja enviada correctamente", ['ticket' => $ticket]);

            return response()->json([
                'success' => true,
                'message' => '✅ Comunicación de baja enviada correctamente',
                'ticket' => $ticket,
                'invoice_id' => $invoice->id,
                'correlativo' => $correlativo,
                'nota' => 'Guarda el TICKET para consultar el estado',
                'consultar_en' => url("/consultar-ticket-baja/{$ticket}")
            ]);

        } catch (\Exception $e) {
            Log::error("Excepción al anular factura {$invoiceId}: " . $e->getMessage());
            Log::error("Línea: " . $e->getLine());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la anulación',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ], 500);
        }
    }

    /**
     * Anular factura manualmente (sin BD local)
     *
     * Ejemplo: /anular-factura-manual?tipo_doc=01&serie=F001&numero=9901&fecha_emision=2025-10-06
     *
     * IMPORTANTE: También sirve para dar de baja NOTAS DE CRÉDITO (tipo_doc=07)
     * Ejemplo: /anular-factura-manual?tipo_doc=07&serie=FC01&numero=6091&fecha_emision=2025-10-09
     */
    public function voidInvoiceManual(Request $request)
    {
        try {
            $request->validate([
                'tipo_doc' => 'required|in:01,03,07,08',
                'serie' => 'required|string',
                'numero' => 'required|string',
                'fecha_emision' => 'required|date',
                'motivo' => 'nullable|string'
            ]);

            $tipoDoc = $request->get('tipo_doc');
            $serie = $request->get('serie');
            $numero = $request->get('numero');
            $fechaEmision = $request->get('fecha_emision');
            $motivo = $request->get('motivo', 'ERROR EN EMISIÓN');

            // Generar correlativo único (solo números, de 1 a 5 dígitos)
            $correlativo = (string)rand(1, 99999);

            // Crear detalle de baja
            $detail = new VoidedDetail();
            $detail->setTipoDoc($tipoDoc)
                ->setSerie($serie)
                ->setCorrelativo($numero)
                ->setDesMotivoBaja($motivo);

            // Crear comunicación de baja
            $voided = new Voided();
            $voided->setCorrelativo($correlativo)
                ->setFecGeneracion(new \DateTime($fechaEmision))
                ->setFecComunicacion(new \DateTime())
                ->setCompany($this->getCompany())
                ->setDetails([$detail]);

            Log::info("Enviando baja manual", [
                'tipo_doc' => $tipoDoc,
                'serie' => $serie,
                'numero' => $numero,
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
                // Enviar usando Greenter Facade con los campos correctos
                $result = Greenter::send('voided', [
                    'correlativo' => $correlativo,
                    'fecGeneracion' => $fechaEmision,
                    'fecComunicacion' => now()->format('Y-m-d'),
                    'details' => [
                        [
                            'tipoDoc' => $tipoDoc,
                            'serie' => $serie,
                            'correlativo' => $numero,
                            'desMotivoBaja' => $motivo,
                        ]
                    ]
                ]);

                // Verificar si hay ticket
                if (method_exists($result, 'getTicket') && $result->getTicket()) {
                    $ticket = $result->getTicket();

                    Log::info("Baja manual enviada correctamente", [
                        'ticket' => $ticket,
                        'documento' => "{$serie}-{$numero}"
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => '✅ Comunicación de baja enviada correctamente',
                        'ticket' => $ticket,
                        'documento' => "{$serie}-{$numero}",
                        'correlativo' => $correlativo,
                        'nota' => 'Guarda el TICKET. La baja puede tardar unos minutos en procesarse.',
                        'consultar_en' => url("/consultar-ticket-baja/{$ticket}")
                    ]);
                }

                Log::error("No se obtuvo ticket de la respuesta");

                return response()->json([
                    'success' => false,
                    'message' => 'No se obtuvo ticket de SUNAT',
                    'available_methods' => get_class_methods($result)
                ], 500);

            } finally {
                // Restaurar credenciales originales
                config([
                    'greenter.company.clave_sol.user' => $originalUser,
                    'greenter.company.clave_sol.password' => $originalPass,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Excepción al anular factura manual: " . $e->getMessage());
            Log::error("Línea: " . $e->getLine());

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la anulación',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ], 500);
        }
    }

    /**
     * Consultar el estado del ticket de baja
     */
    public function checkTicketStatus($ticket)
    {
        try {
            Log::info("Consultando ticket: {$ticket}");

            // Configurar credenciales de NubeFact OSE temporalmente
            $originalUser = config('greenter.company.clave_sol.user');
            $originalPass = config('greenter.company.clave_sol.password');

            config([
                'greenter.company.clave_sol.user' => config('greenter.company.nubefact_ose.user'),
                'greenter.company.clave_sol.password' => config('greenter.company.nubefact_ose.password'),
            ]);

            try {
                $result = Greenter::getStatus($ticket);

                if (method_exists($result, 'getCdrResponse') && $result->getCdrResponse()) {
                    $cdr = $result->getCdrResponse();

                    Log::info("Ticket {$ticket} consultado exitosamente", [
                        'code' => $cdr->code ?? 'N/A',
                        'description' => $cdr->description ?? 'N/A'
                    ]);

                    return response()->json([
                        'success' => true,
                        'status' => 'accepted',
                        'ticket' => $ticket,
                        'code' => $cdr->code ?? 'N/A',
                        'description' => $cdr->description ?? 'N/A',
                        'notes' => $cdr->notes ?? []
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'status' => 'pending',
                    'ticket' => $ticket,
                    'message' => 'El ticket aún está en proceso. Intenta en unos minutos.'
                ]);

            } finally {
                // Restaurar credenciales originales
                config([
                    'greenter.company.clave_sol.user' => $originalUser,
                    'greenter.company.clave_sol.password' => $originalPass,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Error al consultar ticket {$ticket}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener datos de la empresa
     */
    private function getCompany()
    {
        $config = config('greenter.company');

        $address = new Address();
        $address->setUbigueo($config['address']['ubigeo'])
            ->setDepartamento($config['address']['departamento'])
            ->setProvincia($config['address']['provincia'])
            ->setDistrito($config['address']['distrito'])
            ->setDireccion($config['address']['direccion']);

        $company = new Company();
        $company->setRuc($config['ruc'])
            ->setRazonSocial($config['razonSocial'])
            ->setNombreComercial($config['nombreComercial'])
            ->setAddress($address);

        return $company;
    }
}
