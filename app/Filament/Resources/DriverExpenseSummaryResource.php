<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverExpenseSummaryResource\Pages;
use App\Filament\Resources\DriverExpenseSummaryResource\RelationManagers;
use App\Models\DriverExpenseSummary;
use App\Models\Driver;
use App\Models\OperationalExpense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Actions\Action;
use Filament\Tables\Filters\SelectFilter;

class DriverExpenseSummaryResource extends Resource
{
    protected static ?string $model = DriverExpenseSummary::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';    
    protected static ?string $navigationLabel = 'Gastos Operativos';
    protected static ?string $modelLabel = 'Resumen de Gastos';
    protected static ?string $pluralModelLabel = 'Gastos Operativos';
    protected static ?string $navigationGroup = 'Operaciones';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.full_name')
                    ->label('Conductor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vehicle.plate_number')
                    ->label('Unidad')
                    ->searchable(),

                Tables\Columns\TextColumn::make('total_tolls')
                    ->label('Peajes')
                    ->money('PEN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_loading_expenses')
                    ->label('G. de Carga')
                    ->money('PEN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_travel_allowances')
                    ->label('Viáticos')
                    ->money('PEN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_variable_expenses')
                    ->label('Otros')
                    ->money('PEN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('grand_total')
                    ->label('Total')
                    ->money('PEN')
                    ->sortable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('total_variable_salary')
                    ->label('Sueldo Var.')
                    ->money('PEN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_trips')
                    ->label('Viajes')
                    ->sortable(),

                Tables\Columns\TextColumn::make('period_start')
                    ->label('Período')
                    ->formatStateUsing(fn ($record) => 
                        $record->period_start->format('d/m/Y') . ' - ' . 
                        $record->period_end->format('d/m/Y')
                    ),
            ])
            ->filters([
                SelectFilter::make('driver_id')
                    ->label('Conductor')
                    ->options(Driver::all()->pluck('full_name', 'id')),
            ])
            ->actions([
                Action::make('view_details')
                    ->label('Reporte')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->modalHeading('Reporte de Gastos Detallado')
                    ->modalContent(function (DriverExpenseSummary $record) {
                        $expenses = OperationalExpense::where('driver_id', $record->driver_id)
                            ->whereBetween('expense_date', [$record->period_start, $record->period_end])
                            ->with(['expenseType', 'despatch', 'client'])
                            ->get();

                        return view('filament.modals.expense-details', [
                            'summary' => $record,
                            'expenses' => $expenses
                        ]);
                    })
                    ->modalWidth('7xl'),

                Action::make('regenerate')
                    ->label('Actualizar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->action(function (DriverExpenseSummary $record) {
                        self::regenerateSummary($record);
                    })
                    ->requiresConfirmation()
                    ->modalDescription('Esto recalculará los totales del resumen.'),
            ])
            ->bulkActions([
                
            ])
            ->defaultSort('period_start', 'desc');
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
            'index' => Pages\ListDriverExpenseSummaries::route('/'),
            // 'create' => Pages\CreateDriverExpenseSummary::route('/create'),
            // 'edit' => Pages\EditDriverExpenseSummary::route('/{record}/edit'),
        ];
    }

    public static function regenerateSummary(DriverExpenseSummary $summary): void
    {
        // Calcular gastos desde OperationalExpense (tanto fijos como variables)
        $allExpenses = OperationalExpense::where('driver_id', $summary->driver_id)
            ->whereBetween('expense_date', [$summary->period_start, $summary->period_end])
            ->where('status', 'approved')
            ->with('expenseType')
            ->get();

        // Separar gastos fijos (de guías) y variables (manuales)
        $fixedExpenses = $allExpenses->where('expenseType.category', 'fixed');
        $variableExpenses = $allExpenses->where('expenseType.category', 'variable')
                                    ->groupBy('expenseType.name');

        // Contar viajes únicos
        $totalTrips = $allExpenses->whereNotNull('despatch_id')->pluck('despatch_id')->unique()->count();

        $summary->update([
            // Los gastos fijos ahora vienen de OperationalExpense
            'total_tolls' => 0, // Ya no se calculan por separado
            'total_loading_expenses' => 0,
            'total_travel_allowances' => 0,
            'total_variable_salary' => 0,
            'total_fuel' => $variableExpenses->get('Combustible')?->sum('amount') ?? 0,
            'total_washing' => $variableExpenses->get('Lavado')?->sum('amount') ?? 0,
            'total_maintenance' => $variableExpenses->get('Mantenimiento')?->sum('amount') ?? 0,
            'total_other_expenses' => $variableExpenses->except(['Combustible', 'Lavado', 'Mantenimiento'])->flatten()->sum('amount'),
            'total_fixed_expenses' => $fixedExpenses->sum('amount'),
            'total_trips' => $totalTrips,
        ]);

        $summary->calculateTotals();
        $summary->save();
    }

    public static function canCreate(): bool
    {
        return false; 
    }
}
