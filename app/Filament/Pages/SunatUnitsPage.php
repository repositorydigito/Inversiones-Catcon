<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use App\Models\MeasureUnit;
use Illuminate\Database\Eloquent\Builder;

class SunatUnitsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.sunat-units-page';
    protected static ?string $title = 'Unidades de Medida';
    protected static ?string $navigationLabel = 'Unidades de Medida';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'sunat-units';

    public function table(Table $table): Table
    {
        return $table
            ->query(MeasureUnit::query())
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código SUNAT')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
            ])
            ->filters([
                // Puedes agregar filtros si necesitas
            ])
            ->actions([
                // Sin acciones de edición/eliminación para solo lectura
            ])
            ->bulkActions([
                // Sin acciones masivas
            ])
            ->defaultSort('code', 'asc')
            ->striped()
            ->paginated([10, 25, 50, 100]);
    }
}
