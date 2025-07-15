<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalExpense extends Model
{   
    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'expense_type_id',
        'despatch_id',
        'client_id',
        'expense_date',
        'amount',
        'document_number',
        'description',
        'observations',
        'supplier',
        'receipt_image_path',
        'status',        
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
    public function expenseType()
    {
        return $this->belongsTo(ExpenseType::class);
    }
    public function despatch()
    {
        return $this->belongsTo(Despatch::class);
    }
    public function client()
    {
        return $this->belongsTo(Client::class);
    }     

    public function getExpenseTypeNameAttribute(): string
    {
        return $this->expenseType->name ?? '';
    }

}
