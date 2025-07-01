<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 p-4">   
        
        <x-filament::section class="rounded-xl shadow-lg">
            <x-slot name="heading">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Empresa</h2>
            </x-slot>
            <ul class="space-y-3 text-gray-700 dark:text-gray-300">
                <li><a href="{{ url('/admin/company-settings') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Empresa</a></li>
                <li><a href="{{ url('/admin/business-natures') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Giro de negocio</a></li>
                <li><a href="{{ url('/admin/styles-themes') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Estilos y temas</a></li>
                <li><a href="{{ url('/admin/advanced-company-settings') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Avanzado</a></li>
            </ul>
        </x-filament::section>

        <!-- Tarjeta: SUNAT -->
        <x-filament::section class="rounded-xl shadow-lg">
            <x-slot name="heading">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">SUNAT</h2>
            </x-slot>
            <ul class="space-y-3 text-gray-700 dark:text-gray-300">
                <li><a href="{{ url('/admin/sunat-attributes') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Listado de Atributos</a></li>
                <li><a href="{{ url('/admin/sunat-detraction-types') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Listado de tipos de detracciones</a></li>
                <li><a href="{{ url('/admin/sunat-units') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Listado de unidades</a></li>
                <li><a href="{{ url('/admin/sunat-transfer-reason-types') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Tipos de motivos de transferencias</a></li>
            </ul>
        </x-filament::section>        

        <!-- Tarjeta: Plantillas PDF -->
        <x-filament::section class="rounded-xl shadow-lg">
            <x-slot name="heading">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Plantillas PDF</h2>
            </x-slot>
            <ul class="space-y-3 text-gray-700 dark:text-gray-300">
                <li><a href="{{ url('/admin/pdf-templates') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">PDF</a></li>
                <li><a href="{{ url('/admin/pdf-ticket-templates') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">PDF - Ticket</a></li>
                <li><a href="{{ url('/admin/pre-printed-templates') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Pre Impresos</a></li>
            </ul>
        </x-filament::section>

        <!-- Tarjeta: Avanzado -->
        <x-filament::section class="rounded-xl shadow-lg">
            <x-slot name="heading">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Avanzado</h2>
            </x-slot>
            <ul class="space-y-3 text-gray-700 dark:text-gray-300">
                <li><a href="{{ url('/admin/advanced') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Avanzado 1</a></li>
                <li><a href="{{ url('/admin/advanced') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Avanzado 2</a></li>
                <li><a href="{{ url('/admin/advanced') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Avanzado 3</a></li>
                <li><a href="{{ url('/admin/advanced') }}" class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-600 hover:underline transition">Avanzado 4</a></li>
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>