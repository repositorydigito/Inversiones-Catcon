<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalExpenseConfig extends Model
{
    protected $fillable = [
        'departure_point',
        'departure_location', 
        'arrival_location',
        'destination_point',
        'tolls',
        'loading_expenses',
        'variable_salary',
        'operations_manager',
        'security',
        'rate',
    ];

    protected $casts = [
        'tolls' => 'decimal:2',
        'loading_expenses' => 'decimal:2',
        'variable_salary' => 'decimal:2',
        'operations_manager' => 'decimal:2',
        'security' => 'decimal:2',
        'rate' => 'decimal:2',
    ];

    /**
     * Buscar configuración por puntos de partida y destino
     */
    public static function findByRoute(string $departurePoint, string $destinationPoint): ?self
    {
        return static::where('departure_point', $departurePoint)
                    ->where('destination_point', $destinationPoint)
                    ->first();
    }

    /**
     * Obtener el total de gastos operativos
     */
    public function getTotalExpensesAttribute(): float
    {
        return $this->tolls + $this->loading_expenses + $this->variable_salary + 
               $this->operations_manager + $this->security;
    }
}
