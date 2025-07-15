<?php

namespace App\Filament\Resources\OperationalExpenseResource\Pages;

use App\Filament\Resources\OperationalExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOperationalExpenses extends ListRecords
{
    protected static string $resource = OperationalExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Registrar Gasto Variable'),
        ];
    }
}
