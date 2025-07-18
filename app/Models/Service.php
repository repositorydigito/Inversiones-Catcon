<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relación con los items de guías
    public function despatchItems()
    {
        return $this->hasMany(DespatchItem::class);
    }  
    // Accessor para mostrar código + nombre
    public function getFullNameAttribute(): string
    {
        return "{$this->code} - {$this->name}";
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
