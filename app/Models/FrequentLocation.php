<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrequentLocation extends Model
{
    protected $fillable = ['name', 'point', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function getActiveOptions()
    {
        return static::where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'name');
    }
}
