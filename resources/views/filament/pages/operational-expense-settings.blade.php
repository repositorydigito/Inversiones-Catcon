<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
            <div class="flex items-start space-x-3">
                <div>                    
                    <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
                        Define los gastos operativos (peajes, gastos de carga, sueldo variable, etc.) según los puntos de partida (P1) y destino (P4). 
                        Estos valores se aplicarán automáticamente al crear guías de remisión.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 rounded-xl">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
