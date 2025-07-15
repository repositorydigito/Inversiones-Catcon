<div class="space-y-6">
    <!-- Encabezado del Reporte -->
    <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Conductor</h3>
                <p class="text-lg font-semibold">{{ $summary->driver->full_name }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Unidad</h3>
                <p class="text-lg font-semibold">{{ $summary->vehicle?->plate_number ?? 'N/A' }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Período</h3>
                <p class="text-lg font-semibold">
                    {{ $summary->period_start->format('d/m/Y') }} - {{ $summary->period_end->format('d/m/Y') }}
                </p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Viajes</h3>
                <p class="text-lg font-semibold">{{ $summary->total_trips }}</p>
            </div>
        </div>
    </div>

    <!-- Resumen de Gastos -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg">
            <h4 class="text-sm font-medium text-blue-600 dark:text-blue-400">Peajes</h4>
            <p class="text-xl font-bold text-blue-900 dark:text-blue-100">
                S/. {{ number_format($summary->total_tolls, 2) }}
            </p>
        </div>
        <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg">
            <h4 class="text-sm font-medium text-green-600 dark:text-green-400">G. de Carga</h4>
            <p class="text-xl font-bold text-green-900 dark:text-green-100">
                S/. {{ number_format($summary->total_loading_expenses, 2) }}
            </p>
        </div>
        <div class="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded-lg">
            <h4 class="text-sm font-medium text-yellow-600 dark:text-yellow-400">Viáticos</h4>
            <p class="text-xl font-bold text-yellow-900 dark:text-yellow-100">
                S/. {{ number_format($summary->total_travel_allowances, 2) }}
            </p>
        </div>
        <div class="bg-purple-50 dark:bg-purple-900/20 p-4 rounded-lg">
            <h4 class="text-sm font-medium text-purple-600 dark:text-purple-400">Sueldo Variable</h4>
            <p class="text-xl font-bold text-purple-900 dark:text-purple-100">
                S/. {{ number_format($summary->total_variable_salary, 2) }}
            </p>
        </div>
    </div>

    <!-- Detalle de Gastos -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-medium">Detalle de Gastos</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left">Fecha</th>
                        <th class="px-4 py-2 text-left">Gasto</th>
                        <th class="px-4 py-2 text-left">Inicial</th>
                        <th class="px-4 py-2 text-left">Ingreso</th>
                        <th class="px-4 py-2 text-left">Guía/Doc</th>
                        <th class="px-4 py-2 text-left">Prod.</th>
                        <th class="px-4 py-2 text-left">Peso</th>
                        <th class="px-4 py-2 text-left">P.Partida</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($expenses as $expense)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-4 py-2">{{ $expense->expense_date->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $expense->expenseType->category === 'variable' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800' }}">
                                {{ $expense->expenseType->name }}
                            </span>
                        </td>
                        <td class="px-4 py-2">
                            @if($expense->amount < 0)
                                <span class="text-red-600">-{{ number_format(abs($expense->amount), 2) }}</span>
                            @else
                                <span class="text-green-600">{{ number_format($expense->amount, 2) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if($expense->despatch)
                                {{ number_format($expense->despatch->invoices->sum('total') ?? 0, 2) }}
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if($expense->despatch)
                                {{ $expense->despatch->series }}-{{ $expense->despatch->number }}
                            @else
                                {{ $expense->document_number }}
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            {{ $expense->despatch?->product ?? $expense->description }}
                        </td>
                        <td class="px-4 py-2">
                            {{ $expense->despatch ? number_format($expense->despatch->total_gross_weight, 2) : '-' }}
                        </td>
                        <td class="px-4 py-2">
                            {{ $expense->despatch?->loading_point ?? $expense->location }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Totales Finales -->
    <div class="bg-gray-900 text-white p-4 rounded-lg">
        <div class="grid grid-cols-3 gap-4 text-center">
            <div>
                <h4 class="text-sm font-medium text-gray-300">Gastos Fijos</h4>
                <p class="text-2xl font-bold">S/. {{ number_format($summary->total_fixed_expenses, 2) }}</p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-300">Gastos Variables</h4>
                <p class="text-2xl font-bold">S/. {{ number_format($summary->total_variable_expenses, 2) }}</p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-300">Total General</h4>
                <p class="text-3xl font-bold text-yellow-400">S/. {{ number_format($summary->grand_total, 2) }}</p>
            </div>
        </div>
    </div>
</div>