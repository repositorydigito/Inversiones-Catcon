<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountReceivableResource\Pages;
use App\Filament\Resources\AccountReceivableResource\RelationManagers;
use App\Models\AccountReceivable;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Carbon\Carbon;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker as FilterDatePicker;
use Filament\Notifications\Notification;

class AccountReceivableResource extends Resource
{
    protected static ?string $model = AccountReceivable::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Cuentas por cobrar';
    protected static ?string $pluralModelLabel = 'Cuentas por cobrar';
    protected static ?string $modelLabel = 'Cuentas por Cobrar';

    /* public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información de la Cuenta por Cobrar')
                    ->columns(2)
                    ->schema([
                        TextInput::make('invoice_number')
                            ->label('Factura')
                            ->disabled()
                            ->default(fn ($record) => $record ? $record->invoice_number : '')
                            ->dehydrated(false),

                        TextInput::make('client.name')
                            ->label('Cliente')
                            ->disabled()
                            ->default(fn ($record) => $record ? $record->client->name : '')
                            ->dehydrated(false),

                        TextInput::make('total_amount')
                            ->label('Monto Total')
                            ->numeric()
                            ->prefix('S/')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('emission_date')
                            ->label('Fecha de Emisión')
                            ->disabled()
                            ->default(fn ($record) => $record ? $record->emission_date->format('d/m/Y') : '')
                            ->dehydrated(false),

                        TextInput::make('days_elapsed')
                            ->label('Días Transcurridos')
                            ->disabled()
                            ->default(fn ($record) => $record ? $record->days_elapsed : '')
                            ->dehydrated(false),

                        Select::make('status')
                            ->label('Estado')
                            ->options([
                                'pendiente' => 'Pendiente',
                                'pagado' => 'Pagado',
                            ])
                            ->required()
                            ->live(),

                        DatePicker::make('pay_date')
                            ->label('Fecha de Pago')
                            ->nullable()
                            ->visible(fn (Forms\Get $get): bool => $get('status') === 'pagado')
                            ->required(fn (Forms\Get $get): bool => $get('status') === 'pagado'),

                        Select::make('pay_mode')
                            ->label('Modo de Pago')
                            ->options([
                                'transferencia' => 'Transferencia Bancaria',
                                'factoring' => 'Factoring',
                            ])
                            ->nullable()
                            ->visible(fn (Forms\Get $get): bool => $get('status') === 'pagado')
                            ->required(fn (Forms\Get $get): bool => $get('status') === 'pagado'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->maxLength(500)
                            ->nullable()
                            ->columnSpanFull(),
                    ]),
            ]);
    } */

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('emission_date')
                    ->label('F. Emisión')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('days_elapsed')
                    ->label('Días Transcurridos')
                    ->getStateUsing(fn (AccountReceivable $record): int => $record->days_elapsed)
                    ->badge()
                    ->color(fn (AccountReceivable $record): string => $record->badge_color)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('emission_date', $direction === 'asc' ? 'desc' : 'asc');
                    }),

                TextColumn::make('invoice_number')
                    ->label('Factura')
                    ->getStateUsing(fn (AccountReceivable $record): string => $record->invoice_number)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('invoice', function (Builder $query) use ($search) {
                            $query->where('series', 'like', "%{$search}%")
                                  ->orWhere('number', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('total_amount')
                    ->label('Monto')
                    ->money('PEN')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match($state) {
                        'pendiente' => 'warning',
                        'pagado' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'pendiente' => 'Pendiente',
                        'pagado' => 'Pagado',
                        default => $state,
                    }),

                TextColumn::make('pay_mode')
                    ->label('Modo de Pago')
                    ->formatStateUsing(fn (?string $state): string => match($state) {
                        'transferencia' => 'Transferencia',
                        'factoring' => 'Factoring',
                        null => '-',
                        default => $state,
                    })
                    ->placeholder('-'),

                TextColumn::make('pay_date')
                    ->label('F. Pago')
                    ->date('d/m/Y')
                    ->placeholder('-'),
            ])
            ->defaultSort('emission_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado CUENTA POR PAGAR')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'pagado' => 'Pagado',
                    ]),

                SelectFilter::make('client_id')
                    ->label('Cliente')
                    ->options(Client::all()->pluck('name', 'id'))
                    ->searchable(),

                Filter::make('emission_date')
                    ->form([
                        FilterDatePicker::make('emission_from')
                            ->label('Desde'),
                        FilterDatePicker::make('emission_until')
                            ->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['emission_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('emission_date', '>=', $date),
                            )
                            ->when(
                                $data['emission_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('emission_date', '<=', $date),
                            );
                    }),

                SelectFilter::make('sunat_accepted')
                    ->label('Estado FACTURA')
                    ->options([
                        '1' => 'Aceptado',
                        '0' => 'Rechazado',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (isset($data['value']) && $data['value'] !== '') {
                            return $query->whereHas('invoice', function (Builder $query) use ($data) {
                                $query->where('sunat_accepted', (bool) $data['value']);
                            });
                        }
                        return $query;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_as_paid')
                    ->label('Marcar como Pagado')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->form([
                        DatePicker::make('pay_date')
                            ->label('Fecha de Pago')
                            ->required()
                            ->default(Carbon::now()),
                        Select::make('pay_mode')
                            ->label('Modo de Pago')
                            ->options([
                                'transferencia' => 'Transferencia Bancaria',
                                'factoring' => 'Factoring',
                            ])
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notas del Pago')
                            ->maxLength(500),
                    ])
                    ->action(function (AccountReceivable $record, array $data): void {
                        $record->update([
                            'status' => 'pagado',
                            'pay_date' => $data['pay_date'],
                            'pay_mode' => $data['pay_mode'],
                            'notes' => $data['notes'] ?? $record->notes,
                        ]);

                        Notification::make()
                            ->title('Cuenta marcada como pagada')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (AccountReceivable $record): bool => $record->status !== 'pagado'),

                // Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListAccountReceivables::route('/'),
            // 'create' => Pages\CreateAccountReceivable::route('/create'),
            // 'edit' => Pages\EditAccountReceivable::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pendiente')->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        $pendingCount = static::getModel()::where('status', 'pendiente')->count();

        if ($pendingCount > 0) {
            return 'warning';
        }

        return null;
    }
}
