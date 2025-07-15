<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'document_type',
        'document_number',
        'license_number',
        'phone',
    ];

    public function vehicle()
    {
        return $this->hasOne(Vehicle::class);
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

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
    public function getExpensesByPeriod($startDate, $endDate)
    {
        return $this->operationalExpenses()
                    ->whereBetween('expense_date', [$startDate, $endDate])
                    ->with(['expenseType', 'vehicle', 'despatch'])
                    ->get();
    }
    public function getTotalExpensesByPeriod($startDate, $endDate): float
    {
        return $this->operationalExpenses()
                    ->whereBetween('expense_date', [$startDate, $endDate])
                    ->where('status', 'approved')
                    ->sum('amount');
    }

}
