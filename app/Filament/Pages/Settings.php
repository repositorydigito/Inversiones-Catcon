<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string $view = 'filament.pages.settings'; 
    protected static ?string $title = 'Configuración';  
    protected static ?string $navigationGroup = 'Administración';
    protected static ?int $navigationSort = 90;     
    
    public static function getSlug(): string
    {
        return 'settings';
    }
}
