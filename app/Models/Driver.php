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
}
