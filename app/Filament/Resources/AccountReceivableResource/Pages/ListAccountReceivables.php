<?php

namespace App\Filament\Resources\AccountReceivableResource\Pages;

use App\Filament\Resources\AccountReceivableResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\AccountReceivable;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccountReceivables extends ListRecords
{
    protected static string $resource = AccountReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas')
                ->badge(AccountReceivable::count()),
            
            'pending' => Tab::make('Pendientes')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pendiente'))
                ->badge(AccountReceivable::where('status', 'pendiente')->count())
                ->badgeColor('warning'),
            
            'paid' => Tab::make('Pagadas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pagado'))
                ->badge(AccountReceivable::where('status', 'pagado')->count())
                ->badgeColor('success'),
        ];
    }
}
