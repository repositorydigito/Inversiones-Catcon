<?php

namespace App\Observers;

use App\Models\Despatch;
use App\Models\OperationalExpense;
use App\Models\ExpenseType;

class DespatchObserver
{
    public function saved(Despatch $despatch): void
    {
        // Solo sincronizar si tiene conductor asignado
        if (!$despatch->driver_id) {
            return;
        }

        $this->syncOperationalExpenses($despatch);
    }

    private function syncOperationalExpenses(Despatch $despatch): void
    {
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
            OperationalExpense::where('driver_id', $despatch->driver_id)
                ->where('despatch_id', $despatch->id)
                ->delete();
        }
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
}
