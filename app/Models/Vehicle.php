<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'driver_id',
        'plate_number',
        'brand',
        'model',
        'vehicle_certificate',        
        'soat_expiration_date',
        'technical_review_expiration_date',
        'tuce_expiration_date',
    ];

    protected $casts = [
        'soat_expiration_date' => 'date',
        'technical_review_expiration_date' => 'date',
        'tuce_expiration_date' => 'date',
    ];
    
    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
    public function trafficTickets()
    {
        return $this->hasMany(TrafficTicket::class);
    }
    public function operationalExpenses()
    {
        return $this->hasMany(OperationalExpense::class);
    }
    public function expenseSummaries()
    {
        return $this->hasMany(DriverExpenseSummary::class);
    }
    
    public function getExpensesByPeriod($startDate, $endDate)
    {
        return $this->operationalExpenses()
                    ->whereBetween('expense_date', [$startDate, $endDate])
                    ->with(['expenseType', 'driver', 'despatch'])
                    ->get();
    }
}
