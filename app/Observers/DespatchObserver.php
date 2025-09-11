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
        
        // Calcular el total de gastos operativos
        $totalExpenses = $despatch->tolls + $despatch->loading_expenses +
                        $despatch->travel_allowances + $despatch->variable_salary +
                        $despatch->operations_manager + $despatch->security;

        // Solo crear un registro si hay gastos operativos
        if ($totalExpenses > 0) {
            // Buscar o crear el tipo de gasto para "Gastos de Guía"
            $expenseType = ExpenseType::firstOrCreate(
                ['name' => 'Gastos de Guía'],
                ['category' => 'fixed', 'is_active' => true]
            );

            // Crear o actualizar UN SOLO registro por guía
            OperationalExpense::updateOrCreate([
                'driver_id' => $despatch->driver_id,
                'despatch_id' => $despatch->id,
                'expense_type_id' => $expenseType->id,
            ], [
                'vehicle_id' => $despatch->vehicle_id,
                'client_id' => $despatch->client_id,
                'expense_date' => $despatch->emission_date,
                'amount' => $totalExpenses,
                'description' => $this->generateExpenseDescription($despatch),
                'supplier' => $despatch->supplier,
                'status' => 'pending',
            ]);
        } else {
            // Si no hay gastos, eliminar el registro si existe
            $this->removeOperationalExpenses($despatch);
        }
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
        // Configuración: monto de viáticos por día
        $dailyTravelAllowance = 30.00;

        // Obtener la fecha de emisión
        $emissionDate = Carbon::parse($despatch->emission_date)->format('Y-m-d');

        // Contar cuántas guías tiene este conductor en la misma fecha (excluyendo la actual si es una actualización)
        $existingGuides = Despatch::where('driver_id', $despatch->driver_id)
            ->whereDate('emission_date', $emissionDate)
            ->where('accepted_by_sunat', true)
            ->when($despatch->exists, function ($query) use ($despatch) {
                // Si es una actualización, excluir la guía actual del conteo
                return $query->where('id', '!=', $despatch->id);
            })
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Si es la primera guía del día (no hay guías existentes), asignar viáticos
        if ($despatch->accepted_by_sunat && $existingGuides->isEmpty()) {
            $despatch->travel_allowances = $dailyTravelAllowance;
        } else {
            // Si ya hay guías en el día, no asignar viáticos
            $despatch->travel_allowances = 0;
        }
    }
    /**
     * Actualizar viáticos de otras guías del mismo conductor en la misma fecha
     */
    private function updateTravelAllowancesForSameDay(Despatch $currentDespatch): void
    {
        $emissionDate = Carbon::parse($currentDespatch->emission_date)->format('Y-m-d');

        // Obtener todas las guías del conductor en la misma fecha, ordenadas por hora de creación
        $guidesOfTheDay = Despatch::where('driver_id', $currentDespatch->driver_id)
            ->whereDate('emission_date', $emissionDate)
            ->where('accepted_by_sunat', true)
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Configuración: monto de viáticos por día
        $dailyTravelAllowance = 30.00;

        foreach ($guidesOfTheDay as $index => $guide) {
            // Solo la primera guía del día debe tener viáticos
            $newTravelAllowance = ($index === 0) ? $dailyTravelAllowance : 0;

            // Solo actualizar si el valor es diferente para evitar loops
            if ($guide->travel_allowances != $newTravelAllowance) {
                // Usar updateQuietly para evitar disparar el observer nuevamente
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

        // Obtener todas las guías restantes del conductor en esa fecha
        $remainingGuides = Despatch::where('driver_id', $driverId)
            ->whereDate('emission_date', $emissionDate)
            ->where('accepted_by_sunat', true)
            ->orderBy('emission_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        // Configuración: monto de viáticos por día
        $dailyTravelAllowance = 30.00;

        foreach ($remainingGuides as $index => $guide) {
            // Solo la primera guía del día debe tener viáticos
            $newTravelAllowance = ($index === 0) ? $dailyTravelAllowance : 0;

            // Actualizar si es necesario
            if ($guide->travel_allowances != $newTravelAllowance) {
                $guide->updateQuietly(['travel_allowances' => $newTravelAllowance]);
            }
        }
    }
}
