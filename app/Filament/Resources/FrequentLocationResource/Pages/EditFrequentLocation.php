<?php

namespace App\Filament\Resources\FrequentLocationResource\Pages;

use App\Filament\Resources\FrequentLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFrequentLocation extends EditRecord
{
    protected static string $resource = FrequentLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
    {        
        return FrequentLocationResource::getUrl('index');     
    }
}
