<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = [
        'name',
        'document_type',
        'document_number',
        'license_number',
        'phone',
    ];

    public function vehicle()
    {
        return $this->hasOne(Vehicle::class);
    }
}
