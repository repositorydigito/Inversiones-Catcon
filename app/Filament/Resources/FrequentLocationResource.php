<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FrequentLocationResource\Pages;
use App\Filament\Resources\FrequentLocationResource\RelationManagers;
use App\Models\FrequentLocation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FrequentLocationResource extends Resource
{
    protected static ?string $model = FrequentLocation::class;
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationLabel = 'Puntos de Ruta';
    protected static ?string $pluralModelLabel = 'Puntos de Ruta';
    protected static ?string $modelLabel = 'Puntos de Ruta';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationGroup = 'Entidades';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([                
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->boolean()
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFrequentLocations::route('/'),
            'create' => Pages\CreateFrequentLocation::route('/create'),
            'edit' => Pages\EditFrequentLocation::route('/{record}/edit'),
        ];
    }
}
