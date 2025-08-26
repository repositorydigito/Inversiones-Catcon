<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'ruc',
        'name',
        'commercial_name',
        'logo_path',
        'soap_type',
        'soap_delivery_method',
        'cpe_client_id',
        'cpe_client_secret',
        'electronic_guides_soap_username',
        'electronic_guides_soap_password',
        'electronic_guides_client_id',
        'electronic_guides_client_secret',
        'certificate_path',
        'certificate_pass',
        'mtc_registration_number',
    ];

    /**
     * Los atributos que deben ser ocultados para la serialización.
     * Considera ocultar secretos si se exponen en APIs.
     * @var array<int, string>
     */
    protected $hidden = [
        'cpe_client_secret',
        'electronic_guides_soap_password',
        'electronic_guides_client_secret',
    ];
}
