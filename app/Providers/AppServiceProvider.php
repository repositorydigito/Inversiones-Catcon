<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Invoice;
use App\Observers\InvoiceObserver;
use App\Models\Despatch;
use App\Observers\DespatchObserver;

class AppServiceProvider extends ServiceProvider
{    
    public function register(): void
    {
        //
    }
    
    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);
        Despatch::observe(DespatchObserver::class);
    }
}
