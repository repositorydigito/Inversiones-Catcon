<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'comercial_name',
        'document_type',
        'document_number',
        'phone',
        'address',
        'email',
        'days_to_pay',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
