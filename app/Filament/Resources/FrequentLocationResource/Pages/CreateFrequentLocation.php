<?php

namespace App\Filament\Resources\FrequentLocationResource\Pages;

use App\Filament\Resources\FrequentLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFrequentLocation extends CreateRecord
{
    protected static string $resource = FrequentLocationResource::class;

    protected function getRedirectUrl(): string
    {        
        return FrequentLocationResource::getUrl('index');     
    }
}
