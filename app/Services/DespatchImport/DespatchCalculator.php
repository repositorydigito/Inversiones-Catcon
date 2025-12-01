<?php

namespace App\Services\DespatchImport;

use App\Models\Despatch;

class DespatchCalculator
{
    protected FrequentLocationMatcher $matcher;

    public function __construct(FrequentLocationMatcher $matcher)
    {
        $this->matcher = $matcher;
    }
    /**
     * Recalcula viáticos después de una importación masiva
     */
    public function recalculateTravelAllowancesAfterImport(): void
    {
        // Obtener todas las guías importadas en los últimos 5 minutos
        $recentDespatches = Despatch::where('sunat_description', 'Importado desde XML de SUNAT')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereNotNull('driver_id')
            ->orderBy('driver_id')
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Agrupar por conductor y fecha
        $grouped = $recentDespatches->groupBy(function ($d) {
            return $d->driver_id . '-' . $d->emission_date;
        });

        foreach ($grouped as $group) {
            foreach ($group->values() as $index => $despatch) {
                $dailyTravelAllowance = 30.00;

                // Buscar configuración si existe
                if ($despatch->loading_point && $despatch->departure_location &&
                    $despatch->arrival_location && $despatch->unloading_point) {

                    $config = $this->matcher->findOperationalConfig(
                        $despatch->loading_point,
                        $despatch->departure_location,
                        $despatch->arrival_location,
                        $despatch->unloading_point
                    );

                    if ($config && $config->travel_allowances > 0) {
                        $dailyTravelAllowance = $config->travel_allowances;
                    }
                }

                // Solo la primera guía del día debe tener viáticos
                $newTravelAllowances = ($index === 0) ? $dailyTravelAllowance : 0;

                if ($despatch->travel_allowances != $newTravelAllowances) {
                    $despatch->updateQuietly(['travel_allowances' => $newTravelAllowances]);
                    // Forzar creación de gastos operativos
                    $despatch->save();
                }
            }
        }
    }
}
