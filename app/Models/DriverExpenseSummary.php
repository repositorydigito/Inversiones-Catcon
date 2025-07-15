<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverExpenseSummary extends Model
{
    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'period_start',
        'period_end',
        'total_tolls',
        'total_loading_expenses',
        'total_travel_allowances',
        'total_variable_salary',
        'total_fuel',
        'total_washing',
        'total_maintenance',
        'total_other_expenses',
        'total_fixed_expenses',
        'total_variable_expenses',
        'grand_total',
        'total_trips',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_tolls' => 'decimal:2',
        'total_loading_expenses' => 'decimal:2',
        'total_travel_allowances' => 'decimal:2',
        'total_variable_salary' => 'decimal:2',
        'total_fuel' => 'decimal:2',
        'total_washing' => 'decimal:2',
        'total_maintenance' => 'decimal:2',
        'total_other_expenses' => 'decimal:2',
        'total_fixed_expenses' => 'decimal:2',
        'total_variable_expenses' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function calculateTotals(): void
    {
        $this->total_fixed_expenses = $this->total_tolls + 
                                    $this->total_loading_expenses + 
                                    $this->total_travel_allowances + 
                                    $this->total_variable_salary;

        $this->total_variable_expenses = $this->total_fuel + 
                                       $this->total_washing + 
                                       $this->total_maintenance + 
                                       $this->total_other_expenses;

        $this->grand_total = $this->total_fixed_expenses + $this->total_variable_expenses;
    }
}