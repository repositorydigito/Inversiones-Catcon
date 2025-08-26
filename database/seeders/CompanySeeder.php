<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanySeeder extends Seeder
{
    public function run()
    {
        DB::table('companies')->insert([
            'ruc' => '20601921023',
            'name' => 'Inversiones Catcon S.A.C.',
            'mtc_registration_number' => '1571820CNG',
            'commercial_name' => null,
            'logo_path' => null,
            'document_type' => '6',
            'address' => 'Cal. German Schreiber 276 Urb. Santa Ana',
            'email' => null,
            'soap_type' => 'demo',
            'soap_delivery_method' => 'ose',
            'cpe_client_id' => null,
            'cpe_client_secret' => null,
            'electronic_guides_soap_username' => null,
            'electronic_guides_soap_password' => null,
            'electronic_guides_client_id' => null,
            'electronic_guides_client_secret' => null,
            'certificate_path' => null,
            'certificate_pass' => null,
        ]);
    }
}
