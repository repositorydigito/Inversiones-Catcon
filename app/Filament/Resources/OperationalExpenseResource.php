<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperationalExpenseResource\Pages;
use App\Filament\Resources\OperationalExpenseResource\RelationManagers;
use App\Models\OperationalExpense;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\ExpenseType;
use App\Models\Client;
use App\Exports\OperationalExpensesExport;
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
use Filament\Notifications\Notification; 
use Maatwebsite\Excel\Facades\Excel;

class OperationalExpenseResource extends Resource
{
    protected static ?string $model = OperationalExpense::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Gastos Operativos';
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
                            ->default('Variable')
                            ->readOnly()
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Monto')
                            ->numeric()
                            ->prefix('S/.')
                            ->minValue(0.01)
                            ->required()
                            ->validationMessages([
                                'numeric' => 'El monto solo puede contener números.',
                                'minValue' => 'El monto debe ser mayor que 0.',
                                'required' => 'El monto es obligatorio.',
                            ]),

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
                    ->searchable(['first_name', 'last_name'])
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('vehicle.plate_number')
                    ->label('Unidad')
                    ->searchable(),

                // 2. Fecha
                Tables\Columns\TextColumn::make('expense_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                // 3. Gasto (Regular/Variable)
                Tables\Columns\TextColumn::make('expenseType.name')
                    ->label('Gasto')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
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
                        return $record->document_number ?? '';
                    })
                    ->searchable(['document_number'])
                    ->sortable(false),

                // 5. Punto de Partida
                Tables\Columns\TextColumn::make('departure_location')
                    ->label('Punto de Partida')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        return $record->despatch?->departure_location;
                    })
                    ->limit(30)
                    ->tooltip(function (OperationalExpense $record): ?string {
                        return $record->despatch?->departure_location;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 6. Punto de Llegada
                Tables\Columns\TextColumn::make('arrival_location')
                    ->label('Punto de Llegada')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        return $record->despatch?->arrival_location;
                    })
                    ->limit(30)
                    ->tooltip(function (OperationalExpense $record): ?string {
                        return $record->despatch?->arrival_location;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 7. Punto de Carga - Punto 1
                Tables\Columns\TextColumn::make('loading_point')
                    ->label('Punto 1')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        return $record->despatch?->loading_point;
                    })
                    ->limit(25)
                    ->tooltip(function (OperationalExpense $record): ?string {
                        return $record->despatch?->loading_point;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 8. Punto de Descarga - Punto 4
                Tables\Columns\TextColumn::make('unloading_point')
                    ->label('Punto 4')
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
                        return 'S/. 0.00';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 13. Gastos de Carga
                Tables\Columns\TextColumn::make('loading_expenses')
                    ->label('G. de Carga')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        if ($record->despatch && $record->despatch->loading_expenses > 0) {
                            return 'S/. ' . number_format($record->despatch->loading_expenses, 2);
                        }
                        return 'S/. 0.00';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 14. Viáticos
                Tables\Columns\TextColumn::make('travel_allowances')
                    ->label('Viáticos')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        if ($record->despatch && $record->despatch->travel_allowances > 0) {
                            return 'S/. ' . number_format($record->despatch->travel_allowances, 2);
                        }
                        return 'S/. 0.00';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 15. Sueldo Variable
                Tables\Columns\TextColumn::make('variable_salary')
                    ->label('Sueldo Variable')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        if ($record->despatch && $record->despatch->variable_salary > 0) {
                            return 'S/. ' . number_format($record->despatch->variable_salary, 2);
                        }
                        return 'S/. 0.00';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 16. Jefe de Operaciones
                Tables\Columns\TextColumn::make('operations_manager')
                    ->label('Jefe de Operaciones')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        if ($record->despatch && $record->despatch->operations_manager > 0) {
                            return 'S/. ' . number_format($record->despatch->operations_manager, 2);
                        }
                        return 'S/. 0.00';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 17. Seguridad
                Tables\Columns\TextColumn::make('security')
                    ->label('Seguridad')
                    ->getStateUsing(function (OperationalExpense $record): ?string {
                        if ($record->despatch && $record->despatch->security > 0) {
                            return 'S/. ' . number_format($record->despatch->security, 2);
                        }
                        return 'S/. 0.00';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // Columnas adicionales que ya tenías

                Tables\Columns\TextColumn::make('supplier')
                    ->label('Proveedor')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->limit(20),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto Total')
                    ->money('PEN')
                    ->sortable()
                    ->color(fn($state) => $state < 0 ? 'danger' : 'success')
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
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'paid' => 'success',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'pending' => 'Pendiente',
                        'paid' => 'Pagado',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('driver_id')
                    ->label('Conductor')
                    ->options(Driver::all()->pluck('full_name', 'id')),

                SelectFilter::make('expenseType.category')
                    ->relationship('expenseType', 'category')
                    ->getOptionLabelFromRecordUsing(fn($record) => match ($record->category) {
                        'fixed' => 'Regular',
                        'variable' => 'Variable',
                        default => $record->category
                    })
                    ->label('Categoría'),


                /* ->query(function (Builder $query, $state) {
                    // Solo aplicamos el filtro si se ha seleccionado una categoría
                    if ($state) {
                        $query->whereHas('expenseType', function ($q) use ($state) {
                            $q->where('category', $state);
                        });
                    }
                    // Si no hay filtro seleccionado, mostramos todos los resultados
                    else {
                        $query->with('expenseType'); // Cargamos la relación por defecto
                    }
                }), */

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
                                fn(Builder $query, $date): Builder => $query->whereDate('expense_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('expense_date', '<=', $date),
                            );
                    }),
            ])
            // ← AGREGAR AQUÍ EL BOTÓN DE EXPORTACIÓN
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('Exportar a Excel')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->form([
                        Forms\Components\Section::make('Filtros para Exportación')
                            ->description('Selecciona los filtros que deseas aplicar a la exportación')
                            ->schema([
                                Forms\Components\Select::make('driver_id')
                                    ->label('Conductor')
                                    ->options(Driver::all()->pluck('full_name', 'id'))
                                    ->placeholder('Todos los conductores')
                                    ->searchable(),

                                Forms\Components\Select::make('vehicle_id')
                                    ->label('Vehículo/Unidad')
                                    ->options(Vehicle::all()->pluck('plate_number', 'id'))
                                    ->placeholder('Todos los vehículos')
                                    ->searchable(),

                                Forms\Components\DatePicker::make('date_from')
                                    ->label('Fecha desde')
                                    ->placeholder('Seleccionar fecha inicial'),

                                Forms\Components\DatePicker::make('date_to')
                                    ->label('Fecha hasta')
                                    ->placeholder('Seleccionar fecha final'),
                            ])
                            ->columns(2),
                    ])
                    ->action(function (array $data) {
                        try {
                            // Preparar filtros para la exportación
                            $filters = array_filter([
                                'driver_id' => $data['driver_id'] ?? null,
                                'vehicle_id' => $data['vehicle_id'] ?? null,
                                'date_from' => $data['date_from'] ?? null,
                                'date_to' => $data['date_to'] ?? null,
                            ]);

                            // Generar nombre del archivo
                            $fileName = 'gastos-operativos-' . now()->format('Y-m-d-H-i-s') . '.xlsx';

                            // Exportar
                            return Excel::download(new OperationalExpensesExport($filters), $fileName);

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error en la exportación')
                                ->body('No se pudo generar el archivo Excel: ' . $e->getMessage())
                                ->danger()
                                ->send();

                            return null;
                        }
                    })
                    ->modalHeading('Exportar Gastos Operativos a Excel')
                    ->modalSubmitActionLabel('Descargar Excel')
                    ->modalWidth('2xl'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
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
