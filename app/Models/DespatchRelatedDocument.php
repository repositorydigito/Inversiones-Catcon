<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DespatchRelatedDocument extends Model
{
    protected $fillable = [
        'despatch_id',
        'document_type',
        'series',
        'number',
    ];

    // Relación inversa con la guía de remisión
    public function despatch()
    {
        return $this->belongsTo(Despatch::class);
    }
}
