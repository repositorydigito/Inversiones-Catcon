<?php

namespace App\Filament\Resources\FrequentLocationResource\Pages;

use App\Filament\Resources\FrequentLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFrequentLocations extends ListRecords
{
    protected static string $resource = FrequentLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
