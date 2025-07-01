<?php

namespace App\Filament\Resources\DespatchResource\Pages;

use App\Filament\Resources\DespatchResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDespatch extends EditRecord
{
    protected static string $resource = DespatchResource::class;

    protected function getRedirectUrl(): string
    {        
        return DespatchResource::getUrl('index');     
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
