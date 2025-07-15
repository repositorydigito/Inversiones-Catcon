<?php

namespace App\Filament\Resources\OperationalExpenseResource\Pages;

use App\Filament\Resources\OperationalExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Models\ExpenseType;

class CreateOperationalExpense extends CreateRecord
{
    protected static string $resource = OperationalExpenseResource::class;

    protected function getRedirectUrl(): string
    {        
        return OperationalExpenseResource::getUrl('index');     
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Si viene expense_type_name, crear/buscar el tipo de gasto
        if (isset($data['expense_type_name']) && !empty($data['expense_type_name'])) {
            $expenseType = ExpenseType::firstOrCreate(
                ['name' => $data['expense_type_name']],
                ['category' => 'variable', 'is_active' => true]
            );
            
            // Asignar el ID y remover el campo temporal
            $data['expense_type_id'] = $expenseType->id;
            unset($data['expense_type_name']);
        }

        // Establecer estado por defecto para gastos manuales
        $data['status'] = 'pending';

        return $data;
    }
}
