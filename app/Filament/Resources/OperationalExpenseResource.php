<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperationalExpenseResource\Pages;
use App\Filament\Resources\OperationalExpenseResource\RelationManagers;
use App\Models\OperationalExpense;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\ExpenseType;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\ImageColumn;

class OperationalExpenseResource extends Resource
{
    protected static ?string $model = OperationalExpense::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Gastos Variables';
    protected static ?string $pluralModelLabel = 'Gastos Operativos';
    protected static ?string $modelLabel = 'Gastos';
    
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Gasto Variable')
                    ->schema([
                        Forms\Components\Select::make('driver_id')
                            ->label('Conductor')
                            ->options(Driver::all()->pluck('full_name', 'id'))
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $driver = Driver::find($state);
                                if ($driver && $driver->vehicle) {
                                    $set('vehicle_id', $driver->vehicle->id);
                                }
                            }),

                        Forms\Components\Select::make('vehicle_id')
                            ->label('Vehículo')
                            ->options(Vehicle::all()->pluck('plate_number', 'id'))
                            ->nullable(),

                        Forms\Components\Select::make('client_id')
                            ->label('Cliente')
                            ->options(Client::all()->pluck('name', 'id'))
                            ->nullable(),

                        Forms\Components\DatePicker::make('expense_date')
                            ->label('Fecha del Gasto')
                            ->required()
                            ->default(now()),
                    ])->columns(2),

                Forms\Components\Section::make('Detalle del Gasto')
                    ->schema([
                        Forms\Components\TextInput::make('expense_type_name')
                            ->label('Tipo de Gasto')
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Monto')
                            ->numeric()
                            ->prefix('S/.')
                            ->required(),

                        Forms\Components\TextInput::make('supplier')
                            ->label('Proveedor'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->rows(2),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
{
    return $table
        ->columns([
            // 1. Conductor
            Tables\Columns\TextColumn::make('driver.full_name')
                ->label('Conductor')
                ->searchable()
                ->sortable()
                ->wrap(),

            // 2. Fecha
            Tables\Columns\TextColumn::make('expense_date')
                ->label('Fecha')
                ->date('d/m/Y')
                ->sortable(),

            // 3. Gasto (Regular/Variable)
            Tables\Columns\TextColumn::make('expenseType.name')
                ->label('Gasto')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Gastos de Guía' => 'success',
                    default => 'info',
                })
                ->formatStateUsing(function (OperationalExpense $record): string {
                    $category = $record->expenseType->category === 'fixed' ? 'Regular' : 'Variable';
                    return "{$category}";
                }),

            // 4. Guía/Doc
            Tables\Columns\TextColumn::make('despatch_reference')
                ->label('Guía/Doc')
                ->getStateUsing(function (OperationalExpense $record): string {
                    if ($record->despatch) {
                        return $record->despatch->series . '-' . $record->despatch->number;
                    }
                    return $record->document_number ?? 'Manual';
                })
                ->searchable(['document_number'])
                ->sortable(false),

            // 5. Punto de Partida
            Tables\Columns\TextColumn::make('departure_address')
                ->label('Punto de Partida')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    return $record->despatch?->departure_address;
                })
                ->limit(30)
                ->tooltip(function (OperationalExpense $record): ?string {
                    return $record->despatch?->departure_address;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 6. Punto de Llegada
            Tables\Columns\TextColumn::make('arrival_address')
                ->label('Punto de Llegada')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    return $record->despatch?->arrival_address;
                })
                ->limit(30)
                ->tooltip(function (OperationalExpense $record): ?string {
                    return $record->despatch?->arrival_address;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 7. Punto de Carga
            Tables\Columns\TextColumn::make('loading_point')
                ->label('Punto de Carga')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    return $record->despatch?->loading_point;
                })
                ->limit(25)
                ->tooltip(function (OperationalExpense $record): ?string {
                    return $record->despatch?->loading_point;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 8. Punto de Descarga
            Tables\Columns\TextColumn::make('unloading_point')
                ->label('Punto de Descarga')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    return $record->despatch?->unloading_point;
                })
                ->limit(25)
                ->tooltip(function (OperationalExpense $record): ?string {
                    return $record->despatch?->unloading_point;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 9. Peso Bruto
            Tables\Columns\TextColumn::make('gross_weight')
                ->label('Peso Bruto')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch) {
                        $weight = $record->despatch->total_gross_weight;
                        $unit = $record->despatch->total_gross_weight_unit_of_measure;
                        return $weight ? number_format($weight, 2) . ' ' . $unit : null;
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 10. Peso Neto
            Tables\Columns\TextColumn::make('net_weight')
                ->label('Peso Neto')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch && $record->despatch->net_weight) {
                        $weight = $record->despatch->net_weight;
                        $unit = $record->despatch->total_gross_weight_unit_of_measure;
                        return number_format($weight, 2) . ' ' . $unit;
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 11. Producto
            Tables\Columns\TextColumn::make('product')
                ->label('Producto')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    return $record->despatch?->product;
                })
                ->limit(20)
                ->tooltip(function (OperationalExpense $record): ?string {
                    return $record->despatch?->product;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 12. Peajes
            Tables\Columns\TextColumn::make('tolls')
                ->label('Peajes')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch && $record->despatch->tolls > 0) {
                        return 'S/. ' . number_format($record->despatch->tolls, 2);
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 13. Gastos de Carga
            Tables\Columns\TextColumn::make('loading_expenses')
                ->label('G. de Carga')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch && $record->despatch->loading_expenses > 0) {
                        return 'S/. ' . number_format($record->despatch->loading_expenses, 2);
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 14. Viáticos
            Tables\Columns\TextColumn::make('travel_allowances')
                ->label('Viáticos')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch && $record->despatch->travel_allowances > 0) {
                        return 'S/. ' . number_format($record->despatch->travel_allowances, 2);
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 15. Sueldo Variable
            Tables\Columns\TextColumn::make('variable_salary')
                ->label('Sueldo Variable')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch && $record->despatch->variable_salary > 0) {
                        return 'S/. ' . number_format($record->despatch->variable_salary, 2);
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 16. Jefe de Operaciones
            Tables\Columns\TextColumn::make('operations_manager')
                ->label('Jefe de Operaciones')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch && $record->despatch->operations_manager > 0) {
                        return 'S/. ' . number_format($record->despatch->operations_manager, 2);
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // 17. Seguridad
            Tables\Columns\TextColumn::make('security')
                ->label('Seguridad')
                ->getStateUsing(function (OperationalExpense $record): ?string {
                    if ($record->despatch && $record->despatch->security > 0) {
                        return 'S/. ' . number_format($record->despatch->security, 2);
                    }
                    return null;
                })
                ->toggleable(isToggledHiddenByDefault: true),

            // Columnas adicionales que ya tenías
            Tables\Columns\TextColumn::make('vehicle.plate_number')
                ->label('Unidad')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),

            Tables\Columns\TextColumn::make('supplier')
                ->label('Proveedor')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true)
                ->limit(20),

            Tables\Columns\TextColumn::make('amount')
                ->label('Monto Total')
                ->money('PEN')
                ->sortable()
                ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                ->toggleable(isToggledHiddenByDefault: true),

            Tables\Columns\TextColumn::make('description')
                ->label('Descripción')
                ->limit(30)
                ->toggleable(isToggledHiddenByDefault: true)
                ->tooltip(function (OperationalExpense $record): ?string {
                    return $record->description;
                }),

            Tables\Columns\TextColumn::make('status')
                ->label('Estado')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'pending' => 'warning',
                    'paid' => 'success', 
                })
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'pending' => 'Pendiente',
                    'paid' => 'Pagado',
                })
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->filters([
            SelectFilter::make('driver_id')
                ->label('Conductor')
                ->options(Driver::all()->pluck('full_name', 'id')),

            SelectFilter::make('expense_type_id')
                ->label('Tipo de Gasto')
                ->options(ExpenseType::all()->pluck('name', 'id')),

            Filter::make('expense_date')
                ->form([
                    Forms\Components\DatePicker::make('from')
                        ->label('Desde'),
                    Forms\Components\DatePicker::make('until')
                        ->label('Hasta'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when(
                            $data['from'],
                            fn (Builder $query, $date): Builder => $query->whereDate('expense_date', '>=', $date),
                        )
                        ->when(
                            $data['until'],
                            fn (Builder $query, $date): Builder => $query->whereDate('expense_date', '<=', $date),
                        );
                }),            
        ])
        ->actions([
            Tables\Actions\EditAction::make(),            
        ])
        ->bulkActions([
            
        ])
        ->defaultSort('expense_date', 'desc')
        ->striped()
        ->paginated([10, 25, 50, 100]);
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
            'index' => Pages\ListOperationalExpenses::route('/'),
            'create' => Pages\CreateOperationalExpense::route('/create'),
            'edit' => Pages\EditOperationalExpense::route('/{record}/edit'),
        ];
    }
}
