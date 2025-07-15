<?php

namespace App\Filament\Resources\OperationalExpenseResource\Pages;

use App\Filament\Resources\OperationalExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Models\ExpenseType;

class EditOperationalExpense extends EditRecord
{
    protected static string $resource = OperationalExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
    {        
        return OperationalExpenseResource::getUrl('index');     
    }
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Cuando editamos, cargar el nombre del tipo de gasto
        if (isset($data['expense_type_id'])) {
            $expenseType = ExpenseType::find($data['expense_type_id']);
            if ($expenseType) {
                $data['expense_type_name'] = $expenseType->name;
            }
        }

        return $data;
    }
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Procesar expense_type_name al guardar cambios
        if (isset($data['expense_type_name']) && !empty($data['expense_type_name'])) {
            $expenseType = ExpenseType::firstOrCreate(
                ['name' => $data['expense_type_name']],
                ['category' => 'variable', 'is_active' => true]
            );
            
            $data['expense_type_id'] = $expenseType->id;
            unset($data['expense_type_name']);
        }

        return $data;
    }
}
