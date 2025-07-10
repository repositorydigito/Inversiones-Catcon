{{-- resources/views/filament/modals/invoice-guides.blade.php --}}
<div class="p-6">
    @if($invoice->despatches->isEmpty())
        <div class="text-center py-8">
            <div class="text-gray-400 text-sm">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <p class="mt-2">Esta factura no tiene guías de remisión asociadas.</p>
            </div>
        </div>
    @else
        <div class="overflow-hidden">
            <div class="mb-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ $invoice->despatches->count() }} 
                    {{ $invoice->despatches->count() === 1 ? 'Guía Relacionada' : 'Guías Relacionadas' }}
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Guías de remisión incluidas en esta factura
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Tipo de GRE
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Número
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Remitente
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Destinatario
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Fecha Emisión
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Items
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Estado SUNAT
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($invoice->despatches as $despatch)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        {{ $despatch->document_type === '7' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' }}">
                                        @if($despatch->document_type === 7)
                                            GRE Remitente
                                        @elseif($despatch->document_type === 8)
                                            GRE Transportista
                                        @else
                                            GRE Tipo {{ $despatch->document_type }}
                                        @endif
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $despatch->series }}-{{ str_pad($despatch->number, 8, '0', STR_PAD_LEFT) }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    @if($despatch->document_type === 8)
                                        {{-- Para GRE Transportista: Remitente = Company --}}
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ $despatch->company->name ?? 'N/A' }}
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ $despatch->company->ruc ?? 'N/A' }}
                                        </div>
                                    @elseif($despatch->document_type === 7)
                                        {{-- Para GRE Remitente: Remitente = Client --}}
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ $despatch->client->name }}
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ $despatch->client->document_number }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if($despatch->document_type === 8)
                                        {{-- Para GRE Transportista: Destinatario = Client --}}
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ $despatch->client->name }}
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ $despatch->client->document_number }}
                                        </div>
                                    @elseif($despatch->document_type === 7)
                                        {{-- Para GRE Remitente: Destinatario = Company --}}
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            {{ $despatch->company->name ?? 'N/A' }}
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            {{ $despatch->company->ruc ?? 'N/A' }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    {{ $despatch->emission_date->format('d/m/Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ $despatch->items->count() }} item(s)
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ number_format($despatch->items->sum('quantity'), 2) }} unidades
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($despatch->accepted_by_sunat === true)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            ✅ Aceptada
                                        </span>
                                    @elseif($despatch->accepted_by_sunat === false)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                            ❌ Rechazada
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                            ⏳ Pendiente
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>            
        </div>
    @endif
</div>