<?php

namespace App\Filament\Resources\DriverExpenseSummaryResource\Pages;

use App\Filament\Resources\DriverExpenseSummaryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDriverExpenseSummary extends EditRecord
{
    protected static string $resource = DriverExpenseSummaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
