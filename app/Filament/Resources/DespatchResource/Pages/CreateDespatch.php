<?php

namespace App\Filament\Resources\DespatchResource\Pages;

use App\Filament\Resources\DespatchResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateDespatch extends CreateRecord
{
    protected static string $resource = DespatchResource::class;
    protected static ?string $title = 'Crear Nueva Guía de Remisión';

    protected function getRedirectUrl(): string
    {        
        return DespatchResource::getUrl('index');     
    }
}
