<?php

namespace App\Observers;

use App\Models\Despatch;
use App\Models\OperationalExpense;
use App\Models\ExpenseType;
use Carbon\Carbon;

class DespatchObserver
{
    public function saving(Despatch $despatch): void
    {
        // Calcular Venta Bruta automáticamente
        if ($despatch->net_sale && $despatch->net_sale > 0) {
            $despatch->gross_sale = $despatch->net_sale * 1.18;
        } else {
            $despatch->gross_sale = 0;
        }

        // Solo aplicar automatización si tiene conductor asignado
        if ($despatch->driver_id) {
            $this->automatizeTravelAllowances($despatch);
        }
    }
    public function saved(Despatch $despatch): void
    {
        // Solo sincronizar si tiene conductor asignado
        if (!$despatch->driver_id) {
            return;
        }

        // Solo crear gastos operativos si está aceptada por SUNAT
        if ($despatch->accepted_by_sunat) {
            $this->syncOperationalExpenses($despatch);
        } else {
            // Si no está aceptada, eliminar gastos operativos si existen
            $this->removeOperationalExpenses($despatch);
        }
        // Actualizar viáticos de otras guías del mismo conductor en la misma fecha
        $this->updateTravelAllowancesForSameDay($despatch);
    }
    /**
     * Método para ser llamado externamente cuando una guía es aceptada por SUNAT
     */
    public function handleSunatAcceptance(Despatch $despatch): void
    {
        if ($despatch->driver_id && $despatch->accepted_by_sunat) {
            $this->syncOperationalExpenses($despatch);
            $this->updateTravelAllowancesForSameDay($despatch);
        }
    }
    public function deleted(Despatch $despatch): void
    {
        // Cuando se elimina una guía, recalcular viáticos para el conductor en esa fecha
        if ($despatch->driver_id) {
            $this->removeOperationalExpenses($despatch); // Cuando se elimina una guía, eliminar sus gastos operativos asociados
            $this->recalculateTravelAllowancesForDay($despatch->driver_id, $despatch->emission_date);
        }
    }
    /**
     * Método para manejar cambios en el estado de aceptación de SUNAT
     */
    public function updating(Despatch $despatch): void
    {
        // Detectar si cambió el estado de aceptación de SUNAT
        if ($despatch->isDirty('accepted_by_sunat')) {
            $wasAccepted = $despatch->getOriginal('accepted_by_sunat');
            $isNowAccepted = $despatch->accepted_by_sunat;

            // Si antes no estaba aceptada y ahora sí, crear gastos operativos
            if (!$wasAccepted && $isNowAccepted && $despatch->driver_id) {
                // Se manejará en el método saved()
            }

            // Si antes estaba aceptada y ahora no, eliminar gastos operativos
            if ($wasAccepted && !$isNowAccepted && $despatch->driver_id) {
                $this->removeOperationalExpenses($despatch);
            }
        }
    }
    private function syncOperationalExpenses(Despatch $despatch): void
    {
        // VALIDACIÓN AGREGADA: Solo crear gastos si está aceptada por SUNAT
        if (!$despatch->accepted_by_sunat) {
            $this->removeOperationalExpenses($despatch);
            return;
        }

        // Verificar si ya existe un gasto operativo para este despatch
        // Los gastos operativos son registros históricos inmutables - una vez creados, NO se modifican
        $existingExpense = OperationalExpense::where('despatch_id', $despatch->id)->first();

        if ($existingExpense) {
            // Si ya existe, no hacer nada - preservar datos históricos
            return;
        }

        // Si no existe, crear el gasto operativo con los datos actuales del despatch
        $totalExpenses = $despatch->tolls + $despatch->loading_expenses +
                        $despatch->travel_allowances + $despatch->variable_salary +
                        $despatch->operations_manager + $despatch->security;

        $expenseType = ExpenseType::firstOrCreate(
            ['name' => 'Gastos de Guía'],
            ['category' => 'fixed', 'is_active' => true]
        );

        OperationalExpense::create([
            'driver_id' => $despatch->driver_id,
            'vehicle_id' => $despatch->vehicle_id,
            'expense_type_id' => $expenseType->id,
            'despatch_id' => $despatch->id,
            'client_id' => $despatch->client_id,
            'expense_date' => $despatch->emission_date,
            'amount' => $totalExpenses,
            'description' => $this->generateExpenseDescription($despatch),
            'supplier' => $despatch->supplier,
            'status' => 'pending',
        ]);
    }
    /**
     * Eliminar gastos operativos asociados a una guía
     */
    private function removeOperationalExpenses(Despatch $despatch): void
    {
        OperationalExpense::where('driver_id', $despatch->driver_id)
            ->where('despatch_id', $despatch->id)
            ->delete();
    }
    private function generateExpenseDescription(Despatch $despatch): string
    {
        $details = [];

        if ($despatch->tolls > 0) {
            $details[] = "Peajes: S/. " . number_format($despatch->tolls, 2);
        }
        if ($despatch->loading_expenses > 0) {
            $details[] = "G.Carga: S/. " . number_format($despatch->loading_expenses, 2);
        }
        if ($despatch->travel_allowances > 0) {
            $details[] = "Viáticos: S/. " . number_format($despatch->travel_allowances, 2);
        }
        if ($despatch->variable_salary > 0) {
            $details[] = "S.Variable: S/. " . number_format($despatch->variable_salary, 2);
        }
        if ($despatch->operations_manager > 0) {
            $details[] = "J.Operaciones: S/. " . number_format($despatch->operations_manager, 2);
        }
        if ($despatch->security > 0) {
            $details[] = "Seguridad: S/. " . number_format($despatch->security, 2);
        }

        return "Guía {$despatch->series}-{$despatch->number}: " . implode(', ', $details);
    }
    private function automatizeTravelAllowances(Despatch $despatch): void
    {
        // Obtener el monto de viáticos desde la configuración de ruta
        $dailyTravelAllowance = 30.00; // Valor por defecto

        if ($despatch->loading_point && $despatch->departure_location &&
            $despatch->arrival_location && $despatch->unloading_point) {

            $config = $this->findOperationalConfig(
                $despatch->loading_point,
                $despatch->departure_location,
                $despatch->arrival_location,
                $despatch->unloading_point
            );

            if ($config && $config->travel_allowances > 0) {
                $dailyTravelAllowance = $config->travel_allowances;
            }
        }

        // Obtener la fecha de emisión
        $emissionDate = Carbon::parse($despatch->emission_date)->format('Y-m-d');

        // Contar cuántas guías tiene este conductor en la misma fecha
        $existingGuides = Despatch::where('driver_id', $despatch->driver_id)
            ->whereDate('emission_date', $emissionDate)
            ->where('accepted_by_sunat', true)
            ->when($despatch->exists, function ($query) use ($despatch) {
                return $query->where('id', '!=', $despatch->id);
            })
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Si es la primera guía del día, asignar viáticos
        if ($despatch->accepted_by_sunat && $existingGuides->isEmpty()) {
            $despatch->travel_allowances = $dailyTravelAllowance;
        } else {
            $despatch->travel_allowances = 0;
        }
    }
    /**
     * Actualizar viáticos de otras guías del mismo conductor en la misma fecha
     */
    private function updateTravelAllowancesForSameDay(Despatch $currentDespatch): void
    {
        $emissionDate = Carbon::parse($currentDespatch->emission_date)->format('Y-m-d');

        $guidesOfTheDay = Despatch::where('driver_id', $currentDespatch->driver_id)
            ->whereDate('emission_date', $emissionDate)
            ->where('accepted_by_sunat', true)
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($guidesOfTheDay as $index => $guide) {
            // Obtener el monto de viáticos desde la configuración de cada guía
            $dailyTravelAllowance = 30.00;

            if ($guide->loading_point && $guide->departure_location &&
                $guide->arrival_location && $guide->unloading_point) {

                $config = $this->findOperationalConfig(
                    $guide->loading_point,
                    $guide->departure_location,
                    $guide->arrival_location,
                    $guide->unloading_point
                );

                if ($config && $config->travel_allowances > 0) {
                    $dailyTravelAllowance = $config->travel_allowances;
                }
            }

            // Solo la primera guía del día debe tener viáticos
            $newTravelAllowance = ($index === 0) ? $dailyTravelAllowance : 0;

            if ($guide->travel_allowances != $newTravelAllowance) {
                $guide->updateQuietly(['travel_allowances' => $newTravelAllowance]);
            }
        }
    }
    /**
     * Recalcular viáticos cuando se elimina una guía
     */
    private function recalculateTravelAllowancesForDay(int $driverId, $emissionDate): void
    {
        $emissionDate = Carbon::parse($emissionDate)->format('Y-m-d');

        $remainingGuides = Despatch::where('driver_id', $driverId)
            ->whereDate('emission_date', $emissionDate)
            ->where('accepted_by_sunat', true)
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($remainingGuides as $index => $guide) {
            // Obtener el monto de viáticos desde la configuración de cada guía
            $dailyTravelAllowance = 30.00;

            if ($guide->loading_point && $guide->departure_location &&
                $guide->arrival_location && $guide->unloading_point) {

                $config = $this->findOperationalConfig(
                    $guide->loading_point,
                    $guide->departure_location,
                    $guide->arrival_location,
                    $guide->unloading_point
                );

                if ($config && $config->travel_allowances > 0) {
                    $dailyTravelAllowance = $config->travel_allowances;
                }
            }

            // Solo la primera guía del día debe tener viáticos
            $newTravelAllowance = ($index === 0) ? $dailyTravelAllowance : 0;

            if ($guide->travel_allowances != $newTravelAllowance) {
                $guide->updateQuietly(['travel_allowances' => $newTravelAllowance]);
            }
        }
    }
    /**
     * Normaliza una cadena para comparación flexible
     */
    private function normalizeForComparison(string $text): string
    {
        $text = strtolower($text);

        // Eliminar palabras comunes
        $commonWords = ['s/n', 'ref:', 'referencia:', 'km', 'km.', 'alt', 'altura'];
        foreach ($commonWords as $word) {
            $text = str_replace($word, '', $text);
        }

        // Eliminar puntos, comas, guiones y caracteres especiales
        $text = preg_replace('/[.,\-()\/]/', ' ', $text);

        // Eliminar tildes
        $text = $this->removeAccents($text);

        // Reemplazar múltiples espacios por uno solo
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
    /**
     * Elimina tildes y acentos
     */
    private function removeAccents(string $text): string
    {
        $unwanted = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n'
        ];

        return strtr($text, $unwanted);
    }
    /**
     * Busca configuración con normalización flexible y similitud
     */
    private function findOperationalConfig($loadingPoint, $departureLocation, $arrivalLocation, $unloadingPoint)
    {
        if (!$loadingPoint || !$departureLocation || !$arrivalLocation || !$unloadingPoint) {
            return null;
        }

        // Normalizar los valores de entrada
        $normalizedInput = [
            'loading_point' => $this->normalizeForComparison($loadingPoint),
            'departure_location' => $this->normalizeForComparison($departureLocation),
            'arrival_location' => $this->normalizeForComparison($arrivalLocation),
            'unloading_point' => $this->normalizeForComparison($unloadingPoint),
        ];

        // Umbral de similitud (85% = bastante flexible, 90% = más estricto)
        $threshold = 85;

        // Buscar coincidencia exacta primero
        $exactMatch = \App\Models\OperationalExpenseConfig::all()
            ->first(function ($config) use ($normalizedInput) {
                return $this->normalizeForComparison($config->departure_point) === $normalizedInput['loading_point']
                    && $this->normalizeForComparison($config->departure_location) === $normalizedInput['departure_location']
                    && $this->normalizeForComparison($config->arrival_location) === $normalizedInput['arrival_location']
                    && $this->normalizeForComparison($config->destination_point) === $normalizedInput['unloading_point'];
            });

        if ($exactMatch) {
            return $exactMatch;
        }

        // Si no hay coincidencia exacta, buscar por similitud
        $bestMatch = null;
        $bestScore = 0;

        foreach (\App\Models\OperationalExpenseConfig::all() as $config) {
            $score = $this->calculateSimilarityScore($config, $normalizedInput);

            if ($score >= $threshold && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $config;
            }
        }

        return $bestMatch;
    }

    /**
     * Calcula un score de similitud entre la configuración y los valores de entrada
     * Retorna un porcentaje de 0 a 100
     */
    private function calculateSimilarityScore($config, array $normalizedInput): float
    {
        $scores = [];

        // Comparar cada campo
        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->departure_point),
            $normalizedInput['loading_point']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->departure_location),
            $normalizedInput['departure_location']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->arrival_location),
            $normalizedInput['arrival_location']
        );

        $scores[] = $this->stringSimilarity(
            $this->normalizeForComparison($config->destination_point),
            $normalizedInput['unloading_point']
        );

        // Retornar el promedio de similitud de todos los campos
        return array_sum($scores) / count($scores);
    }

    /**
     * Calcula similitud entre dos strings (0-100)
     */
    private function stringSimilarity(string $str1, string $str2): float
    {
        // Si son exactamente iguales, 100%
        if ($str1 === $str2) {
            return 100;
        }

        // Si alguno está vacío, 0%
        if (empty($str1) || empty($str2)) {
            return 0;
        }

        // Usar similar_text que es más rápido que levenshtein
        similar_text($str1, $str2, $percent);

        return $percent;
    }
}
