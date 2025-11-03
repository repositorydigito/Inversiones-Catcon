<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura {{ $invoice->series }}-{{ $invoice->number }}</title>
    <style>
        @page {
            margin: 1.5cm;
            size: A4;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 15px;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }

        .header {
            border: 2px solid #000;
        }

        .company-info {
            width: 65%;
            vertical-align: top;
            padding: 15px;
        }

        .invoice-info {
            width: 35%;
            text-align: center;
            background-color: #f8f8f8;
            padding: 15px;
        }

        .company-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .invoice-type {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .invoice-number {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .section-title {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #000;
        }

        .items-table th {
            background-color: #000;
            color: #fff;
            font-size: 13px;
        }

        .items-table td {
            font-size: 13px;
            text-align: center;
        }

        .items-table .desc {
            text-align: left;
        }

        .items-table .amount {
            text-align: right;
        }

        .totals-section {
            border: 2px solid #000;
        }

        .amount-words {
            width: 60%;
            padding: 15px;
            vertical-align: top;
        }

        .totals {
            width: 40%;
            padding: 0;
        }

        .total-row td:first-child {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: right;
        }

        .total-row td:last-child {
            text-align: right;
            font-weight: bold;
        }

        .total-final {
            background-color: #000;
            color: #fff;
            font-weight: bold;
        }

        .detraction-box {
            border: 2px solid #000;
            margin: 15px 0;
            background-color: #fff;
        }

        .detraction-title {
            font-weight: bold;
            text-align: center;
            font-size: 14px;
            padding: 10px;
            background-color: #f0f0f0;
            border-bottom: 1px solid #000;
        }

        .detraction-content {
            padding: 15px;
        }

        .detraction-row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }

        .detraction-label {
            display: table-cell;
            width: 35%;
            font-weight: bold;
            padding-right: 10px;
            vertical-align: top;
        }

        .detraction-value {
            display: table-cell;
            width: 65%;
            vertical-align: top;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
            border-top: 1px solid #000;
            padding-top: 10px;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
    </style>
</head>
<body>
    <!-- Header -->
    <table class="header">
        <tr>
            <td class="company-info">
                <div class="company-name">{{ $company['name'] }}</div>
                <div>{{ $company['address'] }}</div>
                <div>{{ $company['ubigeo'] }}</div>
                {{-- <div>Tel: {{ $company['phone'] }} | Email: {{ $company['email'] }}</div>
                @if(isset($company['website']))
                <div>{{ $company['website'] }}</div>
                @endif --}}
            </td>
            <td class="invoice-info">
                <div class="invoice-type">
                    @switch($invoice->invoice_type)
                        @case('01')
                            FACTURA ELECTRÓNICA
                            @break
                        @case('07')
                            NOTA DE CRÉDITO
                            @break
                        @case('08')
                            NOTA DE DÉBITO
                            @break
                        @default
                            COMPROBANTE ELECTRÓNICO
                    @endswitch
                </div>
                <div class="invoice-number">{{ $invoice->series }}-{{ str_pad($invoice->number, 8, '0', STR_PAD_LEFT) }}</div>
                <div>RUC: {{ $company['ruc'] }}</div>
            </td>
        </tr>
    </table>

    <!-- Client Information -->
    <div class="section-title">DATOS DEL CLIENTE</div>
    <table>
        <tr>
            <td style="width: 25%; background-color: #f0f0f0; font-weight: bold;">Cliente:</td>
            <td>{{ $invoice->client->name }}</td>
        </tr>
        <tr>
            <td style="background-color: #f0f0f0; font-weight: bold;">Documento:</td>
            <td>{{ strtoupper($invoice->client->document_type) }}: {{ $invoice->client->document_number }}</td>
        </tr>
        @if($invoice->client->address)
        <tr>
            <td style="background-color: #f0f0f0; font-weight: bold;">Dirección:</td>
            <td>{{ $invoice->client->address }}</td>
        </tr>
        @endif
        <tr>
            <td style="background-color: #f0f0f0; font-weight: bold;">Fecha Emisión:</td>
            <td>{{ $invoice->emission_date->format('d/m/Y') }}</td>
        </tr>
        @if($invoice->due_date)
        <tr>
            <td style="background-color: #f0f0f0; font-weight: bold;">Vencimiento:</td>
            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
        </tr>
        @endif
        <tr>
            <td style="background-color: #f0f0f0; font-weight: bold;">Moneda:</td>
            <td>{{ $invoice->currency === 'USD' ? 'DÓLARES AMERICANOS' : 'SOLES' }}</td>
        </tr>
    </table>

    <!-- Items -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 4%">ITEM</th>
                <th style="width: 10%">CÓDIGO</th>
                <th style="width: 32%">DESCRIPCIÓN</th>
                <th style="width: 6%">U.M.</th>
                <th style="width: 7%">CANT.</th>
                <th style="width: 11%">VALOR REF.</th>
                <th style="width: 10%">P. UNIT.</th>
                <th style="width: 10%">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-center">{{ $item->code ?: '-' }}</td>
                <td class="desc">{{ $item->description }}</td>
                <td class="text-center">{{ $item->unitOfMeasure->code ?? 'UND' }}</td>
                <td class="text-center">{{ number_format($item->quantity, 2) }}</td>
                <td class="amount">{{ $currency_symbol }} {{ number_format($item->reference_value ?? 0, 2) }}</td>
                <td class="amount">{{ $currency_symbol }} {{ number_format($item->unit_value, 2) }}</td>
                <td class="amount">{{ $currency_symbol }} {{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals -->
    <table class="totals-section">
        <tr>
            <td class="amount-words">
                <strong>Son:</strong><br>
                {{ $amount_in_words }}

                @if($invoice->observations)
                <div style="margin-top: 15px; padding: 8px; border: 1px solid #000; background-color: #f0f0f0;">
                    <strong>Observaciones:</strong><br>
                    {{ $invoice->observations }}
                </div>
                @endif
            </td>
            <td class="totals">
                <table style="margin: 0;">
                    <tr class="total-row">
                        <td>Op. Gravadas:</td>
                        <td>{{ $currency_symbol }} {{ number_format($invoice->total_taxable ?? 0, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Op. Inafectas:</td>
                        <td>{{ $currency_symbol }} {{ number_format($invoice->total_unaffected ?? 0, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Op. Exoneradas:</td>
                        <td>{{ $currency_symbol }} {{ number_format($invoice->total_exonerated ?? 0, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Descuentos:</td>
                        <td>{{ $currency_symbol }} {{ number_format($invoice->total_discount ?? 0, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>I.G.V. ({{ $invoice->igv_percentage ?? 18 }}%):</td>
                        <td>{{ $currency_symbol }} {{ number_format($invoice->total_igv ?? 0, 2) }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>TOTAL:</td>
                        <td>{{ $currency_symbol }} {{ number_format($invoice->total, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Detraction -->
    @if($detraction)
    <div class="detraction-box">
        <div class="detraction-title">INFORMACIÓN DE LA DETRACCIÓN</div>
        <div class="detraction-content">
            <div class="detraction-row">
                <div class="detraction-label">Leyenda:</div>
                <div class="detraction-value">Operación sujeta al Sistema de Pago de Obligaciones Tributarias con el Gobierno Central – Servicio de Transporte de Carga</div>
            </div>
            <div class="detraction-row">
                <div class="detraction-label">Bien o Servicio:</div>
                <div class="detraction-value">027 Servicio de transporte de carga</div>
            </div>
            <div class="detraction-row">
                <div class="detraction-label">Medio Pago:</div>
                <div class="detraction-value">001 Depósito en cuenta</div>
            </div>
            <div class="detraction-row">
                <div class="detraction-label">Nro. Cta. Banco de la Nación:</div>
                <div class="detraction-value">{{ $detraction['account'] }}</div>
            </div>
            <div class="detraction-row">
                <div class="detraction-label">Porcentaje de detracción:</div>
                <div class="detraction-value">{{ $detraction['percentage'] }}%</div>
            </div>
            <div class="detraction-row">
                <div class="detraction-label">Monto detracción:</div>
                <div class="detraction-value">{{ $currency_symbol }} {{ number_format($detraction['amount'], 2) }}</div>
            </div>
            <div class="detraction-row">
                <div class="detraction-label">Monto neto pendiente de pago:</div>
                <div class="detraction-value">{{ $currency_symbol }} {{ number_format($detraction['net_payable'], 2) }}</div>
            </div>
        </div>
    </div>
    @endif

    <!-- Installments -->
    @if($invoice->installments && $invoice->installments->count() > 0)
    <div class="section-title">INFORMACIÓN DE CRÉDITO</div>
    <table>
        <thead>
            <tr>
                <th>CUOTA</th>
                <th>FECHA VENCIMIENTO</th>
                <th>MONTO</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->installments as $installment)
            <tr>
                <td class="text-center">{{ $installment->installment_number }}</td>
                <td class="text-center">{{ \Carbon\Carbon::parse($installment->due_date)->format('d/m/Y') }}</td>
                <td class="text-center">{{ $currency_symbol }} {{ number_format($installment->amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Despatch Guides -->
    @if($invoice->despatches && $invoice->despatches->count() > 0)
    <div class="section-title">GUÍAS DE REMISIÓN RELACIONADAS</div>
    <table>
        <thead>
            <tr>
                <th>TIPO</th>
                <th>SERIE-NÚMERO</th>
                <th>FECHA EMISIÓN</th>
                <th>CLIENTE</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->despatches as $despatch)
            <tr>
                <td class="text-center">
                    @if($despatch->document_type === 7)
                        GRE REMITENTE
                    @elseif($despatch->document_type === 8)
                        GRE TRANSPORTISTA
                    @else
                        GRE TIPO {{ $despatch->document_type }}
                    @endif
                </td>
                <td class="text-center">{{ $despatch->series }}-{{ str_pad($despatch->number, 8, '0', STR_PAD_LEFT) }}</td>
                <td class="text-center">{{ $despatch->emission_date->format('d/m/Y') }}</td>
                <td class="text-center">{{ $despatch->client->name ?? 'N/A' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Footer -->
    <div class="footer">
        {{-- <strong>COMPROBANTE ELECTRÓNICO - {{ $company['name'] }}</strong><br> --}}
        {{-- Fecha: {{ $despatch->emission_date->format('d/m/Y') }}  --}}
        {{-- |
        Estado SUNAT:
        @if($invoice->sunat_accepted === true)
            ACEPTADO
        @elseif($invoice->sunat_accepted === false)
            RECHAZADO
        @else
            PENDIENTE
        @endif

        @if($invoice->hash_code)
        <br><br>Código Hash: {{ $invoice->hash_code }}
        @endif --}}

        {{-- <br><small>Resolución SUNAT N° 097-2012/SUNAT</small> --}}
    </div>
</body>
</html>
