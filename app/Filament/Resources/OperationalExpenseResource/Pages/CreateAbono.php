<?php

namespace App\Filament\Resources\OperationalExpenseResource\Pages;

use App\Filament\Resources\OperationalExpenseResource;
use App\Models\OperationalExpense;
use App\Models\ExpenseType;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateAbono extends CreateRecord
{
    protected static string $resource = OperationalExpenseResource::class;
    protected static ?string $title = 'Registrar Abono';

    public function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $this->getResource()::abonoForm($form);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Crear/buscar el tipo "Abono"
        $expenseType = ExpenseType::firstOrCreate(
            ['name' => 'Abono'],
            ['category' => 'variable', 'is_active' => true]
        );

        // 2. Asignar el ID correcto
        $data['expense_type_id'] = $expenseType->id;

        // 3. Hacer el monto negativo
        $data['amount'] = -abs($data['amount']);

        // 4. Eliminar el campo temporal
        unset($data['expense_type_name']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
